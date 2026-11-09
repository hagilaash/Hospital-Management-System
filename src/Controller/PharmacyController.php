<?php

namespace App\Controller;

use App\Common\Input;
use App\DTO\MedicineDTO;
use App\DTO\PagedListDTO;
use App\DTO\PharmacySummaryDTO;
use App\DTO\PrescriptionDTO;
use App\Service\AccessControlService;
use App\Service\AuthSessionService;
use App\Service\PharmacyService;
use Doctrine\DBAL\Exception as DbalException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * HTTP layer for the Pharmacy.
 *
 *   Pharmacy staff : dashboard, medicines, stock, dispensing
 *   Doctors        : "Prescribe medicines" from an OP Consultation (pharmacy_prescribe*)
 *
 * Authentication is enforced for every route by AuthenticationListener. Each route also
 * declares the permission it needs (defaults: _permission).
 *
 * Every state change is a POST protected by a CSRF token. Token ids:
 *   pharmacy_medicine_{id|new}, pharmacy_receive_{medicineId}, pharmacy_adjust_{stockId},
 *   pharmacy_dispense_{prescriptionId}, pharmacy_prescribe_{consultationId}, pharmacy_remove_{itemId}
 *
 * Database failures are caught, logged and shown as a generic message; no SQL error text
 * reaches the user.
 */
class PharmacyController extends AbstractController
{
    private const MESSAGE_GENERIC_ERROR = 'Something went wrong while accessing pharmacy records. Please try again.';
    private const MESSAGE_CSRF_ERROR = 'Your session has expired. Please try again.';

    public function __construct(
        private PharmacyService $pharmacyService,
        private AuthSessionService $authSession,
        private LoggerInterface $logger,
    ) {
    }

    // ==================================================================
    // Dashboard
    // ==================================================================

    #[Route('/pharmacy', name: 'pharmacy_dashboard', methods: ['GET'], defaults: ['_permission' => AccessControlService::PHARMACY_VIEW])]
    public function dashboard(): Response
    {
        $emptyFilters = ['search' => null, 'form' => null, 'stock' => 'all', 'view' => 'open'];

        try {
            $summary = $this->pharmacyService->getSummary();
            $queue = $this->pharmacyService->getDispenseQueue('open', null, 1);
            $low = $this->pharmacyService->getMedicines(null, null, 'low', 1);
        } catch (DbalException $exception) {
            $this->logger->error('Pharmacy dashboard could not be loaded.', ['exception' => $exception]);
            $this->addFlash('error', self::MESSAGE_GENERIC_ERROR);

            $summary = PharmacySummaryDTO::empty();
            $queue = PagedListDTO::empty(PharmacyService::PER_PAGE, $emptyFilters);
            $low = PagedListDTO::empty(PharmacyService::PER_PAGE, $emptyFilters);
        }

        return $this->render('pharmacy/index.html.twig', ['summary' => $summary, 'queue' => $queue, 'low' => $low]);
    }

    // ==================================================================
    // Medicines
    // ==================================================================

    #[Route('/pharmacy/medicines', name: 'pharmacy_medicines', methods: ['GET'], defaults: ['_permission' => AccessControlService::PHARMACY_VIEW])]
    public function medicines(Request $request): Response
    {
        $query = $request->query;

        try {
            $list = $this->pharmacyService->getMedicines(
                $query->getString('search'),
                $query->getString('form'),
                $query->getString('stock'),
                $query->getInt('page', 1)
            );
        } catch (DbalException $exception) {
            $this->logger->error('Medicine list could not be loaded.', ['exception' => $exception]);
            $this->addFlash('error', self::MESSAGE_GENERIC_ERROR);

            $list = PagedListDTO::empty(PharmacyService::PER_PAGE, ['search' => null, 'form' => null, 'stock' => 'all']);
        }

        return $this->render('pharmacy/medicines.html.twig', [
            'list' => $list,
            'forms' => PharmacyService::FORMS,
            'stock_filters' => PharmacyService::STOCK_FILTERS,
        ]);
    }

    #[Route('/pharmacy/medicines/new', name: 'pharmacy_medicine_new', methods: ['GET', 'POST'], defaults: ['_permission' => AccessControlService::PHARMACY_MANAGE])]
    public function newMedicine(Request $request): Response
    {
        if (!$request->isMethod('POST')) {
            return $this->renderMedicineForm(new MedicineDTO(), null);
        }

        $medicine = MedicineDTO::fromInput($request->request->all());

        if (!$this->isCsrfTokenValid('pharmacy_medicine_new', $request->request->getString('_token'))) {
            return $this->renderMedicineForm($medicine, null, [], Response::HTTP_BAD_REQUEST, self::MESSAGE_CSRF_ERROR);
        }

        return $this->saveMedicine(null, $medicine);
    }

