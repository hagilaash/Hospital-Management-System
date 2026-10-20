<?php

namespace App\DB;

use Doctrine\DBAL\Connection;

/**
 * Calls the Front Desk Management PostgreSQL functions (sql/fdm_module.sql).
 *
 * Same conventions as DatabaseFunction: every value is a bound parameter,
 * wrapped in CAST(... AS type) so PostgreSQL never has to guess the type of a
 * NULL. The CAST types and the ORDER of the arguments match the signatures in
 * sql/fdm_function_signatures.json exactly (checked by scripts/check_fdm_app.py).
 *
 * Dates are 'Y-m-d' strings and times are 'H:i' strings.
 */
class FDMDatabaseFunction
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /** ecs_hospital_today(): the hospital's current day (Asia/Kolkata), as 'Y-m-d'. */
    public function getHospitalToday(): string
    {
        return (string) $this->connection->fetchOne('SELECT public.ecs_hospital_today()');
    }

    // ------------------------------------------------------------------
    // Appointments
    // ------------------------------------------------------------------

    /**
     * ecs_create_appointment(7 params).
     *
     * @return array{success: bool, message: string, appointment_id: ?int, appointment_code: ?string}
     */
    public function createAppointment(
        int $patientId,
        int $doctorId,
        string $appointmentDate,
        string $startTime,
        int $durationMinutes,
        ?string $reason,
        string $createdBy,
    ): array {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ecs_create_appointment(
                CAST(:patient_id AS integer),
                CAST(:doctor_id AS integer),
                CAST(:appointment_date AS date),
                CAST(:start_time AS time without time zone),
                CAST(:duration_minutes AS integer),
                CAST(:reason AS character varying),
                CAST(:created_by AS character varying)
            )',
            [
                'patient_id' => $patientId,
                'doctor_id' => $doctorId,
                'appointment_date' => $appointmentDate,
                'start_time' => $startTime,
                'duration_minutes' => $durationMinutes,
                'reason' => $reason,
                'created_by' => $createdBy,
            ]
        );

        return $this->requireRow($row, 'ecs_create_appointment');
    }

    /**
     * ecs_update_appointment(7 params).
     *
     * @return array{success: bool, message: string, appointment_id: ?int, appointment_code: ?string}
     */
    public function updateAppointment(
        int $appointmentId,
        int $doctorId,
        string $appointmentDate,
        string $startTime,
        int $durationMinutes,
        ?string $reason,
        string $updatedBy,
    ): array {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ecs_update_appointment(
                CAST(:appointment_id AS integer),
                CAST(:doctor_id AS integer),
                CAST(:appointment_date AS date),
                CAST(:start_time AS time without time zone),
                CAST(:duration_minutes AS integer),
                CAST(:reason AS character varying),
                CAST(:updated_by AS character varying)
            )',
            [
                'appointment_id' => $appointmentId,
                'doctor_id' => $doctorId,
                'appointment_date' => $appointmentDate,
                'start_time' => $startTime,
                'duration_minutes' => $durationMinutes,
                'reason' => $reason,
                'updated_by' => $updatedBy,
            ]
        );

        return $this->requireRow($row, 'ecs_update_appointment');
    }

    /**
     * ecs_cancel_appointment(3 params).
     *
     * @return array{success: bool, message: string}
     */
    public function cancelAppointment(int $appointmentId, string $reason, string $cancelledBy): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ecs_cancel_appointment(
                CAST(:appointment_id AS integer),
                CAST(:reason AS character varying),
                CAST(:cancelled_by AS character varying)
            )',
            [
                'appointment_id' => $appointmentId,
                'reason' => $reason,
                'cancelled_by' => $cancelledBy,
            ]
        );

        return $this->requireRow($row, 'ecs_cancel_appointment');
    }

    /**
     * ecs_mark_appointment_no_show(2 params).
     *
     * @return array{success: bool, message: string}
     */
    public function markAppointmentNoShow(int $appointmentId, string $updatedBy): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ecs_mark_appointment_no_show(
                CAST(:appointment_id AS integer),
                CAST(:updated_by AS character varying)
            )',
            [
                'appointment_id' => $appointmentId,
                'updated_by' => $updatedBy,
            ]
        );

        return $this->requireRow($row, 'ecs_mark_appointment_no_show');
    }

    /**
     * ecs_get_appointment_by_id(1 param). Null when the appointment does not exist.
     */
    public function getAppointmentById(int $appointmentId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ecs_get_appointment_by_id(CAST(:appointment_id AS integer))',
            ['appointment_id' => $appointmentId]
        );

        return false === $row ? null : $row;
    }

    /**
     * ecs_get_appointments_list(8 params).
     * $status: NULL/'all' or one of Scheduled, Checked-In, Completed, Cancelled, No-Show
     * (the SQL function returns nothing for any other value; FDMService whitelists it).
     */
    public function getAppointmentsList(
        ?string $dateFrom,
        ?string $dateTo,
        ?int $doctorId,
        ?int $patientId,
        ?string $status,
        ?string $search,
        int $limit,
        int $offset,
    ): array {
        return $this->connection->fetchAllAssociative(
            'SELECT * FROM ecs_get_appointments_list(
                CAST(:date_from AS date),
                CAST(:date_to AS date),
                CAST(:doctor_id AS integer),
                CAST(:patient_id AS integer),
                CAST(:status AS character varying),
                CAST(:search AS character varying),
                CAST(:limit AS integer),
                CAST(:offset AS integer)
            )',
            [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'doctor_id' => $doctorId,
                'patient_id' => $patientId,
                'status' => $status,
                'search' => $search,
                'limit' => $limit,
                'offset' => $offset,
            ]
        );
    }

    /** ecs_count_appointments(6 params) */
    public function countAppointments(
        ?string $dateFrom,
        ?string $dateTo,
        ?int $doctorId,
        ?int $patientId,
        ?string $status,
        ?string $search,
    ): int {
        $total = $this->connection->fetchOne(
            'SELECT ecs_count_appointments(
                CAST(:date_from AS date),
                CAST(:date_to AS date),
                CAST(:doctor_id AS integer),
                CAST(:patient_id AS integer),
                CAST(:status AS character varying),
                CAST(:search AS character varying)
            )',
            [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'doctor_id' => $doctorId,
                'patient_id' => $patientId,
                'status' => $status,
                'search' => $search,
            ]
        );

        return (int) $total;
    }

    // ------------------------------------------------------------------
    // Check-in and queue
    // ------------------------------------------------------------------

    /**
     * ecs_check_in_appointment(2 params).
     *
     * @return array{success: bool, message: string, queue_id: ?int, token_number: ?int}
     */
    public function checkInAppointment(int $appointmentId, string $checkedInBy): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ecs_check_in_appointment(
                CAST(:appointment_id AS integer),
                CAST(:checked_in_by AS character varying)
            )',
            [
                'appointment_id' => $appointmentId,
                'checked_in_by' => $checkedInBy,
            ]
        );

        return $this->requireRow($row, 'ecs_check_in_appointment');
    }

    /**
     * ecs_check_in_walk_in(3 params).
     *
     * @return array{success: bool, message: string, queue_id: ?int, token_number: ?int}
     */
    public function checkInWalkIn(int $patientId, int $doctorId, string $checkedInBy): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ecs_check_in_walk_in(
                CAST(:patient_id AS integer),
                CAST(:doctor_id AS integer),
                CAST(:checked_in_by AS character varying)
            )',
            [
                'patient_id' => $patientId,
                'doctor_id' => $doctorId,
                'checked_in_by' => $checkedInBy,
            ]
        );

        return $this->requireRow($row, 'ecs_check_in_walk_in');
    }

    /**
     * ecs_get_queue_list(6 params).
     * $visitDate NULL = any day. $status: NULL/'all', 'open', or Waiting /
     * In Consultation / Completed / Cancelled.
     */
    public function getQueueList(
        ?string $visitDate,
        ?int $doctorId,
        ?string $status,
        ?string $search,
        int $limit,
        int $offset,
    ): array {
        return $this->connection->fetchAllAssociative(
            'SELECT * FROM ecs_get_queue_list(
                CAST(:visit_date AS date),
                CAST(:doctor_id AS integer),
                CAST(:status AS character varying),
                CAST(:search AS character varying),
                CAST(:limit AS integer),
                CAST(:offset AS integer)
            )',
            [
                'visit_date' => $visitDate,
                'doctor_id' => $doctorId,
                'status' => $status,
                'search' => $search,
                'limit' => $limit,
                'offset' => $offset,
            ]
        );
    }

    /** ecs_count_queue(4 params) */
    public function countQueue(?string $visitDate, ?int $doctorId, ?string $status, ?string $search): int
    {
        $total = $this->connection->fetchOne(
            'SELECT ecs_count_queue(
                CAST(:visit_date AS date),
                CAST(:doctor_id AS integer),
                CAST(:status AS character varying),
                CAST(:search AS character varying)
            )',
            [
                'visit_date' => $visitDate,
                'doctor_id' => $doctorId,
                'status' => $status,
                'search' => $search,
            ]
        );

        return (int) $total;
    }

    /**
     * ecs_update_queue_status(4 params). $newStatus is In Consultation, Completed or Cancelled.
     *
     * @return array{success: bool, message: string}
     */
    public function updateQueueStatus(int $queueId, string $newStatus, string $updatedBy, ?string $reason): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ecs_update_queue_status(
                CAST(:queue_id AS integer),
                CAST(:new_status AS character varying),
                CAST(:updated_by AS character varying),
                CAST(:reason AS character varying)
            )',
            [
                'queue_id' => $queueId,
                'new_status' => $newStatus,
                'updated_by' => $updatedBy,
                'reason' => $reason,
            ]
        );

        return $this->requireRow($row, 'ecs_update_queue_status');
    }

    // ------------------------------------------------------------------
    // Dashboard and reports
    // ------------------------------------------------------------------

    /** ecs_get_fdm_dashboard_summary(1 param): one row of counts. NULL date = hospital today. */
    public function getDashboardSummary(?string $date): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ecs_get_fdm_dashboard_summary(CAST(:date AS date))',
            ['date' => $date]
        );

        return $this->requireRow($row, 'ecs_get_fdm_dashboard_summary');
    }

    /** ecs_report_registrations(2 params): report_date, registrations */
    public function reportRegistrations(?string $dateFrom, ?string $dateTo): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT * FROM ecs_report_registrations(
                CAST(:date_from AS date),
                CAST(:date_to AS date)
            )',
            ['date_from' => $dateFrom, 'date_to' => $dateTo]
        );
    }

    /** ecs_report_appointments(2 params): one row per doctor */
    public function reportAppointments(?string $dateFrom, ?string $dateTo): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT * FROM ecs_report_appointments(
                CAST(:date_from AS date),
                CAST(:date_to AS date)
            )',
            ['date_from' => $dateFrom, 'date_to' => $dateTo]
        );
    }

    /** ecs_report_cancellations(2 params): at most 500 rows */
    public function reportCancellations(?string $dateFrom, ?string $dateTo): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT * FROM ecs_report_cancellations(
                CAST(:date_from AS date),
                CAST(:date_to AS date)
            )',
            ['date_from' => $dateFrom, 'date_to' => $dateTo]
        );
    }

    /** ecs_report_queue_summary(2 params): one row per doctor */
    public function reportQueueSummary(?string $dateFrom, ?string $dateTo): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT * FROM ecs_report_queue_summary(
                CAST(:date_from AS date),
                CAST(:date_to AS date)
            )',
            ['date_from' => $dateFrom, 'date_to' => $dateTo]
        );
    }

    /**
     * The write functions always return exactly one row. If none comes back the
     * database is badly broken, so fail loudly instead of letting a "false"
     * travel up the layers and surface as a TypeError.
     */
    private function requireRow(array|false $row, string $function): array
    {
        if (false === $row) {
            throw new \RuntimeException(sprintf('Database function %s returned no row.', $function));
        }

        return $row;
    }
}
