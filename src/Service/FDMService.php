<?php

namespace App\Service;

use App\DTO\AppointmentDTO;
use App\DTO\AppointmentListDTO;
use App\DTO\FDMDashboardDTO;
use App\DTO\FDMReportDTO;
use App\DTO\PatientDTO;
use App\DTO\PatientListDTO;
use App\DTO\QueueListDTO;
use App\Repository\FDMRepository;
use Doctrine\DBAL\Exception as DbalException;
use Psr\Log\LoggerInterface;

/**
 * Business logic for the Front Desk module.
 *
 * - Cleans and validates form input BEFORE it reaches the database. The rules
 *   are the same as, or a subset of, the ones PostgreSQL enforces (the database
 *   is the final authority and also owns the rules that depend on the clock or
 *   on other rows: past dates, overlaps, duplicate check-ins, status moves).
 * - Whitelists every list filter (the SQL functions return nothing for an
 *   unknown status, so user input is never passed through unchecked).
 * - Works out pagination and the default filters.
 * - Reuses PatientService (patient search and lookup) and DoctorService
 *   (the active doctors); it never touches the session or the database itself.
 *   The acting username is passed in by the controller.
 *
 * Write operations return one array shape (like PatientService):
 *
 *   [
 *     'success'          => bool,
 *     'message'          => string,                  // safe to show to the user
 *     'errors'           => array<string, string>,   // fieldName => message
 *     'appointment_id'   => ?int,
 *     'appointment_code' => ?string,
 *     'queue_id'         => ?int,
 *     'token_number'     => ?int,
 *   ]
 *
 * Field names in 'errors' are AppointmentDTO property names, plus 'reason',
 * 'patientId' and 'doctorId'.
 */
class FDMService
{
    public const APPOINTMENT_STATUSES = ['Scheduled', 'Checked-In', 'Completed', 'Cancelled', 'No-Show'];
    public const APPOINTMENT_STATUS_FILTERS = ['all', 'Scheduled', 'Checked-In', 'Completed', 'Cancelled', 'No-Show'];
    public const QUEUE_STATUS_FILTERS = ['open', 'all', 'Waiting', 'In Consultation', 'Completed', 'Cancelled'];
    /** The statuses a queue entry can be moved TO (Waiting is only ever the starting status). */
    public const QUEUE_ACTIONS = ['In Consultation', 'Completed', 'Cancelled'];

    public const DEFAULT_APPOINTMENT_STATUS = 'all';
    public const DEFAULT_QUEUE_STATUS = 'open';

    public const APPOINTMENTS_PER_PAGE = 10;
    public const QUEUE_PER_PAGE = 20;
    public const MAX_SEARCH_LENGTH = 100;
    public const REPORT_DEFAULT_DAYS = 7;
    public const REPORT_MAX_DAYS = 366;

    private const MAX_REASON_LENGTH = 255;

    private const MESSAGE_VALIDATION = 'Please correct the errors below';
    private const MESSAGE_NOT_FOUND = 'Appointment not found';
    private const MESSAGE_QUEUE_NOT_FOUND = 'Queue entry not found';
    private const MESSAGE_NOT_SIGNED_IN = 'You must be signed in to perform this action';

    public function __construct(
        private FDMRepository $fdmRepository,
        private PatientService $patientService,
        private DoctorService $doctorService,
        private LoggerInterface $logger,
    ) {
    }

    // ------------------------------------------------------------------
    // Lookups
    // ------------------------------------------------------------------

    /** The hospital's current day ('Y-m-d'), decided by the database. */
    public function getToday(): string
    {
        return $this->fdmRepository->getHospitalToday();
    }

    public function getDashboard(): FDMDashboardDTO
    {
        return $this->fdmRepository->getDashboardSummary(null);
    }