    #[Route('/pharmacy/medicines/{id}/edit', name: 'pharmacy_medicine_edit', requirements: ['id' => '\d{1,9}'], methods: ['GET', 'POST'], defaults: ['_permission' => AccessControlService::PHARMACY_MANAGE])]
    public function editMedicine(int $id, Request $request): Response
    {
        $existing = $this->loadMedicine($id);
        if ($existing instanceof Response) {
            return $existing;
        }

        if (!$request->isMethod('POST')) {
            return $this->renderMedicineForm($existing, $id);
        }

        $medicine = MedicineDTO::fromInput($request->request->all());
        $medicine->medicineId = $id;
        $medicine->medicineCode = $existing->medicineCode;

        if (!$this->isCsrfTokenValid('pharmacy_medicine_'.$id, $request->request->getString('_token'))) {
            return $this->renderMedicineForm($medicine, $id, [], Response::HTTP_BAD_REQUEST, self::MESSAGE_CSRF_ERROR);
        }

        return $this->saveMedicine($id, $medicine);
    }

    #[Route('/pharmacy/medicines/{id}', name: 'pharmacy_medicine_show', requirements: ['id' => '\d{1,9}'], methods: ['GET'], defaults: ['_permission' => AccessControlService::PHARMACY_VIEW])]
    public function showMedicine(int $id): Response
    {
        $medicine = $this->loadMedicine($id);
        if ($medicine instanceof Response) {
            return $medicine;
        }

        try {
            $batches = $this->pharmacyService->getStockBatches($id);
            $movements = $this->pharmacyService->getStockMovements($id);
        } catch (DbalException $exception) {
            $this->logger->error('Stock could not be loaded.', ['exception' => $exception, 'medicine_id' => $id]);
            $this->addFlash('error', self::MESSAGE_GENERIC_ERROR);
            $batches = [];
            $movements = [];
        }

        return $this->render('pharmacy/medicine_show.html.twig', [
            'medicine' => $medicine,
            'batches' => $batches,
            'movements' => $movements,
        ]);
    }

    // ==================================================================
    // Stock (POST only; the result is shown on the medicine page)
    // ==================================================================

    #[Route('/pharmacy/medicines/{id}/stock', name: 'pharmacy_stock_receive', requirements: ['id' => '\d{1,9}'], methods: ['POST'], defaults: ['_permission' => AccessControlService::PHARMACY_MANAGE])]
    public function receiveStock(int $id, Request $request): Response
    {
        $back = $this->redirectToRoute('pharmacy_medicine_show', ['id' => $id]);

        if (!$this->isCsrfTokenValid('pharmacy_receive_'.$id, $request->request->getString('_token'))) {
            $this->addFlash('error', self::MESSAGE_CSRF_ERROR);

            return $back;
        }

        try {
            $result = $this->pharmacyService->receiveStock(
                $id,
                $request->request->getString('batch_number'),
                $request->request->getString('expiry_date'),
                $request->request->getString('quantity'),
                $request->request->getString('unit_cost'),
                $request->request->getString('supplier'),
                $this->currentUsername()
            );
        } catch (DbalException $exception) {
            $this->logger->error('Receiving stock failed.', ['exception' => $exception, 'medicine_id' => $id]);
            $this->addFlash('error', self::MESSAGE_GENERIC_ERROR);

            return $back;
        }

        $this->flashResult($result);

        return $back;
    }

    #[Route('/pharmacy/stock/{id}/adjust', name: 'pharmacy_stock_adjust', requirements: ['id' => '\d{1,9}'], methods: ['POST'], defaults: ['_permission' => AccessControlService::PHARMACY_MANAGE])]
    public function adjustStock(int $id, Request $request): Response
    {
        $medicineId = Input::positiveInt($request->request->getString('medicine_id'));
        $back = null !== $medicineId
            ? $this->redirectToRoute('pharmacy_medicine_show', ['id' => $medicineId])
            : $this->redirectToRoute('pharmacy_medicines');

        if (!$this->isCsrfTokenValid('pharmacy_adjust_'.$id, $request->request->getString('_token'))) {
            $this->addFlash('error', self::MESSAGE_CSRF_ERROR);

            return $back;
        }

        try {
            $result = $this->pharmacyService->adjustStock(
                $id,
                $request->request->getString('change'),
                $request->request->getString('reason'),
                $this->currentUsername()
            );
        } catch (DbalException $exception) {
            $this->logger->error('Adjusting stock failed.', ['exception' => $exception, 'stock_id' => $id]);
            $this->addFlash('error', self::MESSAGE_GENERIC_ERROR);

            return $back;
        }

        $this->flashResult($result);

        return $back;
    }

