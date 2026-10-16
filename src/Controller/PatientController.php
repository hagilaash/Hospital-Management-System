<?php

namespace App\Controller;

use App\DTO\PatientDTO;
use App\DTO\PatientListDTO;
use App\Service\AccessControlService;
use App\Service\AuthSessionService;
use App\Service\PatientService;
use Doctrine\DBAL\Exception as DbalException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * HTTP layer for the Patient Management module.
 *
 * Authentication is enforced for every route by AuthenticationListener.
 * Each route also declares the permission it needs (defaults: _permission);
 * the listener checks it through AccessControlService before the action runs.
 *
 * CSRF token ids used here (and in the templates):
 *   patient_new, patient_edit_{id}, patient_status_{id}
 *
 * Database failures are caught here, logged, and shown as a generic message,
 * the same way LoginController handles them. No SQL error text reaches the user.
 */
class PatientController extends AbstractController
{
    private const MESSAGE_GENERIC_ERROR = 'Something went wrong while accessing patient records. Please try again.';
    private const MESSAGE_CSRF_ERROR = 'Your session has expired. Please try again.';
    private const MESSAGE_NOT_FOUND = 'Patient not found';

    public function __construct(
        private PatientService $patientService,
        private AuthSessionService $authSession,
        private LoggerInterface $logger
    ) {
    }

    // ------------------------------------------------------------------
    // List
    // ------------------------------------------------------------------