    /**
     * Active doctors from the Doctor Module, as plain arrays for the templates.
     *
     * @return list<array{id: int, name: string, specialization: string}>
     */
    public function getActiveDoctors(): array
    {
        $doctors = [];
        foreach ($this->doctorService->getDoctorsList() as $doctor) {
            $doctors[] = [
                'id' => $doctor->getDoctorId(),
                'name' => $doctor->getDoctorName(),
                'specialization' => $doctor->getSpecialization(),
            ];
        }

        usort(
            $doctors,
            static fn (array $a, array $b): int => [mb_strtolower($a['name']), $a['id']] <=> [mb_strtolower($b['name']), $b['id']]
        );

        return $doctors;
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

    public function getAppointment(int $appointmentId): ?AppointmentDTO
    {
        if ($appointmentId < 1) {
            return null;
        }

        return $this->fdmRepository->getAppointmentById($appointmentId);
    }

    // ------------------------------------------------------------------
    // Appointment list
    // ------------------------------------------------------------------

    /**
     * One page of appointments. For the two date filters, null means "the
     * parameter was not sent" (default: today) and '' means "the user cleared
     * it" (no limit on that side). Bad filter values fall back to safe defaults.
     */
    public function getAppointments(
        ?string $dateFrom,
        ?string $dateTo,
        ?string $doctorId,
        ?string $patientId,
        ?string $status,
        ?string $search,
        int $page = 1,
    ): AppointmentListDTO {
        $today = $this->fdmRepository->getHospitalToday();

        $from = $this->normalizeDateFilter($dateFrom, $today);
        $to = $this->normalizeDateFilter($dateTo, $today);
        if (null !== $from && null !== $to && $from > $to) {
            [$from, $to] = [$to, $from];
        }
        $doctor = $this->positiveInt($doctorId);
        $patient = $this->positiveInt($patientId);
        $status = $this->normalizeStatus($status, self::APPOINTMENT_STATUS_FILTERS, self::DEFAULT_APPOINTMENT_STATUS);
        $search = $this->normalizeSearch($search);

        $total = $this->fdmRepository->countAppointments($from, $to, $doctor, $patient, $status, $search);
        $totalPages = max(1, (int) ceil($total / self::APPOINTMENTS_PER_PAGE));
        $page = min(max(1, $page), $totalPages);

        $appointments = [];
        if ($total > 0) {
            $appointments = $this->fdmRepository->getAppointmentsList(
                $from,
                $to,
                $doctor,
                $patient,
                $status,
                $search,
                self::APPOINTMENTS_PER_PAGE,
                ($page - 1) * self::APPOINTMENTS_PER_PAGE
            );
        }

        return new AppointmentListDTO(
            $appointments,
            $total,
            $page,
            self::APPOINTMENTS_PER_PAGE,
            $totalPages,
            $from,
            $to,
            $doctor,
            $patient,
            $status,
            $search,
            $today
        );
    }

    // ------------------------------------------------------------------
    // Appointment writes
    // ------------------------------------------------------------------

    public function createAppointment(AppointmentDTO $input, string $performedBy): array
    {
        $performedBy = trim($performedBy);
        if ('' === $performedBy) {
            return $this->failure(self::MESSAGE_NOT_SIGNED_IN);
        }

        $appointment = $this->normalizeAppointment($input);
        $errors = $this->validateAppointment($appointment, true);
        if ([] !== $errors) {
            return $this->failure(self::MESSAGE_VALIDATION, $errors);
        }

        return $this->appointmentResult($this->fdmRepository->createAppointment($appointment, $performedBy));
    }

    public function updateAppointment(int $appointmentId, AppointmentDTO $input, string $performedBy): array
    {
        if ($appointmentId < 1) {
            return $this->failure(self::MESSAGE_NOT_FOUND);
        }
        $performedBy = trim($performedBy);
        if ('' === $performedBy) {
            return $this->failure(self::MESSAGE_NOT_SIGNED_IN);
        }

        $appointment = $this->normalizeAppointment($input);
        $errors = $this->validateAppointment($appointment, false);
        if ([] !== $errors) {
            return $this->failure(self::MESSAGE_VALIDATION, $errors);
        }

        return $this->appointmentResult($this->fdmRepository->updateAppointment($appointmentId, $appointment, $performedBy));
    }

    public function cancelAppointment(int $appointmentId, ?string $reason, string $performedBy): array
    {
        if ($appointmentId < 1) {
            return $this->failure(self::MESSAGE_NOT_FOUND);
        }
        $performedBy = trim($performedBy);
        if ('' === $performedBy) {
            return $this->failure(self::MESSAGE_NOT_SIGNED_IN);
        }

        $reason = $this->nullIfEmpty($this->clean($reason));
        if (null === $reason) {
            return $this->failure('Cancellation reason is required', ['reason' => 'Cancellation reason is required']);
        }
        if (mb_strlen($reason) > self::MAX_REASON_LENGTH) {
            $message = 'Cancellation reason must be '.self::MAX_REASON_LENGTH.' characters or fewer';

            return $this->failure($message, ['reason' => $message]);
        }

        return $this->simpleResult($this->fdmRepository->cancelAppointment($appointmentId, $reason, $performedBy));
    }

    public function markNoShow(int $appointmentId, string $performedBy): array
    {
        if ($appointmentId < 1) {
            return $this->failure(self::MESSAGE_NOT_FOUND);
        }
        $performedBy = trim($performedBy);
        if ('' === $performedBy) {
            return $this->failure(self::MESSAGE_NOT_SIGNED_IN);
        }

        return $this->simpleResult($this->fdmRepository->markAppointmentNoShow($appointmentId, $performedBy));
    }

    // ------------------------------------------------------------------
    // Check-in
    // ------------------------------------------------------------------

    public function checkInAppointment(int $appointmentId, string $performedBy): array
    {
        if ($appointmentId < 1) {
            return $this->failure(self::MESSAGE_NOT_FOUND);
        }
        $performedBy = trim($performedBy);
        if ('' === $performedBy) {
            return $this->failure(self::MESSAGE_NOT_SIGNED_IN);
        }

        return $this->queueResult($this->fdmRepository->checkInAppointment($appointmentId, $performedBy));
    }

    public function checkInWalkIn(int $patientId, int $doctorId, string $performedBy): array
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

        return $this->queueResult($this->fdmRepository->checkInWalkIn($patientId, $doctorId, $performedBy));
    }