    // ==================================================================
    // Dispensing
    // ==================================================================

    #[Route('/pharmacy/dispensing', name: 'pharmacy_dispensing', methods: ['GET'], defaults: ['_permission' => AccessControlService::PHARMACY_VIEW])]
    public function dispensing(Request $request): Response
    {
        $query = $request->query;

        try {
            $list = $this->pharmacyService->getDispenseQueue($query->getString('view'), $query->getString('search'), $query->getInt('page', 1));
        } catch (DbalException $exception) {
            $this->logger->error('Dispensing queue could not be loaded.', ['exception' => $exception]);
            $this->addFlash('error', self::MESSAGE_GENERIC_ERROR);

            $list = PagedListDTO::empty(PharmacyService::PER_PAGE, ['view' => 'open', 'search' => null]);
        }

        return $this->render('pharmacy/dispensing.html.twig', ['list' => $list]);
    }

    #[Route('/pharmacy/prescriptions/{id}', name: 'pharmacy_prescription_show', requirements: ['id' => '\d{1,9}'], methods: ['GET'], defaults: ['_permission' => AccessControlService::PHARMACY_VIEW])]
    public function showPrescription(int $id): Response
    {
        $prescription = $this->loadPrescription($id);
        if ($prescription instanceof Response) {
            return $prescription;
        }

        return $this->renderPrescription($prescription);
    }

    #[Route('/pharmacy/prescriptions/{id}/dispense', name: 'pharmacy_dispense', requirements: ['id' => '\d{1,9}'], methods: ['POST'], defaults: ['_permission' => AccessControlService::PHARMACY_DISPENSE])]
    public function dispense(int $id, Request $request): Response
    {
        $prescription = $this->loadPrescription($id);
        if ($prescription instanceof Response) {
            return $prescription;
        }

        $typed = $request->request->all('qty');

        if (!$this->isCsrfTokenValid('pharmacy_dispense_'.$id, $request->request->getString('_token'))) {
            return $this->renderPrescription($prescription, $typed, [], Response::HTTP_BAD_REQUEST, self::MESSAGE_CSRF_ERROR);
        }

        try {
            $result = $this->pharmacyService->dispense($id, $typed, $this->currentUsername());
        } catch (DbalException $exception) {
            $this->logger->error('Dispensing failed.', ['exception' => $exception, 'prescription_id' => $id]);

            return $this->renderPrescription($prescription, $typed, [], Response::HTTP_SERVICE_UNAVAILABLE, self::MESSAGE_GENERIC_ERROR);
        }

        if ($result['success']) {
            $this->addFlash('success', sprintf('%s. Prescription %s is now %s.', $result['message'], $prescription->prescriptionCode, $result['status']));

            return $this->redirectToRoute('pharmacy_prescription_show', ['id' => $id]);
        }

        $status = [] !== $result['errors'] ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_CONFLICT;

        return $this->renderPrescription($prescription, $typed, $result['errors'], $status, $result['message']);
    }

    // ==================================================================
    // Prescribing: the doctor's screens, reached from OP Consultation
    // ==================================================================

    /** {id} is the CONSULTATION id. */
    #[Route('/pharmacy/prescribe/{id}', name: 'pharmacy_prescribe', requirements: ['id' => '\d{1,9}'], methods: ['GET'], defaults: ['_permission' => AccessControlService::PHARMACY_PRESCRIBE])]
    public function prescribe(int $id, Request $request): Response
    {
        $prescription = $this->loadConsultationPrescription($id);
        if ($prescription instanceof Response) {
            return $prescription;
        }

        $selectedId = Input::positiveInt($request->query->getString('medicine_id'));

        return $this->renderPrescribe($prescription, $request->query->getString('q'), $selectedId);
    }

