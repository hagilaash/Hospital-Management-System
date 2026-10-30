<?php

namespace App\Controller;

use App\Common\Input;
use App\DTO\BillDTO;
use App\DTO\PagedListDTO;
use App\DTO\PatientDTO;
use App\DTO\PatientListDTO;
use App\Service\AccessControlService;
use App\Service\AuthSessionService;
use App\Service\BillingService;
use Doctrine\DBAL\Exception as DbalException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * HTTP layer for Billing and Payments.
 *
 * Authentication is enforced for every route by AuthenticationListener. Each route
 * also declares the permission it needs (defaults: _permission).
 *
 * Every state change is a POST protected by a CSRF token. Token ids:
 *   billing_new, billing_pay_{id}, billing_cancel_{id}
 *
 * Database failures are caught, logged and shown as a generic message; no SQL
 * error text reaches the user.
 */
class BillingController extends AbstractController
{
    private const MESSAGE_GENERIC_ERROR = 'Something went wrong while accessing billing records. Please try again.';
    private const MESSAGE_CSRF_ERROR = 'Your session has expired. Please try again.';
    private const MESSAGE_NOT_FOUND = 'Bill not found';

    public function __construct(
        private BillingService $billingService,
        private AuthSessionService $authSession,
        private LoggerInterface $logger,
    ) {
    }

    // ------------------------------------------------------------------
    // Dashboard
    // ------------------------------------------------------------------