    // ------------------------------------------------------------------
    // Queue
    // ------------------------------------------------------------------

    /**
     * One page of the consultation queue. $visitDate: null = not sent (today),
     * '' = cleared (any day). The default status filter is 'open'
     * (Waiting + In Consultation).
     */
    public function getQueue(
        ?string $visitDate,
        ?string $doctorId,
        ?string $status,
        ?string $search,
        int $page = 1,
    ): QueueListDTO {
        $today = $this->fdmRepository->getHospitalToday();

        $date = $this->normalizeDateFilter($visitDate, $today);
        $doctor = $this->positiveInt($doctorId);
        $status = $this->normalizeStatus($status, self::QUEUE_STATUS_FILTERS, self::DEFAULT_QUEUE_STATUS);
        $search = $this->normalizeSearch($search);

        $total = $this->fdmRepository->countQueue($date, $doctor, $status, $search);
        $totalPages = max(1, (int) ceil($total / self::QUEUE_PER_PAGE));
        $page = min(max(1, $page), $totalPages);

        $entries = [];
        if ($total > 0) {
            $entries = $this->fdmRepository->getQueueList(
                $date,
                $doctor,
                $status,
                $search,
                self::QUEUE_PER_PAGE,
                ($page - 1) * self::QUEUE_PER_PAGE
            );
        }

        return new QueueListDTO($entries, $total, $page, self::QUEUE_PER_PAGE, $totalPages, $date, $doctor, $status, $search, $today);
    }

