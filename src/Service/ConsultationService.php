<?php

namespace App\Service;

use App\Common\Input;
use App\DTO\ConsultationDTO;
use App\DTO\PagedListDTO;
use App\DTO\PatientDTO;
use App\DTO\PatientListDTO;
use App\Repository\ConsultationRepository;

/**
 * Business logic for OP Consultation.
 *
 * - Reuses PatientService for patient search/lookup and FDMService for the
 *   active doctors and the hospital's "today": it never creates patients or
 *   appointments. A consultation always starts from an EXISTING queue entry
 *   (a checked-in appointment or a walk-in) or from an existing patient, and
 *   PostgreSQL guarantees one consultation per queue entry.
 * - Cleans and validates the clinical text before it reaches the database
 *   (the database enforces the same limits and the billing rule).
 * - Never changes an appointment's status: finalization belongs to RCM.
 *
 * Write operations return one array shape:
 *
 *   [
 *     'success'           => bool,
 *     'message'           => string,                  // safe to show to the user
 *     'errors'            => array<string, string>,   // fieldName => message
 *     'consultation_id'   => ?int,
 *     'consultation_code' => ?string,
 *     'status'            => ?string,                 // consultation status after a save
 *   ]
 */
class ConsultationService
{
    public const STATUSES = ['In Progress', 'Ready for Billing', 'Completed'];
    public const STATUS_FILTERS = ['all', 'In Progress', 'Ready for Billing', 'Completed'];

    public const WORKLIST_PER_PAGE = 20;
    public const LIST_PER_PAGE = 10;

    private const MAX_CHIEF_COMPLAINT = 500;
    private const MAX_LONG_TEXT = 1000;
    private const MAX_NOTES = 2000;

    private const MESSAGE_VALIDATION = 'Please correct the errors below';
    private const MESSAGE_NOT_FOUND = 'Consultation not found';
    private const MESSAGE_NOT_SIGNED_IN = 'You must be signed in to perform this action';

    public function __construct(
        private ConsultationRepository $consultationRepository,
        private PatientService $patientService,
        private FDMService $fdmService,
    ) {
    }

    // ------------------------------------------------------------------
    // Lookups
    // ------------------------------------------------------------------

    /** @return list<array{id: int, name: string, specialization: string}> */
    public function getActiveDoctors(): array
    {
        return $this->fdmService->getActiveDoctors();
    }

    /** Active patients matching a search (patient ID, name or phone), 10 per page. */
    public function searchPatients(?string $search, int $page = 1): PatientListDTO
    {
        return $this->patientService->getPatientsList($search, PatientService::STATUS_ACTIVE, $page);
    }

    public function getPatient(int $patientId): ?PatientDTO
    {
        return $this->patientService->getPatient($patientId);
    }

    public function getConsultation(int $consultationId): ?ConsultationDTO
    {
        if ($consultationId < 1) {
            return null;
        }

        return $this->consultationRepository->getConsultationById($consultationId);
    }

    // ------------------------------------------------------------------
    // Lists
    // ------------------------------------------------------------------

    /**
     * Today's open queue entries (Waiting / In Consultation), 20 per page.
     * Filters: doctor_id, search.
     */
    public function getWorklist(?string $doctorId, ?string $search, int $page = 1): PagedListDTO
    {
        $doctor = Input::positiveInt($doctorId);
        $search = Input::search($search);

        $total = $this->consultationRepository->countWorklist($doctor, $search);
        $totalPages = max(1, (int) ceil($total / self::WORKLIST_PER_PAGE));
        $page = min(max(1, $page), $totalPages);

        $items = $total > 0
            ? $this->consultationRepository->getWorklist($doctor, $search, self::WORKLIST_PER_PAGE, ($page - 1) * self::WORKLIST_PER_PAGE)
            : [];

        return new PagedListDTO(
            $items,
            $total,
            $page,
            self::WORKLIST_PER_PAGE,
            $totalPages,
            ['doctor_id' => $doctor, 'search' => $search],
            $this->fdmService->getToday()
        );
    }