    #[Route('/pharmacy/prescribe/{id}/items', name: 'pharmacy_prescribe_add', requirements: ['id' => '\d{1,9}'], methods: ['POST'], defaults: ['_permission' => AccessControlService::PHARMACY_PRESCRIBE])]
    public function addItem(int $id, Request $request): Response
    {
        $prescription = $this->loadConsultationPrescription($id);
        if ($prescription instanceof Response) {
            return $prescription;
        }

        $post = $request->request;
        $typed = [
            'medicine_id' => $post->getString('medicine_id'),
            'dosage' => $post->getString('dosage'),
            'frequency' => $post->getString('frequency'),
            'duration_days' => $post->getString('duration_days'),
            'quantity' => $post->getString('quantity'),
            'instructions' => $post->getString('instructions'),
        ];
        $selectedId = Input::positiveInt($typed['medicine_id']);

        if (!$this->isCsrfTokenValid('pharmacy_prescribe_'.$id, $post->getString('_token'))) {
            return $this->renderPrescribe($prescription, '', $selectedId, $typed, [], Response::HTTP_BAD_REQUEST, self::MESSAGE_CSRF_ERROR);
        }

        try {
            $result = $this->pharmacyService->addPrescriptionItem(
                $id,
                $typed['medicine_id'],
                $typed['dosage'],
                $typed['frequency'],
                $typed['duration_days'],
                $typed['quantity'],
                $typed['instructions'],
                $this->currentUsername()
            );
        } catch (DbalException $exception) {
            $this->logger->error('Adding a prescription item failed.', ['exception' => $exception, 'consultation_id' => $id]);

            return $this->renderPrescribe($prescription, '', $selectedId, $typed, [], Response::HTTP_SERVICE_UNAVAILABLE, self::MESSAGE_GENERIC_ERROR);
        }

        if ($result['success']) {
            $this->addFlash('success', $result['message']);

            return $this->redirectToRoute('pharmacy_prescribe', ['id' => $id]);
        }

        $status = [] !== $result['errors'] ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_CONFLICT;

        return $this->renderPrescribe($prescription, '', $selectedId, $typed, $result['errors'], $status, $result['message']);
    }

    #[Route('/pharmacy/prescribe/{id}/items/{itemId}/remove', name: 'pharmacy_prescribe_remove', requirements: ['id' => '\d{1,9}', 'itemId' => '\d{1,9}'], methods: ['POST'], defaults: ['_permission' => AccessControlService::PHARMACY_PRESCRIBE])]
    public function removeItem(int $id, int $itemId, Request $request): Response
    {
        $back = $this->redirectToRoute('pharmacy_prescribe', ['id' => $id]);

        if (!$this->isCsrfTokenValid('pharmacy_remove_'.$itemId, $request->request->getString('_token'))) {
            $this->addFlash('error', self::MESSAGE_CSRF_ERROR);

            return $back;
        }

        try {
            $result = $this->pharmacyService->removePrescriptionItem($itemId, $this->currentUsername());
        } catch (DbalException $exception) {
            $this->logger->error('Removing a prescription item failed.', ['exception' => $exception, 'item_id' => $itemId]);
            $this->addFlash('error', self::MESSAGE_GENERIC_ERROR);

            return $back;
        }

        $this->flashResult($result);

        return $back;
    }

    // ==================================================================
    // Helpers
    // ==================================================================

    private function currentUsername(): string
    {
        return $this->authSession->getUsername() ?? '';
    }

    /**
     * Shows a service result as a flash message. A field-level refusal is shown together with
     * the general message so nothing the user needs to know is hidden.
     *
     * @param array{success: bool, message: string, errors: array<string, string>} $result
     */
    private function flashResult(array $result): void
    {
        if ($result['success']) {
            $this->addFlash('success', $result['message']);

            return;
        }

        $detail = array_unique(array_values($result['errors']));
        $this->addFlash('error', [] === $detail || $result['message'] === $detail[0] ? $result['message'] : $result['message'].': '.implode('. ', $detail));
    }

    private function saveMedicine(?int $id, MedicineDTO $medicine): Response
    {
        try {
            $result = $this->pharmacyService->saveMedicine($id, $medicine, $this->currentUsername());
        } catch (DbalException $exception) {
            $this->logger->error('Saving a medicine failed.', ['exception' => $exception, 'medicine_id' => $id]);

            return $this->renderMedicineForm($medicine, $id, [], Response::HTTP_SERVICE_UNAVAILABLE, self::MESSAGE_GENERIC_ERROR);
        }

        if ($result['success']) {
            $this->addFlash('success', $result['message']);

            return $this->redirectToRoute('pharmacy_medicine_show', ['id' => $result['medicine_id']]);
        }

        $status = [] !== $result['errors'] ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_CONFLICT;

        return $this->renderMedicineForm($medicine, $id, $result['errors'], $status, $result['message']);
    }

