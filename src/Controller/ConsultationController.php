<?php

namespace App\Controller;

use App\Common\Input;
use App\DTO\ConsultationDTO;
use App\DTO\PagedListDTO;
use App\DTO\PatientDTO;
use App\DTO\PatientListDTO;
use App\Service\AccessControlService;
use App\Service\AuthSessionService;
use App\Service\ConsultationService;
use Doctrine\DBAL\Exception as DbalException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * HTTP layer for OP Consultation.
 *
 * Authentication is enforced for every route by AuthenticationListener. Each
 * route also declares the permission it needs (defaults: _permission).
 *
 * Every state change is a POST protected by a CSRF token. Token ids:
 *   opc_start_queue_{id}, opc_start_patient, opc_save_{id}
 *
 * Database failures are caught, logged and shown as a generic message; no SQL
 * error text reaches the user.
 */
class ConsultationController extends AbstractController
{
    private const MESSAGE_GENERIC_ERROR = 'Something went wrong while accessing consultation records. Please try again.';
    private const MESSAGE_CSRF_ERROR = 'Your session has expired. Please try again.';
    private const MESSAGE_NOT_FOUND = 'Consultation not found';

    public function __construct(
        private ConsultationService $consultationService,
        private AuthSessionService $authSession,
        private LoggerInterface $logger
    ) {
    }

    // ------------------------------------------------------------------
    // Dashboard: today's worklist + start a consultation for a patient
    // ------------------------------------------------------------------