    /**
     * Consultations, newest first, 10 per page.
     * Filters: status, doctor_id, search, date_from, date_to (all optional).
     */
    public function getConsultations(
        ?string $status,
        ?string $doctorId,
        ?string $search,
        ?string $dateFrom,
        ?string $dateTo,
        int $page = 1,
    ): PagedListDTO {
        $status = Input::whitelist($status, self::STATUS_FILTERS, 'all');
        $doctor = Input::positiveInt($doctorId);
        $search = Input::search($search);
        $from = Input::dateOrNull($dateFrom);
        $to = Input::dateOrNull($dateTo);
        if (null !== $from && null !== $to && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        $total = $this->consultationRepository->countConsultations($status, $doctor, $search, $from, $to);
        $totalPages = max(1, (int) ceil($total / self::LIST_PER_PAGE));
        $page = min(max(1, $page), $totalPages);

        $items = $total > 0
            ? $this->consultationRepository->getConsultationsList($status, $doctor, $search, $from, $to, self::LIST_PER_PAGE, ($page - 1) * self::LIST_PER_PAGE)
            : [];

        return new PagedListDTO(
            $items,
            $total,
            $page,
            self::LIST_PER_PAGE,
            $totalPages,
            ['status' => $status, 'doctor_id' => $doctor, 'search' => $search, 'date_from' => $from, 'date_to' => $to],
            $this->fdmService->getToday()
        );
    }

    // ------------------------------------------------------------------
    // Starting a consultation
    // ------------------------------------------------------------------

    /** From a queue entry on today's worklist. Safe to repeat: the same consultation is returned. */
    public function startFromQueue(int $queueId, string $performedBy): array
    {
        if ($queueId < 1) {
            return $this->failure('Queue entry not found');
        }
        $performedBy = trim($performedBy);
        if ('' === $performedBy) {
            return $this->failure(self::MESSAGE_NOT_SIGNED_IN);
        }

        return $this->startResult($this->consultationRepository->startConsultation($queueId, $performedBy));
    }

    /** For a registered patient (Patients -> OP Consultation). No patient or appointment is created. */
    public function startForPatient(int $patientId, int $doctorId, string $performedBy): array
    {
        $errors = [];
        if ($patientId < 1) {
            $errors['patientId'] = 'Select a patient';
        }
        if ($doctorId < 1) {
            $errors['doctorId'] = 'Select a doctor';
        }
        if ([] !== $errors) {
            return $this->failure(self::MESSAGE_VALIDATION, $errors);
        }
        $performedBy = trim($performedBy);
        if ('' === $performedBy) {
            return $this->failure(self::MESSAGE_NOT_SIGNED_IN);
        }

        return $this->startResult($this->consultationRepository->startConsultationForPatient($patientId, $doctorId, $performedBy));
    }

    // ------------------------------------------------------------------
    // Saving
    // ------------------------------------------------------------------

    /**
     * Saves the clinical text of a consultation in progress. With
     * $sendToBilling the consultation also becomes Ready for Billing, which
     * needs a chief complaint and a diagnosis.
     */
    public function saveConsultation(int $consultationId, ConsultationDTO $input, bool $sendToBilling, string $performedBy): array
    {
        if ($consultationId < 1) {
            return $this->failure(self::MESSAGE_NOT_FOUND);
        }
        $performedBy = trim($performedBy);
        if ('' === $performedBy) {
            return $this->failure(self::MESSAGE_NOT_SIGNED_IN);
        }

        $consultation = $this->normalize($input);
        $errors = $this->validate($consultation, $sendToBilling);
        if ([] !== $errors) {
            return $this->failure(self::MESSAGE_VALIDATION, $errors);
        }

        $db = $this->consultationRepository->saveConsultation($consultationId, $consultation, $sendToBilling, $performedBy);

        $result = $this->failure((string) ($db['message'] ?? ''));
        $result['success'] = Input::isTrue($db['success'] ?? false);
        $result['consultation_id'] = $consultationId;
        $result['status'] = isset($db['status']) ? (string) $db['status'] : null;
        if (!$result['success']) {
            $field = $this->fieldForMessage($result['message']);
            if (null !== $field) {
                $result['errors'][$field] = $result['message'];
            }
        }

        return $result;
    }

    // ------------------------------------------------------------------
    // Normalisation and validation
    // ------------------------------------------------------------------

    /** A cleaned COPY of the clinical fields; the caller's DTO (what the user typed) is untouched. */
    private function normalize(ConsultationDTO $input): ConsultationDTO
    {
        $consultation = new ConsultationDTO();
        $consultation->chiefComplaint = Input::nullIfEmpty(Input::clean($input->chiefComplaint));
        $consultation->diagnosis = Input::nullIfEmpty(Input::cleanMultiline($input->diagnosis));
        $consultation->advice = Input::nullIfEmpty(Input::cleanMultiline($input->advice));
        $consultation->notes = Input::nullIfEmpty(Input::cleanMultiline($input->notes));

        return $consultation;
    }

    /** @return array<string, string> */
    private function validate(ConsultationDTO $consultation, bool $sendToBilling): array
    {
        $errors = [];

        if (null !== $consultation->chiefComplaint && mb_strlen($consultation->chiefComplaint) > self::MAX_CHIEF_COMPLAINT) {
            $errors['chiefComplaint'] = 'Chief complaint must be '.self::MAX_CHIEF_COMPLAINT.' characters or fewer';
        }
        if (null !== $consultation->diagnosis && mb_strlen($consultation->diagnosis) > self::MAX_LONG_TEXT) {
            $errors['diagnosis'] = 'Diagnosis must be '.self::MAX_LONG_TEXT.' characters or fewer';
        }
        if (null !== $consultation->advice && mb_strlen($consultation->advice) > self::MAX_LONG_TEXT) {
            $errors['advice'] = 'Advice must be '.self::MAX_LONG_TEXT.' characters or fewer';
        }
        if (null !== $consultation->notes && mb_strlen($consultation->notes) > self::MAX_NOTES) {
            $errors['notes'] = 'Notes must be '.self::MAX_NOTES.' characters or fewer';
        }

        if ($sendToBilling) {
            if (null === $consultation->chiefComplaint) {
                $errors['chiefComplaint'] ??= 'A chief complaint is required before sending to billing';
            }
            if (null === $consultation->diagnosis) {
                $errors['diagnosis'] ??= 'A diagnosis is required before sending to billing';
            }
        }

        return $errors;
    }

    // ------------------------------------------------------------------
    // Result building
    // ------------------------------------------------------------------

    private function startResult(array $db): array
    {
        $result = $this->failure((string) ($db['message'] ?? ''));
        $result['success'] = Input::isTrue($db['success'] ?? false);
        $result['consultation_id'] = isset($db['consultation_id']) ? (int) $db['consultation_id'] : null;
        $result['consultation_code'] = isset($db['consultation_code']) ? (string) $db['consultation_code'] : null;

        return $result;
    }

    private function fieldForMessage(string $message): ?string
    {
        $map = [
            'Chief complaint must' => 'chiefComplaint',
            'A chief complaint and a diagnosis' => 'diagnosis',
            'Diagnosis and advice must' => 'diagnosis',
            'Notes must' => 'notes',
        ];
        foreach ($map as $prefix => $field) {
            if (str_starts_with($message, $prefix)) {
                return $field;
            }
        }

        return null;
    }

    /**
     * The uniform result shape, pre-filled as a failure.
     *
     * @param array<string, string> $errors
     */
    private function failure(string $message, array $errors = []): array
    {
        return [
            'success' => false,
            'message' => $message,
            'errors' => $errors,
            'consultation_id' => null,
            'consultation_code' => null,
            'status' => null,
        ];
    }
}
