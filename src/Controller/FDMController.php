<?php

namespace App\Controller;

use App\DTO\AppointmentDTO;
use App\DTO\AppointmentListDTO;
use App\DTO\FDMDashboardDTO;
use App\DTO\PatientDTO;
use App\DTO\PatientListDTO;
use App\DTO\QueueListDTO;
use App\Service\AccessControlService;
use App\Service\AuthSessionService;
use App\Service\FDMService;
use Doctrine\DBAL\Exception as DbalException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * HTTP layer for the Front Desk Management module.
 *
 * Authentication is enforced for every route by AuthenticationListener. Each
 * route also declares the permission it needs (defaults: _permission); the
 * listener checks it through AccessControlService before the action runs.
 *
 * Every state change is a POST protected by a CSRF token. Token ids (used here
 * and in the templates):
 *   fdm_appointment_new, fdm_appointment_edit_{id}, fdm_appointment_cancel_{id},
 *   fdm_appointment_no_show_{id}, fdm_check_in_{id}, fdm_walk_in, fdm_queue_{id}
 *
 * Database failures are caught here, logged, and shown as a generic message,
 * the same way LoginController and PatientController do. No SQL error text
 * reaches the user.
 */
class FDMController extends AbstractController
{
    private const MESSAGE_GENERIC_ERROR = 'Something went wrong while accessing front desk records. Please try again.';
    private const MESSAGE_CSRF_ERROR = 'Your session has expired. Please try again.';
    private const MESSAGE_NOT_FOUND = 'Appointment not found';

    public function __construct(
        private FDMService $fdmService,
        private AuthSessionService $authSession,
        private LoggerInterface $logger
    ) {
    }

    // ------------------------------------------------------------------
    // Dashboard
    // ------------------------------------------------------------------