    #[Route(
        '/billing',
        name: 'billing_dashboard',
        methods: ['GET'],
        defaults: ['_permission' => AccessControlService::BILLING_VIEW]
    )]
    public function dashboard(): Response
    {
        try {
            $dashboard = $this->billingService->getDashboard();
        } catch (DbalException $exception) {
            $this->logger->error('Billing dashboard could not be loaded.', ['exception' => $exception]);
            $this->addFlash('error', self::MESSAGE_GENERIC_ERROR);

            $dashboard = [
                'summary' => [
                    'total_bills' => 0, 'paid_bills' => 0, 'pending_bills' => 0, 'partially_paid_bills' => 0,
                    'cancelled_bills' => 0, 'total_billed' => '0.00', 'total_collected' => '0.00', 'total_outstanding' => '0.00',
                ],
                'recent' => [],
            ];
        }

        return $this->render('billing/index.html.twig', [
            'summary' => $dashboard['summary'],
            'recent' => $dashboard['recent'],
            'billable' => $this->loadBillable(null, null),
        ]);
    }

    // ------------------------------------------------------------------
    // Bill list
    // ------------------------------------------------------------------

    #[Route(
        '/billing/bills',
        name: 'billing_list',
        methods: ['GET'],
        defaults: ['_permission' => AccessControlService::BILLING_VIEW]
    )]
    public function bills(Request $request): Response
    {
        $query = $request->query;

        try {
            $list = $this->billingService->getBills(
                $query->getString('status'),
                $query->getString('search'),
                $query->getString('date_from'),
                $query->getString('date_to'),
                $query->getInt('page', 1)
            );
        } catch (DbalException $exception) {
            $this->logger->error('Bill list could not be loaded.', ['exception' => $exception]);
            $this->addFlash('error', self::MESSAGE_GENERIC_ERROR);

            $list = PagedListDTO::empty(BillingService::PER_PAGE, ['status' => 'all', 'search' => null, 'date_from' => null, 'date_to' => null]);
        }

        return $this->render('billing/list.html.twig', [
            'list' => $list,
            'statuses' => BillingService::STATUS_FILTERS,
        ]);
    }

    // ------------------------------------------------------------------
    // Create a bill
    // ------------------------------------------------------------------

    #[Route(
        '/billing/new',
        name: 'billing_new',
        methods: ['GET', 'POST'],
        defaults: ['_permission' => AccessControlService::BILLING_CREATE]
    )]
    public function newBill(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            return $this->createBillFromRequest($request);
        }

        $query = $request->query;
        $consultationId = Input::positiveInt($query->getString('consultation_id'));
        $patientId = Input::positiveInt($query->getString('patient_id'));
        $consultations = [];

        // Coming from OP Consultation: the consultation decides the patient.
        if (null !== $consultationId) {
            $consultations = $this->loadBillable(null, $consultationId);
            if ([] === $consultations) {
                $this->addFlash('error', 'That consultation is not ready for billing, or it has already been billed.');
                $consultationId = null;
            } else {
                $patientId = (int) $consultations[0]['patient_id'];
            }
        }

        $patient = $this->loadActivePatient($patientId);
        if (null !== $patient && [] === $consultations) {
            $consultations = $this->loadBillable($patient->patientId, null);
        }

        $patientSearch = $query->getString('q');
        $patients = null;
        if (null === $patient && '' !== trim($patientSearch)) {
            $patients = $this->searchPatients($patientSearch, $query->getInt('qpage', 1));
        }

        $bill = new BillDTO();
        $bill->patientId = $patient?->patientId;
        $bill->consultationId = null !== $patient ? $consultationId : null;

        return $this->renderNewBill($bill, $patient, $patients, $patientSearch, $consultations);
    }

    private function createBillFromRequest(Request $request): Response
    {
        $bill = BillDTO::fromInput($request->request->all());
        $patient = $this->loadActivePatient($bill->patientId);
        $consultations = null !== $patient ? $this->loadBillable($patient->patientId, null) : [];

        if (!$this->isCsrfTokenValid('billing_new', $request->request->getString('_token'))) {
            return $this->renderNewBill($bill, $patient, null, '', $consultations, [], Response::HTTP_BAD_REQUEST, self::MESSAGE_CSRF_ERROR);
        }

        try {
            $result = $this->billingService->createBill($bill, $this->currentUsername());
        } catch (DbalException $exception) {
            $this->logger->error('Bill creation failed.', ['exception' => $exception]);

            return $this->renderNewBill(
                $bill,
                $patient,
                null,
                '',
                $consultations,
                [],
                Response::HTTP_SERVICE_UNAVAILABLE,
                self::MESSAGE_GENERIC_ERROR
            );
        }

        if ($result['success']) {
            $this->addFlash('success', sprintf('%s. Bill number: %s', $result['message'], $result['bill_number']));

            return $this->redirectToRoute('billing_show', ['id' => $result['bill_id']]);
        }

        $status = [] !== $result['errors'] ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_CONFLICT;

        return $this->renderNewBill($bill, $patient, null, '', $consultations, $result['errors'], $status, $result['message']);
    }

    // ------------------------------------------------------------------
    // Details and invoice
    // ------------------------------------------------------------------

    #[Route(
        '/billing/{id}',
        name: 'billing_show',
        requirements: ['id' => '\d{1,9}'],
        methods: ['GET'],
        defaults: ['_permission' => AccessControlService::BILLING_VIEW]
    )]
    public function show(int $id): Response
    {
        $bill = $this->loadBill($id);
        if ($bill instanceof Response) {
            return $bill;
        }

        return $this->render('billing/show.html.twig', ['bill' => $bill]);
    }

    #[Route(
        '/billing/{id}/invoice',
        name: 'billing_invoice',
        requirements: ['id' => '\d{1,9}'],
        methods: ['GET'],
        defaults: ['_permission' => AccessControlService::BILLING_VIEW]
    )]
    public function invoice(int $id): Response
    {
        $bill = $this->loadBill($id);
        if ($bill instanceof Response) {
            return $bill;
        }

        return $this->render('billing/invoice.html.twig', ['bill' => $bill]);
    }

    // ------------------------------------------------------------------
    // Record a payment
    // ------------------------------------------------------------------

    #[Route(
        '/billing/{id}/pay',
        name: 'billing_pay',
        requirements: ['id' => '\d{1,9}'],
        methods: ['GET', 'POST'],
        defaults: ['_permission' => AccessControlService::BILLING_PAYMENT]
    )]
    public function pay(int $id, Request $request): Response
    {
        $bill = $this->loadBill($id);
        if ($bill instanceof Response) {
            return $bill;
        }

        if (!$bill->canPay()) {
            $this->addFlash('error', sprintf('Payments cannot be recorded on this bill (status: %s)', $bill->status));

            return $this->redirectToRoute('billing_show', ['id' => $id]);
        }

        if (!$request->isMethod('POST')) {
            return $this->renderPayment($bill, ['amount' => '', 'method' => '', 'transaction_ref' => ''], $this->billingService->newPaymentKey());
        }

        $typed = [
            'amount' => $request->request->getString('amount'),
            'method' => $request->request->getString('method'),
            'transaction_ref' => $request->request->getString('transaction_ref'),
        ];
        $key = $request->request->getString('idempotency_key');
        $keyForRedisplay = 1 === preg_match('/^[A-Za-z0-9]{16,64}$/', $key) ? $key : $this->billingService->newPaymentKey();

        if (!$this->isCsrfTokenValid('billing_pay_'.$id, $request->request->getString('_token'))) {
            return $this->renderPayment($bill, $typed, $keyForRedisplay, [], Response::HTTP_BAD_REQUEST, self::MESSAGE_CSRF_ERROR);
        }

        try {
            $result = $this->billingService->recordPayment(
                $id,
                $typed['amount'],
                $typed['method'],
                $typed['transaction_ref'],
                $key,
                $this->currentUsername()
            );
        } catch (DbalException $exception) {
            $this->logger->error('Recording a payment failed.', ['exception' => $exception, 'bill_id' => $id]);

            return $this->renderPayment($bill, $typed, $keyForRedisplay, [], Response::HTTP_SERVICE_UNAVAILABLE, self::MESSAGE_GENERIC_ERROR);
        }

        if ($result['success']) {
            $this->addFlash('success', sprintf('%s. Payment reference: %s', $result['message'], $result['payment_reference']));

            return $this->redirectToRoute('billing_show', ['id' => $id]);
        }

        $status = [] !== $result['errors'] ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_CONFLICT;

        return $this->renderPayment($bill, $typed, $keyForRedisplay, $result['errors'], $status, $result['message']);
    }

    // ------------------------------------------------------------------
    // Cancel a bill (POST only)
    // ------------------------------------------------------------------

    #[Route(
        '/billing/{id}/cancel',
        name: 'billing_cancel',
        requirements: ['id' => '\d{1,9}'],
        methods: ['POST'],
        defaults: ['_permission' => AccessControlService::BILLING_CANCEL]
    )]
    public function cancel(int $id, Request $request): Response
    {
        $back = $this->redirectToRoute('billing_show', ['id' => $id]);

        if (!$this->isCsrfTokenValid('billing_cancel_'.$id, $request->request->getString('_token'))) {
            $this->addFlash('error', self::MESSAGE_CSRF_ERROR);

            return $back;
        }

        try {
            $result = $this->billingService->cancelBill($id, $request->request->getString('reason'), $this->currentUsername());
        } catch (DbalException $exception) {
            $this->logger->error('Bill cancellation failed.', ['exception' => $exception, 'bill_id' => $id]);
            $this->addFlash('error', self::MESSAGE_GENERIC_ERROR);

            return $back;
        }

        $this->addFlash($result['success'] ? 'success' : 'error', $result['message']);

        return $back;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function currentUsername(): string
    {
        return $this->authSession->getUsername() ?? '';
    }

    /**
     * Consultations ready to be billed. A failure here (for example the OP
     * Consultation table is not installed) must never stop billing, so it is
     * logged and an empty list is returned.
     *
     * @return list<array<string, mixed>>
     */
    private function loadBillable(?int $patientId, ?int $consultationId): array
    {
        try {
            return $this->billingService->getBillableConsultations($patientId, $consultationId);
        } catch (DbalException $exception) {
            $this->logger->error('Billable consultations could not be loaded.', ['exception' => $exception]);

            return [];
        }
    }

    /** The patient, only when active (inactive patients cannot be billed). */
    private function loadActivePatient(?int $patientId): ?PatientDTO
    {
        if (null === $patientId) {
            return null;
        }

        try {
            $patient = $this->billingService->getPatient($patientId);
        } catch (DbalException $exception) {
            $this->logger->error('Patient could not be loaded.', ['exception' => $exception, 'patient_id' => $patientId]);
            $this->addFlash('error', self::MESSAGE_GENERIC_ERROR);

            return null;
        }

        return null !== $patient && $patient->isActive ? $patient : null;
    }

    private function searchPatients(string $search, int $page): ?PatientListDTO
    {
        try {
            return $this->billingService->searchPatients($search, $page);
        } catch (DbalException $exception) {
            $this->logger->error('Patient search failed.', ['exception' => $exception]);
            $this->addFlash('error', self::MESSAGE_GENERIC_ERROR);

            return null;
        }
    }

    /** The bill, or a redirect (with a flash message) when it does not exist or the database fails. */
    private function loadBill(int $id): BillDTO|RedirectResponse
    {
        try {
            $bill = $this->billingService->getBill($id);
        } catch (DbalException $exception) {
            $this->logger->error('Bill could not be loaded.', ['exception' => $exception, 'bill_id' => $id]);
            $this->addFlash('error', self::MESSAGE_GENERIC_ERROR);

            return $this->redirectToRoute('billing_list');
        }

        if (null === $bill) {
            $this->addFlash('error', self::MESSAGE_NOT_FOUND);

            return $this->redirectToRoute('billing_list');
        }

        return $bill;
    }

    /**
     * @param list<array<string, mixed>> $consultations
     * @param array<string, string>      $errors
     */
    private function renderNewBill(
        BillDTO $bill,
        ?PatientDTO $patient,
        ?PatientListDTO $patients,
        string $patientSearch,
        array $consultations,
        array $errors = [],
        int $status = Response::HTTP_OK,
        ?string $formError = null,
    ): Response {
        return $this->render('billing/new.html.twig', [
            'bill' => $bill,
            'patient' => $patient,
            'patients' => $patients,
            'patient_search' => $patientSearch,
            'consultations' => $consultations,
            'errors' => $errors,
            'form_error' => $formError,
            'min_rows' => BillingService::MIN_ITEM_ROWS,
        ], new Response('', $status));
    }

    /**
     * @param array<string, string> $typed
     * @param array<string, string> $errors
     */
    private function renderPayment(
        BillDTO $bill,
        array $typed,
        string $key,
        array $errors = [],
        int $status = Response::HTTP_OK,
        ?string $formError = null,
    ): Response {
        return $this->render('billing/pay.html.twig', [
            'bill' => $bill,
            'typed' => $typed,
            'payment_key' => $key,
            'methods' => BillingService::METHODS,
            'errors' => $errors,
            'form_error' => $formError,
        ], new Response('', $status));
    }
}
