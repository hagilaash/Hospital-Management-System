<?php

namespace App\DAO;

use App\DB\ConsultationDatabaseFunction;
use App\DTO\ConsultationDTO;

/**
 * Executes the OP Consultation database operations through
 * ConsultationDatabaseFunction. It only unpacks DTOs into scalar arguments and
 * returns the raw database results; it contains no business rules.
 */
class ConsultationDAO
{
    public function __construct(
        private ConsultationDatabaseFunction $consultationDatabaseFunction
    ) {
    }

    public function startConsultation(int $queueId, string $startedBy): array
    {
        return $this->consultationDatabaseFunction->startConsultation($queueId, $startedBy);
    }

    public function startConsultationForPatient(int $patientId, int $doctorId, string $startedBy): array
    {
        return $this->consultationDatabaseFunction->startConsultationForPatient($patientId, $doctorId, $startedBy);
    }

    public function saveConsultation(int $consultationId, ConsultationDTO $consultation, bool $sendToBilling, string $updatedBy): array
    {
        return $this->consultationDatabaseFunction->saveConsultation(
            $consultationId,
            $consultation->chiefComplaint,
            $consultation->diagnosis,
            $consultation->advice,
            $consultation->notes,
            $sendToBilling,
            $updatedBy
        );
    }

    public function getConsultationById(int $consultationId): ?array
    {
        return $this->consultationDatabaseFunction->getConsultationById($consultationId);
    }

    public function getConsultationsList(
        ?string $status,
        ?int $doctorId,
        ?string $search,
        ?string $dateFrom,
        ?string $dateTo,
        int $limit,
        int $offset
    ): array {
        return $this->consultationDatabaseFunction->getConsultationsList($status, $doctorId, $search, $dateFrom, $dateTo, $limit, $offset);
    }

    public function countConsultations(?string $status, ?int $doctorId, ?string $search, ?string $dateFrom, ?string $dateTo): int
    {
        return $this->consultationDatabaseFunction->countConsultations($status, $doctorId, $search, $dateFrom, $dateTo);
    }

    public function getWorklist(?int $doctorId, ?string $search, int $limit, int $offset): array
    {
        return $this->consultationDatabaseFunction->getWorklist($doctorId, $search, $limit, $offset);
    }

    public function countWorklist(?int $doctorId, ?string $search): int
    {
        return $this->consultationDatabaseFunction->countWorklist($doctorId, $search);
    }
}