    #[Route(
        '/fdm',
        name: 'fdm_dashboard',
        methods: ['GET'],
        defaults: ['_permission' => AccessControlService::FDM_VIEW]
    )]
    public function dashboard(): Response
    {
        try {
            $dashboard = $this->fdmService->getDashboard();
        } catch (DbalException $exception) {
            $this->logger->error('FDM dashboard could not be loaded.', ['exception' => $exception]);
            $this->addFlash('error', self::MESSAGE_GENERIC_ERROR);

            $dashboard = FDMDashboardDTO::empty();
        }

        return $this->render('fdm/index.html.twig', [
            'dashboard' => $dashboard,
        ]);
    }

    // ------------------------------------------------------------------
    // Appointment list
    // ------------------------------------------------------------------

    #[Route(
        '/fdm/appointments',
        name: 'fdm_appointment_list',
        methods: ['GET'],
        defaults: ['_permission' => AccessControlService::FDM_VIEW]
    )]
    public function appointments(Request $request): Response
    {
        $query = $request->query;

        try {
            $list = $this->fdmService->getAppointments(
                $query->has('date_from') ? $query->getString('date_from') : null,
                $query->has('date_to') ? $query->getString('date_to') : null,
                $query->getString('doctor_id'),
                $query->getString('patient_id'),
                $query->getString('status'),
                $query->getString('search'),
                $query->getInt('page', 1)
            );
        } catch (DbalException $exception) {
            $this->logger->error('Appointment list could not be loaded.', ['exception' => $exception]);
            $this->addFlash('error', self::MESSAGE_GENERIC_ERROR);

            $list = new AppointmentListDTO([], 0, 1, FDMService::APPOINTMENTS_PER_PAGE, 1, null, null, null, null, 'all', null, '');
        }

        return $this->render('fdm/appointments.html.twig', [
            'list' => $list,
            'doctors' => $this->loadDoctors(),
            'statuses' => FDMService::APPOINTMENT_STATUS_FILTERS,
        ]);
    }

    // ------------------------------------------------------------------
    // New appointment
    // ------------------------------------------------------------------

    #[Route(
        '/fdm/appointments/new',
        name: 'fdm_appointment_new',
        methods: ['GET', 'POST'],
        defaults: ['_permission' => AccessControlService::FDM_APPOINTMENT_CREATE]
    )]
    public function newAppointment(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            return $this->createAppointmentFromRequest($request);
        }

        $appointment = new AppointmentDTO();
        $appointment->doctorId = $this->digitsOrNull($request->query->getString('doctor_id'));
        $appointment->appointmentDate = $request->query->getString('appointment_date');

        $patient = $this->loadPatient($this->digitsOrNull($request->query->getString('patient_id')));
        $search = $request->query->getString('q');
        $patients = null;
        if ($patient === null) {
            $patients = $this->searchPatients($search, $request->query->getInt('page', 1));
        }

        $appointment->patientId = $patient?->patientId;

        return $this->renderAppointmentForm('new', $appointment, $patient, $patients, $search);
    }

    private function createAppointmentFromRequest(Request $request): Response
    {
        $appointment = AppointmentDTO::fromInput($request->request->all());
        $patient = $this->loadPatient($appointment->patientId);
        $patients = $patient === null ? $this->searchPatients('', 1) : null;

        if (!$this->isCsrfTokenValid('fdm_appointment_new', $request->request->getString('_token'))) {
            return $this->renderAppointmentForm('new', $appointment, $patient, $patients, '', [], Response::HTTP_BAD_REQUEST, self::MESSAGE_CSRF_ERROR);
        }

        try {
            $result = $this->fdmService->createAppointment($appointment, $this->currentUsername());
        } catch (DbalException $exception) {
            $this->logger->error('Appointment creation failed.', ['exception' => $exception]);

            return $this->renderAppointmentForm(
                'new',
                $appointment,
                $patient,
                $patients,
                '',
                [],
                Response::HTTP_SERVICE_UNAVAILABLE,
                self::MESSAGE_GENERIC_ERROR
            );
        }

        if ($result['success']) {
            $this->addFlash('success', sprintf('%s. Appointment number: %s', $result['message'], $result['appointment_code']));

            return $this->redirectToRoute('fdm_appointment_show', ['id' => $result['appointment_id']]);
        }

        return $this->renderFailedSubmission('new', $appointment, $patient, $patients, $result);
    }

    // ------------------------------------------------------------------
    // Details
    // ------------------------------------------------------------------

    #[Route(
        '/fdm/appointments/{id}',
        name: 'fdm_appointment_show',
        requirements: ['id' => '\d{1,9}'],
        methods: ['GET'],
        defaults: ['_permission' => AccessControlService::FDM_VIEW]
    )]
    public function showAppointment(int $id): Response
    {
        $appointment = $this->loadAppointment($id);
        if ($appointment instanceof Response) {
            return $appointment;
        }

        return $this->render('fdm/appointment_show.html.twig', [
            'appointment' => $appointment,
            'today' => $this->loadToday(),
        ]);
    }

    // ------------------------------------------------------------------
    // Edit
    // ------------------------------------------------------------------

    #[Route(
        '/fdm/appointments/{id}/edit',
        name: 'fdm_appointment_edit',
        requirements: ['id' => '\d{1,9}'],
        methods: ['GET', 'POST'],
        defaults: ['_permission' => AccessControlService::FDM_APPOINTMENT_EDIT]
    )]
    public function editAppointment(int $id, Request $request): Response
    {
        $existing = $this->loadAppointment($id);
        if ($existing instanceof Response) {
            return $existing;
        }

        if (!$existing->canEdit()) {
            $this->addFlash('error', 'Only scheduled appointments can be changed');

            return $this->redirectToRoute('fdm_appointment_show', ['id' => $id]);
        }

        $patient = $this->loadPatient($existing->patientId);

        if (!$request->isMethod('POST')) {
            return $this->renderAppointmentForm('edit', $existing, $patient, null);
        }

        // Rebuild the DTO from the submitted form, keeping the identity fields
        // (which the form never sends) so the page can be redisplayed.
        $appointment = AppointmentDTO::fromInput($request->request->all());
        $appointment->appointmentId = $id;
        $appointment->appointmentCode = $existing->appointmentCode;
        $appointment->patientId = $existing->patientId;
        $appointment->patientName = $existing->patientName;
        $appointment->patientCode = $existing->patientCode;

        if (!$this->isCsrfTokenValid('fdm_appointment_edit_' . $id, $request->request->getString('_token'))) {
            return $this->renderAppointmentForm('edit', $appointment, $patient, null, '', [], Response::HTTP_BAD_REQUEST, self::MESSAGE_CSRF_ERROR);
        }

        try {
            $result = $this->fdmService->updateAppointment($id, $appointment, $this->currentUsername());
        } catch (DbalException $exception) {
            $this->logger->error('Appointment update failed.', ['exception' => $exception, 'appointment_id' => $id]);

            return $this->renderAppointmentForm(
                'edit',
                $appointment,
                $patient,
                null,
                '',
                [],
                Response::HTTP_SERVICE_UNAVAILABLE,
                self::MESSAGE_GENERIC_ERROR
            );
        }

        if ($result['success']) {
            $this->addFlash('success', $result['message']);

            return $this->redirectToRoute('fdm_appointment_show', ['id' => $id]);
        }

        return $this->renderFailedSubmission('edit', $appointment, $patient, null, $result);
    }

    // ------------------------------------------------------------------
    // Cancel and no-show (POST only)
    // ------------------------------------------------------------------

    #[Route(
        '/fdm/appointments/{id}/cancel',
        name: 'fdm_appointment_cancel',
        requirements: ['id' => '\d{1,9}'],
        methods: ['POST'],
        defaults: ['_permission' => AccessControlService::FDM_APPOINTMENT_CANCEL]
    )]
    public function cancelAppointment(int $id, Request $request): Response
    {
        $redirect = $this->redirectAfterAppointmentAction($id, $request);

        if (!$this->isCsrfTokenValid('fdm_appointment_cancel_' . $id, $request->request->getString('_token'))) {
            $this->addFlash('error', self::MESSAGE_CSRF_ERROR);

            return $redirect;
        }

        try {
            $result = $this->fdmService->cancelAppointment($id, $request->request->getString('reason'), $this->currentUsername());
        } catch (DbalException $exception) {
            $this->logger->error('Appointment cancellation failed.', ['exception' => $exception, 'appointment_id' => $id]);
            $this->addFlash('error', self::MESSAGE_GENERIC_ERROR);

            return $redirect;
        }

        $this->addFlash($result['success'] ? 'success' : 'error', $result['message']);

        return $redirect;
    }

    #[Route(
        '/fdm/appointments/{id}/no-show',
        name: 'fdm_appointment_no_show',
        requirements: ['id' => '\d{1,9}'],
        methods: ['POST'],
        defaults: ['_permission' => AccessControlService::FDM_APPOINTMENT_CANCEL]
    )]
    public function markNoShow(int $id, Request $request): Response
    {
        $redirect = $this->redirectAfterAppointmentAction($id, $request);

        if (!$this->isCsrfTokenValid('fdm_appointment_no_show_' . $id, $request->request->getString('_token'))) {
            $this->addFlash('error', self::MESSAGE_CSRF_ERROR);

            return $redirect;
        }

        try {
            $result = $this->fdmService->markNoShow($id, $this->currentUsername());
        } catch (DbalException $exception) {
            $this->logger->error('Marking no-show failed.', ['exception' => $exception, 'appointment_id' => $id]);
            $this->addFlash('error', self::MESSAGE_GENERIC_ERROR);

            return $redirect;
        }

        $this->addFlash($result['success'] ? 'success' : 'error', $result['message']);

        return $redirect;
    }

    // ------------------------------------------------------------------
    // Check-in page, scheduled check-in, walk-in
    // ------------------------------------------------------------------

    #[Route(
        '/fdm/check-in',
        name: 'fdm_check_in',
        methods: ['GET'],
        defaults: ['_permission' => AccessControlService::FDM_CHECK_IN]
    )]
    public function checkIn(Request $request): Response
    {
        $query = $request->query;

        // Today's scheduled appointments (the dates are not sent, so they default to today).
        try {
            $appointments = $this->fdmService->getAppointments(
                null,
                null,
                '',
                '',
                'Scheduled',
                $query->getString('search'),
                $query->getInt('page', 1)
            );
        } catch (DbalException $exception) {
            $this->logger->error('Check-in list could not be loaded.', ['exception' => $exception]);
            $this->addFlash('error', self::MESSAGE_GENERIC_ERROR);

            $appointments = new AppointmentListDTO([], 0, 1, FDMService::APPOINTMENTS_PER_PAGE, 1, null, null, null, null, 'Scheduled', null, '');
        }

        // Walk-in: either a patient has been chosen, or a patient search is shown.
        $walkInPatient = $this->loadPatient($this->digitsOrNull($query->getString('walkin_patient_id')));
        $patientSearch = $query->getString('q');
        $patientResults = null;
        if ($walkInPatient === null && trim($patientSearch) !== '') {
            $patientResults = $this->searchPatients($patientSearch, $query->getInt('qpage', 1));
        }

        return $this->render('fdm/check_in.html.twig', [
            'appointments' => $appointments,
            'walkin_patient' => $walkInPatient,
            'patient_results' => $patientResults,
            'patient_search' => $patientSearch,
            'doctors' => $this->loadDoctors(),
        ]);
    }

    #[Route(
        '/fdm/appointments/{id}/check-in',
        name: 'fdm_check_in_appointment',
        requirements: ['id' => '\d{1,9}'],
        methods: ['POST'],
        defaults: ['_permission' => AccessControlService::FDM_CHECK_IN]
    )]
    public function checkInAppointment(int $id, Request $request): Response
    {
        $return = $request->request->getString('return');
        $redirect = match ($return) {
            'show' => $this->redirectToRoute('fdm_appointment_show', ['id' => $id]),
            'list' => $this->redirectAfterAppointmentAction($id, $request),
            default => $this->redirectToRoute('fdm_check_in'),
        };

        if (!$this->isCsrfTokenValid('fdm_check_in_' . $id, $request->request->getString('_token'))) {
            $this->addFlash('error', self::MESSAGE_CSRF_ERROR);

            return $redirect;
        }

        try {
            $result = $this->fdmService->checkInAppointment($id, $this->currentUsername());
        } catch (DbalException $exception) {
            $this->logger->error('Check-in failed.', ['exception' => $exception, 'appointment_id' => $id]);
            $this->addFlash('error', self::MESSAGE_GENERIC_ERROR);

            return $redirect;
        }

        $this->addFlash($result['success'] ? 'success' : 'error', $this->checkInMessage($result));

        return $redirect;
    }

    #[Route(
        '/fdm/check-in/walk-in',
        name: 'fdm_walk_in',
        methods: ['POST'],
        defaults: ['_permission' => AccessControlService::FDM_CHECK_IN]
    )]
    public function walkIn(Request $request): Response
    {
        $patientId = $this->digitsOrNull($request->request->getString('patient_id'));
        $doctorId = $this->digitsOrNull($request->request->getString('doctor_id'));

        // On any failure the walk-in form is shown again with the same patient selected.
        $retry = $this->redirectToRoute('fdm_check_in', $patientId !== null ? ['walkin_patient_id' => $patientId] : []);

        if (!$this->isCsrfTokenValid('fdm_walk_in', $request->request->getString('_token'))) {
            $this->addFlash('error', self::MESSAGE_CSRF_ERROR);

            return $retry;
        }

        try {
            $result = $this->fdmService->checkInWalkIn((int) $patientId, (int) $doctorId, $this->currentUsername());
        } catch (DbalException $exception) {
            $this->logger->error('Walk-in check-in failed.', ['exception' => $exception]);
            $this->addFlash('error', self::MESSAGE_GENERIC_ERROR);

            return $retry;
        }

        if ($result['success']) {
            $this->addFlash('success', $this->checkInMessage($result));

            return $this->redirectToRoute('fdm_check_in');
        }

        $message = $result['errors'] !== [] ? implode('. ', $result['errors']) : $result['message'];
        $this->addFlash('error', $message);

        return $retry;
    }

    // ------------------------------------------------------------------
    // Queue
    // ------------------------------------------------------------------

    #[Route(
        '/fdm/queue',
        name: 'fdm_queue',
        methods: ['GET'],
        defaults: ['_permission' => AccessControlService::FDM_VIEW]
    )]
    public function queue(Request $request): Response
    {
        $query = $request->query;

        try {
            $queue = $this->fdmService->getQueue(
                $query->has('date') ? $query->getString('date') : null,
                $query->getString('doctor_id'),
                $query->getString('status'),
                $query->getString('search'),
                $query->getInt('page', 1)
            );
        } catch (DbalException $exception) {
            $this->logger->error('Queue could not be loaded.', ['exception' => $exception]);
            $this->addFlash('error', self::MESSAGE_GENERIC_ERROR);

            $queue = new QueueListDTO([], 0, 1, FDMService::QUEUE_PER_PAGE, 1, null, null, 'open', null, '');
        }

        return $this->render('fdm/queue.html.twig', [
            'queue' => $queue,
            'doctors' => $this->loadDoctors(),
            'statuses' => FDMService::QUEUE_STATUS_FILTERS,
        ]);
    }

    #[Route(
        '/fdm/queue/{id}/status',
        name: 'fdm_queue_status',
        requirements: ['id' => '\d{1,9}'],
        methods: ['POST'],
        defaults: ['_permission' => AccessControlService::FDM_QUEUE_UPDATE]
    )]
    public function updateQueueStatus(int $id, Request $request): Response
    {
        $redirect = $this->redirectToQueue($request);

        if (!$this->isCsrfTokenValid('fdm_queue_' . $id, $request->request->getString('_token'))) {
            $this->addFlash('error', self::MESSAGE_CSRF_ERROR);

            return $redirect;
        }

        try {
            $result = $this->fdmService->updateQueueStatus(
                $id,
                $request->request->getString('status'),
                $this->currentUsername(),
                $request->request->getString('reason')
            );
        } catch (DbalException $exception) {
            $this->logger->error('Queue status change failed.', ['exception' => $exception, 'queue_id' => $id]);
            $this->addFlash('error', self::MESSAGE_GENERIC_ERROR);

            return $redirect;
        }

        $this->addFlash($result['success'] ? 'success' : 'error', $result['message']);

        return $redirect;
    }

    // ------------------------------------------------------------------
    // Reports
    // ------------------------------------------------------------------

    #[Route(
        '/fdm/reports',
        name: 'fdm_reports',
        methods: ['GET'],
        defaults: ['_permission' => AccessControlService::FDM_REPORTS]
    )]
    public function reports(Request $request): Response
    {
        $query = $request->query;

        try {
            $report = $this->fdmService->getReport(
                $query->getString('date_from'),
                $query->getString('date_to'),
                $query->getString('doctor_id')
            );
        } catch (DbalException $exception) {
            // Only reachable when even "today" cannot be read from the database.
            $this->logger->error('Reports could not be loaded.', ['exception' => $exception]);
            $this->addFlash('error', self::MESSAGE_GENERIC_ERROR);

            return $this->redirectToRoute('fdm_dashboard');
        }

        return $this->render('fdm/reports.html.twig', [
            'report' => $report,
            'doctors' => $this->loadDoctors(),
        ]);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function currentUsername(): string
    {
        return $this->authSession->getUsername() ?? '';
    }

    /** Digits only, at most 9 of them (fits a PostgreSQL integer); anything else is null. */
    private function digitsOrNull(?string $value): ?int
    {
        $value = trim((string) $value);

        return $value !== '' && ctype_digit($value) && strlen($value) <= 9 && (int) $value >= 1 ? (int) $value : null;
    }

    /**
     * Active doctors for the dropdowns. A failure (database down, or a doctor
     * row that DoctorDTO cannot represent, such as a NULL name) must not take
     * the whole page down: show a message and an empty list instead.
     *
     * @return list<array{id: int, name: string, specialization: string}>
     */
    private function loadDoctors(): array
    {
        try {
            return $this->fdmService->getActiveDoctors();
        } catch (DbalException|\TypeError $exception) {
            $this->logger->error('Doctors could not be loaded.', ['exception' => $exception]);
            $this->addFlash('error', 'The list of doctors could not be loaded. Please try again.');

            return [];
        }
    }

    private function loadToday(): string
    {
        try {
            return $this->fdmService->getToday();
        } catch (DbalException $exception) {
            $this->logger->error('Hospital date could not be read.', ['exception' => $exception]);

            return '';
        }
    }

    private function loadPatient(?int $patientId): ?PatientDTO
    {
        if ($patientId === null) {
            return null;
        }

        try {
            $patient = $this->fdmService->getPatient($patientId);
        } catch (DbalException $exception) {
            $this->logger->error('Patient could not be loaded.', ['exception' => $exception, 'patient_id' => $patientId]);
            $this->addFlash('error', self::MESSAGE_GENERIC_ERROR);

            return null;
        }

        // Only active patients can be given appointments or checked in.
        return $patient !== null && $patient->isActive ? $patient : null;
    }

    private function searchPatients(string $search, int $page): ?PatientListDTO
    {
        try {
            return $this->fdmService->searchPatients($search, $page);
        } catch (DbalException $exception) {
            $this->logger->error('Patient search failed.', ['exception' => $exception]);
            $this->addFlash('error', self::MESSAGE_GENERIC_ERROR);

            return null;
        }
    }

    /**
     * Returns the appointment, or a redirect response (with a flash message)
     * when it does not exist or the database fails.
     */
    private function loadAppointment(int $id): AppointmentDTO|RedirectResponse
    {
        try {
            $appointment = $this->fdmService->getAppointment($id);
        } catch (DbalException $exception) {
            $this->logger->error('Appointment could not be loaded.', ['exception' => $exception, 'appointment_id' => $id]);
            $this->addFlash('error', self::MESSAGE_GENERIC_ERROR);

            return $this->redirectToRoute('fdm_appointment_list');
        }

        if ($appointment === null) {
            $this->addFlash('error', self::MESSAGE_NOT_FOUND);

            return $this->redirectToRoute('fdm_appointment_list');
        }

        return $appointment;
    }

    /** "Appointment APT-... checked in. Queue token: #7" (the database generated both numbers). */
    private function checkInMessage(array $result): string
    {
        if ($result['success'] && $result['token_number'] !== null) {
            return sprintf('%s. Queue token: #%d', $result['message'], $result['token_number']);
        }
        if (!$result['success'] && $result['token_number'] !== null) {
            return sprintf('%s (queue token #%d)', $result['message'], $result['token_number']);
        }

        return $result['message'];
    }

    /**
     * Back to the details page, to the check-in page, or to the appointment list
     * with the same filters the user was looking at. Values go through the
     * router (so they are URL-encoded), never into a raw Location header.
     */
    private function redirectAfterAppointmentAction(int $id, Request $request): RedirectResponse
    {
        $return = $request->request->getString('return');
        if ($return === 'show') {
            return $this->redirectToRoute('fdm_appointment_show', ['id' => $id]);
        }
        if ($return === 'checkin') {
            return $this->redirectToRoute('fdm_check_in');
        }

        $params = [];
        foreach (['date_from', 'date_to', 'doctor_id', 'patient_id', 'status', 'search'] as $key) {
            // A key that is present but empty must stay (it means "no limit"); only absent keys are skipped.
            if ($request->request->has($key)) {
                $params[$key] = $request->request->getString($key);
            }
        }
        $page = $request->request->getInt('page', 1);
        if ($page > 1) {
            $params['page'] = $page;
        }

        return $this->redirectToRoute('fdm_appointment_list', $params);
    }

    /** Back to the queue with the filters the user was looking at. */
    private function redirectToQueue(Request $request): RedirectResponse
    {
        $params = [];
        foreach (['date', 'doctor_id', 'status', 'search'] as $key) {
            if ($request->request->has($key)) {
                $params[$key] = $request->request->getString($key);
            }
        }
        $page = $request->request->getInt('page', 1);
        if ($page > 1) {
            $params['page'] = $page;
        }

        return $this->redirectToRoute('fdm_queue', $params);
    }

    /**
     * Shows the form again after a failed save:
     *  - validation errors or a field-level refusal -> 422 with per-field messages
     *  - any other refusal                          -> 409 with the database's message
     *
     * @param array<string, mixed> $result the service result
     */
    private function renderFailedSubmission(
        string $mode,
        AppointmentDTO $appointment,
        ?PatientDTO $patient,
        ?PatientListDTO $patients,
        array $result
    ): Response {
        $status = $result['errors'] !== [] ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_CONFLICT;

        return $this->renderAppointmentForm($mode, $appointment, $patient, $patients, '', $result['errors'], $status, $result['message']);
    }

    /**
     * @param array<string, string> $errors
     */
    private function renderAppointmentForm(
        string $mode,
        AppointmentDTO $appointment,
        ?PatientDTO $patient,
        ?PatientListDTO $patients,
        string $patientSearch = '',
        array $errors = [],
        int $status = Response::HTTP_OK,
        ?string $formError = null
    ): Response {
        return $this->render('fdm/appointment_form.html.twig', [
            'mode' => $mode,
            'appointment' => $appointment,
            'patient' => $patient,
            'patients' => $patients,
            'patient_search' => $patientSearch,
            'doctors' => $this->loadDoctors(),
            'errors' => $errors,
            'form_error' => $formError,
            'today' => $this->loadToday(),
        ], new Response('', $status));
    }
}