    #[Route(
        '/patients',
        name: 'patient_list',
        methods: ['GET'],
        defaults: ['_permission' => AccessControlService::PATIENT_VIEW]
    )]
    public function index(Request $request): Response
    {
        $search = $request->query->getString('search');
        $status = $request->query->getString('status');
        $page = $request->query->getInt('page', 1);

        try {
            $list = $this->patientService->getPatientsList($search, $status, $page);
        } catch (DbalException $exception) {
            $this->logger->error('Patient list could not be loaded.', ['exception' => $exception]);
            $this->addFlash('error', self::MESSAGE_GENERIC_ERROR);

            $list = new PatientListDTO([], 0, 1, PatientService::PER_PAGE, 1, null, PatientService::DEFAULT_STATUS);
        }

        return $this->render('patient/list.html.twig', [
            'list' => $list,
        ]);
    }

    // ------------------------------------------------------------------
    // Register
    // ------------------------------------------------------------------

    #[Route(
        '/patients/new',
        name: 'patient_new',
        methods: ['GET', 'POST'],
        defaults: ['_permission' => AccessControlService::PATIENT_CREATE]
    )]
    public function new(Request $request): Response
    {
        if (!$request->isMethod('POST')) {
            return $this->renderForm('new', new PatientDTO());
        }

        $patient = PatientDTO::fromInput($request->request->all());

        if (!$this->isCsrfTokenValid('patient_new', $request->request->getString('_token'))) {
            return $this->renderForm('new', $patient, [], Response::HTTP_BAD_REQUEST, self::MESSAGE_CSRF_ERROR);
        }

        try {
            $result = $this->patientService->registerPatient(
                $patient,
                $this->currentUsername(),
                $request->request->getBoolean('confirm_duplicate')
            );
        } catch (DbalException $exception) {
            $this->logger->error('Patient registration failed.', ['exception' => $exception]);

            return $this->renderForm(
                'new',
                $patient,
                [],
                Response::HTTP_SERVICE_UNAVAILABLE,
                self::MESSAGE_GENERIC_ERROR
            );
        }

        if ($result['success']) {
            $this->addFlash('success', sprintf('%s. Patient ID: %s', $result['message'], $result['patient_code']));

            return $this->redirectToRoute('patient_show', ['id' => $result['patient_id']]);
        }

        return $this->renderFailedSubmission('new', $patient, $result);
    }

    // ------------------------------------------------------------------
    // Details
    // ------------------------------------------------------------------

    #[Route(
        '/patients/{id}',
        name: 'patient_show',
        requirements: ['id' => '\d{1,9}'],
        methods: ['GET'],
        defaults: ['_permission' => AccessControlService::PATIENT_VIEW]
    )]
    public function show(int $id): Response
    {
        $patient = $this->loadPatient($id);
        if ($patient instanceof Response) {
            return $patient;
        }

        return $this->render('patient/show.html.twig', [
            'patient' => $patient,
        ]);
    }

    // ------------------------------------------------------------------
    // Edit
    // ------------------------------------------------------------------

    #[Route(
        '/patients/{id}/edit',
        name: 'patient_edit',
        requirements: ['id' => '\d{1,9}'],
        methods: ['GET', 'POST'],
        defaults: ['_permission' => AccessControlService::PATIENT_EDIT]
    )]
    public function edit(int $id, Request $request): Response
    {
        $existing = $this->loadPatient($id);
        if ($existing instanceof Response) {
            return $existing;
        }

        if (!$existing->isActive) {
            $this->addFlash('error', 'Inactive patients must be reactivated before they can be edited');

            return $this->redirectToRoute('patient_show', ['id' => $id]);
        }

        if (!$request->isMethod('POST')) {
            return $this->renderForm('edit', $existing);
        }

        // Rebuild the DTO from the submitted form, keeping the identity fields
        // (which the form never sends) so the page can be redisplayed.
        $patient = PatientDTO::fromInput($request->request->all());
        $patient->patientId = $id;
        $patient->patientCode = $existing->patientCode;
        $patient->isActive = true;

        if (!$this->isCsrfTokenValid('patient_edit_' . $id, $request->request->getString('_token'))) {
            return $this->renderForm('edit', $patient, [], Response::HTTP_BAD_REQUEST, self::MESSAGE_CSRF_ERROR);
        }

        try {
            $result = $this->patientService->updatePatient(
                $id,
                $patient,
                $this->currentUsername(),
                $request->request->getBoolean('confirm_duplicate')
            );
        } catch (DbalException $exception) {
            $this->logger->error('Patient update failed.', ['exception' => $exception, 'patient_id' => $id]);

            return $this->renderForm(
                'edit',
                $patient,
                [],
                Response::HTTP_SERVICE_UNAVAILABLE,
                self::MESSAGE_GENERIC_ERROR
            );
        }

        if ($result['success']) {
            $this->addFlash('success', $result['message']);

            return $this->redirectToRoute('patient_show', ['id' => $id]);
        }

        return $this->renderFailedSubmission('edit', $patient, $result);
    }

    // ------------------------------------------------------------------
    // Activate / deactivate (soft status change, never a delete)
    // ------------------------------------------------------------------

    #[Route(
        '/patients/{id}/status',
        name: 'patient_status',
        requirements: ['id' => '\d{1,9}'],
        methods: ['POST'],
        defaults: ['_permission' => AccessControlService::PATIENT_STATUS]
    )]
    public function status(int $id, Request $request): Response
    {
        $redirect = $this->redirectAfterStatusChange($id, $request);

        if (!$this->isCsrfTokenValid('patient_status_' . $id, $request->request->getString('_token'))) {
            $this->addFlash('error', self::MESSAGE_CSRF_ERROR);

            return $redirect;
        }

        $action = $request->request->getString('action');
        if (!in_array($action, ['activate', 'deactivate'], true)) {
            $this->addFlash('error', 'Unknown action');

            return $redirect;
        }

        try {
            $result = $this->patientService->changePatientStatus($id, $action === 'activate', $this->currentUsername());
        } catch (DbalException $exception) {
            $this->logger->error('Patient status change failed.', ['exception' => $exception, 'patient_id' => $id]);
            $this->addFlash('error', self::MESSAGE_GENERIC_ERROR);

            return $redirect;
        }

        $this->addFlash($result['success'] ? 'success' : 'error', $result['message']);

        return $redirect;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function currentUsername(): string
    {
        return $this->authSession->getUsername() ?? '';
    }

    /**
     * Returns the patient, or a redirect response (with a flash message) when
     * the patient does not exist or the database fails.
     */
    private function loadPatient(int $id): PatientDTO|RedirectResponse
    {
        try {
            $patient = $this->patientService->getPatient($id);
        } catch (DbalException $exception) {
            $this->logger->error('Patient could not be loaded.', ['exception' => $exception, 'patient_id' => $id]);
            $this->addFlash('error', self::MESSAGE_GENERIC_ERROR);

            return $this->redirectToRoute('patient_list');
        }

        if ($patient === null) {
            $this->addFlash('error', self::MESSAGE_NOT_FOUND);

            return $this->redirectToRoute('patient_list');
        }

        return $patient;
    }

    /**
     * Back to the details page, or back to the list with the same search,
     * filter and page the user was looking at. The values go through the
     * router (so they are URL-encoded), never into a raw Location header.
     */
    private function redirectAfterStatusChange(int $id, Request $request): RedirectResponse
    {
        if ($request->request->getString('return') === 'show') {
            return $this->redirectToRoute('patient_show', ['id' => $id]);
        }

        $params = array_filter([
            'search' => $request->request->getString('search'),
            'status' => $request->request->getString('status'),
            'page' => max(1, $request->request->getInt('page', 1)),
        ], static fn ($value): bool => $value !== '' && $value !== 1);

        return $this->redirectToRoute('patient_list', $params);
    }

    /**
     * Shows the form again after a failed save:
     *  - duplicate warning  -> 200, the template shows the matches and the confirm button
     *  - validation errors  -> 422 with per-field messages
     *  - any other refusal  -> 409 with the message from the database function
     *
     * @param array<string, mixed> $result the service result
     */
    private function renderFailedSubmission(string $mode, PatientDTO $patient, array $result): Response
    {
        if ($result['duplicate_warning']) {
            return $this->renderForm($mode, $patient, $result);
        }

        $status = $result['errors'] !== [] ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_CONFLICT;

        return $this->renderForm($mode, $patient, $result, $status, $result['message']);
    }

    /**
     * @param array<string, mixed> $result the service result, or [] on a first display
     */
    private function renderForm(
        string $mode,
        PatientDTO $patient,
        array $result = [],
        int $status = Response::HTTP_OK,
        ?string $formError = null
    ): Response {
        return $this->render('patient/form.html.twig', [
            'mode' => $mode,
            'patient' => $patient,
            'errors' => $result['errors'] ?? [],
            'duplicates' => $result['duplicates'] ?? [],
            'duplicate_warning' => $result['duplicate_warning'] ?? false,
            'form_error' => $formError,
            'genders' => PatientService::GENDERS,
            'blood_groups' => PatientService::BLOOD_GROUPS,
        ], new Response('', $status));
    }
}