    /**
     * Moves a queue entry to In Consultation, Completed or Cancelled. The
     * database decides whether the move is allowed from the current status.
     * Cancelling needs a reason.
     */
    public function updateQueueStatus(int $queueId, string $newStatus, string $performedBy, ?string $reason = null): array
    {
        if ($queueId < 1) {
            return $this->failure(self::MESSAGE_QUEUE_NOT_FOUND);
        }
        if (!in_array($newStatus, self::QUEUE_ACTIONS, true)) {
            return $this->failure('Invalid queue status');
        }
        $performedBy = trim($performedBy);
        if ('' === $performedBy) {
            return $this->failure(self::MESSAGE_NOT_SIGNED_IN);
        }

        $reason = $this->nullIfEmpty($this->clean($reason));
        if ('Cancelled' === $newStatus) {
            if (null === $reason) {
                return $this->failure('Cancellation reason is required', ['reason' => 'Cancellation reason is required']);
            }
            if (mb_strlen($reason) > self::MAX_REASON_LENGTH) {
                $message = 'Cancellation reason must be '.self::MAX_REASON_LENGTH.' characters or fewer';

                return $this->failure($message, ['reason' => $message]);
            }
        } else {
            $reason = null;
        }

        return $this->simpleResult($this->fdmRepository->updateQueueStatus($queueId, $newStatus, $performedBy, $reason));
    }

    // ------------------------------------------------------------------
    // Reports
    // ------------------------------------------------------------------

    /**
     * The four reports for a date range. Defaults to the last 7 days ending
     * today. The optional doctor filter applies to the two per-doctor reports.
     * Each report is loaded on its own: a failure leaves that section empty
     * and lists it in FDMReportDTO::$unavailable.
     */
    public function getReport(?string $dateFrom, ?string $dateTo, ?string $doctorId): FDMReportDTO
    {
        $today = $this->fdmRepository->getHospitalToday();

        $to = $this->isValidDate((string) $dateTo) ? (string) $dateTo : $today;
        $from = $this->isValidDate((string) $dateFrom)
            ? (string) $dateFrom
            : (new \DateTimeImmutable($to))->modify('-'.(self::REPORT_DEFAULT_DAYS - 1).' days')->format('Y-m-d');
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        $earliest = (new \DateTimeImmutable($to))->modify('-'.(self::REPORT_MAX_DAYS - 1).' days')->format('Y-m-d');
        if ($from < $earliest) {
            $from = $earliest;
        }

        $doctor = $this->positiveInt($doctorId);
        $unavailable = [];

        $registrations = $this->loadSection('registrations', $unavailable, fn (): array => $this->fdmRepository->reportRegistrations($from, $to));
        $appointments = $this->loadSection('appointments', $unavailable, fn (): array => $this->fdmRepository->reportAppointments($from, $to));
        $cancellations = $this->loadSection('cancellations', $unavailable, fn (): array => $this->fdmRepository->reportCancellations($from, $to));
        $queue = $this->loadSection('queue', $unavailable, fn (): array => $this->fdmRepository->reportQueueSummary($from, $to));

        if (null !== $doctor) {
            $appointments = $this->filterByDoctor($appointments, $doctor);
            $queue = $this->filterByDoctor($queue, $doctor);
        }

        return new FDMReportDTO(
            $from,
            $to,
            $doctor,
            $registrations,
            $appointments,
            $cancellations,
            $queue,
            $this->sumColumns($appointments, ['total', 'scheduled', 'checked_in', 'completed', 'cancelled', 'no_show']),
            $this->sumColumns($queue, ['total', 'walk_ins', 'waiting', 'in_consultation', 'completed', 'cancelled']),
            array_sum(array_map(static fn (array $row): int => (int) ($row['registrations'] ?? 0), $registrations)),
            $unavailable
        );
    }

    // ------------------------------------------------------------------
    // Normalisation
    // ------------------------------------------------------------------

    /**
     * Returns a cleaned COPY; the caller's DTO (what the user typed) is not
     * modified, so the form can be shown again exactly as submitted.
     */
    private function normalizeAppointment(AppointmentDTO $input): AppointmentDTO
    {
        $appointment = new AppointmentDTO();

        $appointment->patientId = $input->patientId;
        $appointment->doctorId = $input->doctorId;
        $appointment->appointmentDate = $this->clean($input->appointmentDate);
        $appointment->startTime = $this->normalizeTime($this->clean($input->startTime));
        $appointment->durationInput = $this->clean($input->durationInput);
        $appointment->durationMinutes = ctype_digit($appointment->durationInput) && strlen($appointment->durationInput) <= 4
            ? (int) $appointment->durationInput
            : 0;
        $appointment->reason = $this->nullIfEmpty($this->clean($input->reason));

        return $appointment;
    }