    private function loadMedicine(int $id): MedicineDTO|RedirectResponse
    {
        try {
            $medicine = $this->pharmacyService->getMedicine($id);
        } catch (DbalException $exception) {
            $this->logger->error('Medicine could not be loaded.', ['exception' => $exception, 'medicine_id' => $id]);
            $this->addFlash('error', self::MESSAGE_GENERIC_ERROR);

            return $this->redirectToRoute('pharmacy_medicines');
        }

        if (null === $medicine) {
            $this->addFlash('error', 'Medicine not found');

            return $this->redirectToRoute('pharmacy_medicines');
        }

        return $medicine;
    }

    private function loadPrescription(int $id): PrescriptionDTO|RedirectResponse
    {
        try {
            $prescription = $this->pharmacyService->getPrescription($id);
        } catch (DbalException $exception) {
            $this->logger->error('Prescription could not be loaded.', ['exception' => $exception, 'prescription_id' => $id]);
            $this->addFlash('error', self::MESSAGE_GENERIC_ERROR);

            return $this->redirectToRoute('pharmacy_dispensing');
        }

        if (null === $prescription) {
            $this->addFlash('error', 'Prescription not found');

            return $this->redirectToRoute('pharmacy_dispensing');
        }

        return $prescription;
    }

    /** The consultation's prescription (empty header when no medicine has been added yet). */
    private function loadConsultationPrescription(int $consultationId): PrescriptionDTO|RedirectResponse
    {
        try {
            $prescription = $this->pharmacyService->getPrescriptionForConsultation($consultationId);
        } catch (DbalException $exception) {
            $this->logger->error('Consultation could not be loaded.', ['exception' => $exception, 'consultation_id' => $consultationId]);
            $this->addFlash('error', self::MESSAGE_GENERIC_ERROR);

            return $this->redirectToRoute('opc_dashboard');
        }

        if (null === $prescription) {
            $this->addFlash('error', 'Consultation not found');

            return $this->redirectToRoute('opc_dashboard');
        }

        return $prescription;
    }

    /**
     * @param array<string, string> $errors
     */
    private function renderMedicineForm(MedicineDTO $medicine, ?int $medicineId, array $errors = [], int $status = Response::HTTP_OK, ?string $formError = null): Response
    {
        return $this->render('pharmacy/medicine_form.html.twig', [
            'medicine' => $medicine,
            'medicine_id' => $medicineId,
            'forms' => PharmacyService::FORMS,
            'errors' => $errors,
            'form_error' => $formError,
        ], new Response('', $status));
    }

    /**
     * @param array<int|string, mixed> $typed
     * @param array<string, string>    $errors
     */
    private function renderPrescription(PrescriptionDTO $prescription, array $typed = [], array $errors = [], int $status = Response::HTTP_OK, ?string $formError = null): Response
    {
        return $this->render('pharmacy/prescription.html.twig', [
            'prescription' => $prescription,
            'typed' => $typed,
            'errors' => $errors,
            'form_error' => $formError,
        ], new Response('', $status));
    }

    /**
     * @param array<string, string> $typed
     * @param array<string, string> $errors
     */
    private function renderPrescribe(
        PrescriptionDTO $prescription,
        string $search,
        ?int $selectedId,
        array $typed = [],
        array $errors = [],
        int $status = Response::HTTP_OK,
        ?string $formError = null,
    ): Response {
        $results = [];
        $selected = null;

        if ($prescription->canEdit()) {
            try {
                $results = $this->pharmacyService->searchPrescribable($search);
                $selected = null !== $selectedId ? $this->pharmacyService->getMedicine($selectedId) : null;
            } catch (DbalException $exception) {
                $this->logger->error('Medicines could not be searched.', ['exception' => $exception]);
                $this->addFlash('error', self::MESSAGE_GENERIC_ERROR);
            }
            if (null !== $selected && (!$selected->isActive || $selected->availableQuantity < 1)) {
                $selected = null;
            }
        }

        return $this->render('pharmacy/prescribe.html.twig', [
            'prescription' => $prescription,
            'search' => $search,
            'results' => $results,
            'selected' => $selected,
            'frequencies' => PharmacyService::FREQUENCIES,
            'typed' => $typed + ['medicine_id' => '', 'dosage' => '', 'frequency' => '', 'duration_days' => '', 'quantity' => '', 'instructions' => ''],
            'errors' => $errors,
            'form_error' => $formError,
        ], new Response('', $status));
    }
}
