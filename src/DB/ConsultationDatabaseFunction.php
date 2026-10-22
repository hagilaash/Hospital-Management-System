<?php

namespace App\DB;

use Doctrine\DBAL\Connection;

/**
 * Calls the OP Consultation PostgreSQL functions
 * (sql/01_workflow_foundation_opc.sql).
 *
 * Same conventions as FDMDatabaseFunction: every value is a bound parameter
 * wrapped in CAST(... AS type); CAST types, bind names and argument ORDER match
 * the SQL signatures exactly (checked by scripts/check_workflow.py).
 * Booleans are sent as the text 'true' / 'false'.
 */
class ConsultationDatabaseFunction
{
    public function __construct(
        private Connection $connection
    ) {
    }

    /**
     * ecs_start_consultation(2 params)
     *
     * @return array{success: bool, message: string, consultation_id: ?int, consultation_code: ?string}
     */
    public function startConsultation(int $queueId, string $startedBy): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ecs_start_consultation(
                CAST(:queue_id AS integer),
                CAST(:started_by AS character varying)
            )',
            ['queue_id' => $queueId, 'started_by' => $startedBy]
        );

        return $this->requireRow($row, 'ecs_start_consultation');
    }

    /**
     * ecs_start_consultation_for_patient(3 params)
     *
     * @return array{success: bool, message: string, consultation_id: ?int, consultation_code: ?string}
     */
    public function startConsultationForPatient(int $patientId, int $doctorId, string $startedBy): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ecs_start_consultation_for_patient(
                CAST(:patient_id AS integer),
                CAST(:doctor_id AS integer),
                CAST(:started_by AS character varying)
            )',
            ['patient_id' => $patientId, 'doctor_id' => $doctorId, 'started_by' => $startedBy]
        );

        return $this->requireRow($row, 'ecs_start_consultation_for_patient');
    }

    /**
     * ecs_save_consultation(7 params)
     *
     * @return array{success: bool, message: string, status: ?string}
     */
    public function saveConsultation(
        int $consultationId,
        ?string $chiefComplaint,
        ?string $diagnosis,
        ?string $advice,
        ?string $notes,
        bool $sendToBilling,
        string $updatedBy
    ): array {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ecs_save_consultation(
                CAST(:consultation_id AS integer),
                CAST(:chief_complaint AS character varying),
                CAST(:diagnosis AS character varying),
                CAST(:advice AS character varying),
                CAST(:notes AS character varying),
                CAST(:send_to_billing AS boolean),
                CAST(:updated_by AS character varying)
            )',
            [
                'consultation_id' => $consultationId,
                'chief_complaint' => $chiefComplaint,
                'diagnosis' => $diagnosis,
                'advice' => $advice,
                'notes' => $notes,
                'send_to_billing' => $sendToBilling ? 'true' : 'false',
                'updated_by' => $updatedBy,
            ]
        );

        return $this->requireRow($row, 'ecs_save_consultation');
    }

    /** ecs_get_consultation_by_id(1 param). Null when it does not exist. */
    public function getConsultationById(int $consultationId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ecs_get_consultation_by_id(CAST(:consultation_id AS integer))',
            ['consultation_id' => $consultationId]
        );

        return $row === false ? null : $row;
    }

    /** ecs_get_consultations_list(7 params) */
    public function getConsultationsList(
        ?string $status,
        ?int $doctorId,
        ?string $search,
        ?string $dateFrom,
        ?string $dateTo,
        int $limit,
        int $offset
    ): array {
        return $this->connection->fetchAllAssociative(
            'SELECT * FROM ecs_get_consultations_list(
                CAST(:status AS character varying),
                CAST(:doctor_id AS integer),
                CAST(:search AS character varying),
                CAST(:date_from AS date),
                CAST(:date_to AS date),
                CAST(:limit AS integer),
                CAST(:offset AS integer)
            )',
            [
                'status' => $status,
                'doctor_id' => $doctorId,
                'search' => $search,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'limit' => $limit,
                'offset' => $offset,
            ]
        );
    }

    /** ecs_count_consultations(5 params) */
    public function countConsultations(
        ?string $status,
        ?int $doctorId,
        ?string $search,
        ?string $dateFrom,
        ?string $dateTo
    ): int {
        $total = $this->connection->fetchOne(
            'SELECT ecs_count_consultations(
                CAST(:status AS character varying),
                CAST(:doctor_id AS integer),
                CAST(:search AS character varying),
                CAST(:date_from AS date),
                CAST(:date_to AS date)
            )',
            [
                'status' => $status,
                'doctor_id' => $doctorId,
                'search' => $search,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ]
        );

        return (int) $total;
    }

    /** ecs_get_consult_worklist(4 params): today's open queue entries */
    public function getWorklist(?int $doctorId, ?string $search, int $limit, int $offset): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT * FROM ecs_get_consult_worklist(
                CAST(:doctor_id AS integer),
                CAST(:search AS character varying),
                CAST(:limit AS integer),
                CAST(:offset AS integer)
            )',
            ['doctor_id' => $doctorId, 'search' => $search, 'limit' => $limit, 'offset' => $offset]
        );
    }

    /** ecs_count_consult_worklist(2 params) */
    public function countWorklist(?int $doctorId, ?string $search): int
    {
        $total = $this->connection->fetchOne(
            'SELECT ecs_count_consult_worklist(
                CAST(:doctor_id AS integer),
                CAST(:search AS character varying)
            )',
            ['doctor_id' => $doctorId, 'search' => $search]
        );

        return (int) $total;
    }

    /**
     * The write functions always return exactly one row. If none comes back the
     * database is badly broken, so fail loudly instead of letting a "false"
     * travel up the layers and surface as a TypeError.
     */
    private function requireRow(array|false $row, string $function): array
    {
        if ($row === false) {
            throw new \RuntimeException(sprintf('Database function %s returned no row.', $function));
        }

        return $row;
    }
}