    #[Route(
        '/op-consultation',
        name: 'opc_dashboard',
        methods: ['GET'],
        defaults: ['_permission' => AccessControlService::OPC_VIEW]
    )]
    public function dashboard(Request $request): Response
    {
        $query = $request->query;

        try {
            $worklist = $this->consultationService->getWorklist(
                $query->getString('doctor_id'),
                $query->getString('search'),
                $query->getInt('page', 1)
            );
        } catch (DbalException $exception) {
            $this->logger->error('OP worklist could not be loaded.', ['exception' => $exception]);
            $this->addFlash('error', self::MESSAGE_GENERIC_ERROR);

            $worklist = PagedListDTO::empty(ConsultationService::WORKLIST_PER_PAGE, ['doctor_id' => null, 'search' => null]);
        }

        // "Start for a patient": a patient is either chosen already or being searched for.
        $patient = $this->loadActivePatient(Input::positiveInt($query->getString('patient_id')));
        $patientSearch = $query->getString('q');
        $patients = null;
        if ($patient === null && trim($patientSearch) !== '') {
            $patients = $this->searchPatients($patientSearch, $query->getInt('qpage', 1));
        }

        return $this->render('opc/index.html.twig', [
            'worklist' => $worklist,
            'doctors' => $this->loadDoctors(),
            'patient' => $patient,
            'patients' => $patients,
            'patient_search' => $patientSearch,
        ]);
    }

    // ------------------------------------------------------------------
    // Consultation list
    // ------------------------------------------------------------------

    #[Route(
        '/op-consultation/consultations',
        name: 'opc_list',
        methods: ['GET'],
        defaults: ['_permission' => AccessControlService::OPC_VIEW]
    )]
    public function consultations(Request $request): Response
    {
        $query = $request->query;

        try {
            $list = $this->consultationService->getConsultations(
                $query->getString('status'),
                $query->getString('doctor_id'),
                $query->getString('search'),
                $query->getString('date_from'),
                $query->getString('date_to'),
                $query->getInt('page', 1)
            );
        } catch (DbalException $exception) {
            $this->logger->error('Consultation list could not be loaded.', ['exception' => $exception]);
            $this->addFlash('error', self::MESSAGE_GENERIC_ERROR);

            $list = PagedListDTO::empty(ConsultationService::LIST_PER_PAGE, [
                'status' => 'all', 'doctor_id' => null, 'search' => null, 'date_from' => null, 'date_to' => null,
            ]);
        }

        return $this->render('opc/consultations.html.twig', [
            'list' => $list,
            'doctors' => $this->loadDoctors(),
            'statuses' => ConsultationService::STATUS_FILTERS,
        ]);
    }

    // ------------------------------------------------------------------
    // Start (POST only)
    // ------------------------------------------------------------------

    #[Route(
        '/op-consultation/start/queue/{id}',
        name: 'opc_start_queue',
        requirements: ['id' => '\d{1,9}'],
        methods: ['POST'],
        defaults: ['_permission' => AccessControlService::OPC_MANAGE]
    )]
    public function startFromQueue(int $id, Request $request): Response
    {
        $back = $this->redirectToRoute('opc_dashboard');

        if (!$this->isCsrfTokenValid('opc_start_queue_' . $id, $request->request->getString('_token'))) {
            $this->addFlash('error', self::MESSAGE_CSRF_ERROR);

            return $back;
        }

        try {
            $result = $this->consultationService->startFromQueue($id, $this->currentUsername());
        } catch (DbalException $exception) {
            $this->logger->error('Starting a consultation failed.', ['exception' => $exception, 'queue_id' => $id]);
            $this->addFlash('error', self::MESSAGE_GENERIC_ERROR);

            return $back;
        }

        return $this->afterStart($result, $back);
    }

    #[Route(
        '/op-consultation/start/patient',
        name: 'opc_start_patient',
        methods: ['POST'],
        defaults: ['_permission' => AccessControlService::OPC_MANAGE]
    )]
    public function startForPatient(Request $request): Response
    {
        $patientId = Input::positiveInt($request->request->getString('patient_id'));
        $doctorId = Input::positiveInt($request->request->getString('doctor_id'));

        // On any failure the page is shown again with the same patient selected.
        $back = $this->redirectToRoute('opc_dashboard', $patientId !== null ? ['patient_id' => $patientId] : []);

        if (!$this->isCsrfTokenValid('opc_start_patient', $request->request->getString('_token'))) {
            $this->addFlash('error', self::MESSAGE_CSRF_ERROR);

            return $back;
        }

        try {
            $result = $this->consultationService->startForPatient((int) $patientId, (int) $doctorId, $this->currentUsername());
        } catch (DbalException $exception) {
            $this->logger->error('Starting a consultation for a patient failed.', ['exception' => $exception]);
            $this->addFlash('error', self::MESSAGE_GENERIC_ERROR);

            return $back;
        }

        if (!$result['success'] && $result['errors'] !== []) {
            $result['message'] = implode('. ', $result['errors']);
        }

        return $this->afterStart($result, $back);
    }

    // ------------------------------------------------------------------
    // Details, save and send to billing
    // ------------------------------------------------------------------

    #[Route(
        '/op-consultation/{id}',
        name: 'opc_show',
        requirements: ['id' => '\d{1,9}'],
        methods: ['GET'],
        defaults: ['_permission' => AccessControlService::OPC_VIEW]
    )]
    public function show(int $id): Response
    {
        $consultation = $this->loadConsultation($id);
        if ($consultation instanceof Response) {
            return $consultation;
        }

        return $this->renderConsultation($consultation);
    }

    /**
     * One POST handles both buttons of the form: action=save keeps the
     * consultation In Progress, action=send also marks it Ready for Billing.
     */
    #[Route(
        '/op-consultation/{id}/save',
        name: 'opc_save',
        requirements: ['id' => '\d{1,9}'],
        methods: ['POST'],
        defaults: ['_permission' => AccessControlService::OPC_MANAGE]
    )]
    public function save(int $id, Request $request): Response
    {
        $consultation = $this->loadConsultation($id);
        if ($consultation instanceof Response) {
            return $consultation;
        }

        // Show what the user typed again if anything goes wrong.
        $typed = ConsultationDTO::fromInput($request->request->all());
        $consultation->chiefComplaint = $typed->chiefComplaint;
        $consultation->diagnosis = $typed->diagnosis;
        $consultation->advice = $typed->advice;
        $consultation->notes = $typed->notes;

        if (!$this->isCsrfTokenValid('opc_save_' . $id, $request->request->getString('_token'))) {
            return $this->renderConsultation($consultation, [], Response::HTTP_BAD_REQUEST, self::MESSAGE_CSRF_ERROR);
        }

        $send = $request->request->getString('action') === 'send';

        try {
            $result = $this->consultationService->saveConsultation($id, $typed, $send, $this->currentUsername());
        } catch (DbalException $exception) {
            $this->logger->error('Saving a consultation failed.', ['exception' => $exception, 'consultation_id' => $id]);

            return $this->renderConsultation($consultation, [], Response::HTTP_SERVICE_UNAVAILABLE, self::MESSAGE_GENERIC_ERROR);
        }

        if ($result['success']) {
            $this->addFlash('success', $result['message']);

            return $this->redirectToRoute('opc_show', ['id' => $id]);
        }

        $status = $result['errors'] !== [] ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_CONFLICT;

        return $this->renderConsultation($consultation, $result['errors'], $status, $result['message']);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function currentUsername(): string
    {
        return $this->authSession->getUsername() ?? '';
    }

    /**
     * Success (including "already started") goes to the consultation; a refusal
     * goes back with the database's message.
     *
     * @param array<string, mixed> $result the service result
     */
    private function afterStart(array $result, RedirectResponse $back): Response
    {
        if ($result['consultation_id'] !== null && $result['success']) {
            $this->addFlash('success', sprintf('%s. Consultation number: %s', $result['message'], $result['consultation_code']));

            return $this->redirectToRoute('opc_show', ['id' => $result['consultation_id']]);
        }

        $this->addFlash('error', $result['message']);

        return $back;
    }

    /**
     * Active doctors for the dropdowns. A failure (database down, or a doctor
     * row that DoctorDTO cannot represent) must not take the page down.
     *
     * @return list<array{id: int, name: string, specialization: string}>
     */
    private function loadDoctors(): array
    {
        try {
            return $this->consultationService->getActiveDoctors();
        } catch (DbalException|\TypeError $exception) {
            $this->logger->error('Doctors could not be loaded.', ['exception' => $exception]);
            $this->addFlash('error', 'The list of doctors could not be loaded. Please try again.');

            return [];
        }
    }

    /** The patient, only when active (inactive patients cannot be consulted). */
    private function loadActivePatient(?int $patientId): ?PatientDTO
    {
        if ($patientId === null) {
            return null;
        }

        try {
            $patient = $this->consultationService->getPatient($patientId);
        } catch (DbalException $exception) {
            $this->logger->error('Patient could not be loaded.', ['exception' => $exception, 'patient_id' => $patientId]);
            $this->addFlash('error', self::MESSAGE_GENERIC_ERROR);

            return null;
        }

        return $patient !== null && $patient->isActive ? $patient : null;
    }

    private function searchPatients(string $search, int $page): ?PatientListDTO
    {
        try {
            return $this->consultationService->searchPatients($search, $page);
        } catch (DbalException $exception) {
            $this->logger->error('Patient search failed.', ['exception' => $exception]);
            $this->addFlash('error', self::MESSAGE_GENERIC_ERROR);

            return null;
        }
    }

    /** The consultation, or a redirect (with a flash message) when it does not exist or the database fails. */
    private function loadConsultation(int $id): ConsultationDTO|RedirectResponse
    {
        try {
            $consultation = $this->consultationService->getConsultation($id);
        } catch (DbalException $exception) {
            $this->logger->error('Consultation could not be loaded.', ['exception' => $exception, 'consultation_id' => $id]);
            $this->addFlash('error', self::MESSAGE_GENERIC_ERROR);

            return $this->redirectToRoute('opc_dashboard');
        }

        if ($consultation === null) {
            $this->addFlash('error', self::MESSAGE_NOT_FOUND);

            return $this->redirectToRoute('opc_dashboard');
        }

        return $consultation;
    }

    /**
     * @param array<string, string> $errors
     */
    private function renderConsultation(
        ConsultationDTO $consultation,
        array $errors = [],
        int $status = Response::HTTP_OK,
        ?string $formError = null
    ): Response {
        return $this->render('opc/show.html.twig', [
            'consultation' => $consultation,
            'errors' => $errors,
            'form_error' => $formError,
        ], new Response('', $status));
    }
}