    /** '09:30:00' -> '09:30'; anything else is returned unchanged and fails validation. */
    private function normalizeTime(string $time): string
    {
        return 1 === preg_match('/^(\d{2}:\d{2}):00$/', $time, $m) ? $m[1] : $time;
    }

    /**
     * Removes control characters, collapses any run of whitespace to a single
     * space and trims. Text that is not valid UTF-8 becomes '' (PostgreSQL
     * would reject it), which then fails the "required" or format rule.
     */
    private function clean(?string $value): string
    {
        if (null === $value || !mb_check_encoding($value, 'UTF-8')) {
            return '';
        }

        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);
        if (null === $value) {
            return '';
        }

        $value = preg_replace('/\s+/u', ' ', $value);

        return null === $value ? '' : trim($value);
    }

    private function nullIfEmpty(string $value): ?string
    {
        return '' === $value ? null : $value;
    }

    private function normalizeSearch(?string $search): ?string
    {
        if (null === $search) {
            return null;
        }

        $clean = trim(mb_substr($this->clean($search), 0, self::MAX_SEARCH_LENGTH));

        return '' === $clean ? null : $clean;
    }

    /**
     * Case-insensitive match against the allowed list; returns the canonical
     * spelling, or the default for anything unknown.
     *
     * @param list<string> $allowed
     */
    private function normalizeStatus(?string $status, array $allowed, string $default): string
    {
        $status = trim((string) $status);
        foreach ($allowed as $candidate) {
            if (0 === strcasecmp($status, $candidate)) {
                return $candidate;
            }
        }

        return $default;
    }

    /** null = not sent -> today; '' = cleared -> null (no limit); invalid -> today. */
    private function normalizeDateFilter(?string $value, string $today): ?string
    {
        if (null === $value) {
            return $today;
        }
        $value = trim($value);
        if ('' === $value) {
            return null;
        }

        return $this->isValidDate($value) ? $value : $today;
    }

    /** Digits only, 1..999999999 (fits a PostgreSQL integer); anything else is null. */
    private function positiveInt(?string $value): ?int
    {
        $value = trim((string) $value);
        if ('' === $value || !ctype_digit($value) || strlen($value) > 9) {
            return null;
        }
        $number = (int) $value;

        return $number >= 1 ? $number : null;
    }

    private function isValidDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $problems = \DateTimeImmutable::getLastErrors();

        // The round-trip comparison also catches rolled-over dates such as 2026-02-31.
        return false !== $date
            && (false === $problems || (0 === $problems['warning_count'] && 0 === $problems['error_count']))
            && $date->format('Y-m-d') === $value;
    }

    // ------------------------------------------------------------------
    // Validation
    // ------------------------------------------------------------------

    /**
     * Expects a normalised DTO. Only checks that need no clock and no other
     * rows; the database still enforces everything.
     *
     * @return array<string, string> fieldName => message (empty when valid)
     */
    private function validateAppointment(AppointmentDTO $appointment, bool $requirePatient): array
    {
        $errors = [];

        if ($requirePatient && (null === $appointment->patientId || $appointment->patientId < 1)) {
            $errors['patientId'] = 'Select a patient';
        }
        if (null === $appointment->doctorId || $appointment->doctorId < 1) {
            $errors['doctorId'] = 'Select a doctor';
        }

        if ('' === $appointment->appointmentDate) {
            $errors['appointmentDate'] = 'Appointment date is required';
        } elseif (!$this->isValidDate($appointment->appointmentDate)) {
            $errors['appointmentDate'] = 'Enter a valid date (YYYY-MM-DD)';
        }

        if ('' === $appointment->startTime) {
            $errors['startTime'] = 'Appointment time is required';
        } elseif (1 !== preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $appointment->startTime, $m)) {
            $errors['startTime'] = 'Enter a valid time (HH:MM)';
        } elseif (0 !== (int) $m[2] % 5) {
            $errors['startTime'] = 'Time must be on a 5-minute boundary (for example 10:00 or 10:05)';
        }

        if ($appointment->durationMinutes < 5
            || $appointment->durationMinutes > 120
            || 0 !== $appointment->durationMinutes % 5
        ) {
            $errors['durationMinutes'] = 'Duration must be between 5 and 120 minutes, in steps of 5';
        }

        if (null !== $appointment->reason && mb_strlen($appointment->reason) > self::MAX_REASON_LENGTH) {
            $errors['reason'] = 'Reason must be '.self::MAX_REASON_LENGTH.' characters or fewer';
        }

        return $errors;
    }

    // ------------------------------------------------------------------
    // Result building
    // ------------------------------------------------------------------

    /**
     * Result of create / update. When the database refuses with a message that
     * belongs to one field, the message is also attached to that field so the
     * form can show it next to the input.
     */
    private function appointmentResult(array $dbResult): array
    {
        $result = $this->simpleResult($dbResult);
        $result['appointment_id'] = isset($dbResult['appointment_id']) ? (int) $dbResult['appointment_id'] : null;
        $result['appointment_code'] = isset($dbResult['appointment_code']) ? (string) $dbResult['appointment_code'] : null;

        return $result;
    }

    /** Result of check-in: adds the queue entry and the token the database generated. */
    private function queueResult(array $dbResult): array
    {
        $result = $this->simpleResult($dbResult);
        $result['queue_id'] = isset($dbResult['queue_id']) ? (int) $dbResult['queue_id'] : null;
        $result['token_number'] = isset($dbResult['token_number']) ? (int) $dbResult['token_number'] : null;

        return $result;
    }

    /** success + message from a database row, with the field mapping for refusals. */
    private function simpleResult(array $dbResult): array
    {
        $success = $this->isTrue($dbResult['success'] ?? false);
        $message = (string) ($dbResult['message'] ?? '');

        $result = $this->failure($message);
        $result['success'] = $success;

        if (!$success) {
            $field = $this->fieldForMessage($message);
            if (null !== $field) {
                $result['errors'][$field] = $message;
            }
        }

        return $result;
    }

    /** Which form field a database refusal belongs to, or null for a general message. */
    private function fieldForMessage(string $message): ?string
    {
        $map = [
            'Patient not found' => 'patientId',
            'Inactive patients cannot be given appointments' => 'patientId',
            'Doctor not found' => 'doctorId',
            'Doctor is not active' => 'doctorId',
            'Doctor already has an appointment' => 'startTime',
            'Appointment time' => 'startTime',
            'Appointment must finish before midnight' => 'startTime',
            'Appointment date' => 'appointmentDate',
            'Appointments can be booked at most' => 'appointmentDate',
            'Duration must' => 'durationMinutes',
            'Reason must' => 'reason',
            'Cancellation reason' => 'reason',
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
            'appointment_id' => null,
            'appointment_code' => null,
            'queue_id' => null,
            'token_number' => null,
        ];
    }

    private function isTrue(mixed $value): bool
    {
        return true === $value || 't' === $value || 'true' === $value || 1 === $value || '1' === $value;
    }

    // ------------------------------------------------------------------
    // Report helpers
    // ------------------------------------------------------------------

    /**
     * @param list<string>                                 $unavailable collects the names of sections that failed
     * @param callable(): array<int, array<string, mixed>> $load
     *
     * @return list<array<string, mixed>>
     */
    private function loadSection(string $name, array &$unavailable, callable $load): array
    {
        try {
            return array_values($load());
        } catch (DbalException $exception) {
            $this->logger->error('FDM report section could not be loaded.', ['section' => $name, 'exception' => $exception]);
            $unavailable[] = $name;

            return [];
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function filterByDoctor(array $rows, int $doctorId): array
    {
        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => (int) ($row['doctor_id'] ?? 0) === $doctorId
        ));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string>               $columns
     *
     * @return array<string, int>
     */
    private function sumColumns(array $rows, array $columns): array
    {
        $totals = array_fill_keys($columns, 0);
        foreach ($rows as $row) {
            foreach ($columns as $column) {
                $totals[$column] += (int) ($row[$column] ?? 0);
            }
        }

        return $totals;
    }
}
