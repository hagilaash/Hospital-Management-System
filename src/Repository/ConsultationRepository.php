<?php

namespace App\Repository;

use App\DAO\ConsultationDAO;
use App\DTO\ConsultationDTO;
use App\DTO\WorklistEntryDTO;

/**
 * Coordinates OP Consultation data access through ConsultationDAO and turns
 * rows into DTOs. Write results are returned as the arrays the database
 * produced. No business rules live here.
 */
class ConsultationRepository
{
    public function __construct(
        private ConsultationDAO $consultationDAO
    ) {
    }

    /**
     * @return array{success: bool, message: string, consultation_id: ?int, consultation_code: ?string}
     */
    public function startConsultation(int $queueId, string $startedBy): array
    {
        return $this->consultationDAO->startConsultation($queueId, $startedBy);
    }

    /**
     * @return array{success: bool, message: string, consultation_id: ?int, consultation_code: ?string}
     */
    public function startConsultationForPatient(int $patientId, int $doctorId, string $startedBy): array
    {
        return $this->consultationDAO->startConsultationForPatient($patientId, $doctorId, $startedBy);
    }

    /**
     * @return array{success: bool, message: string, status: ?string}
     */
    public function saveConsultation(int $consultationId, ConsultationDTO $consultation, bool $sendToBilling, string $updatedBy): array
    {
        return $this->consultationDAO->saveConsultation($consultationId, $consultation, $sendToBilling, $updatedBy);
    }

    public function getConsultationById(int $consultationId): ?ConsultationDTO
    {
        $row = $this->consultationDAO->getConsultationById($consultationId);

        return $row === null ? null : ConsultationDTO::fromRow($row);
    }

    /**
     * @return list<ConsultationDTO>
     */
    public function getConsultationsList(
        ?string $status,
        ?int $doctorId,
        ?string $search,
        ?string $dateFrom,
        ?string $dateTo,
        int $limit,
        int $offset
    ): array {
        $rows = $this->consultationDAO->getConsultationsList($status, $doctorId, $search, $dateFrom, $dateTo, $limit, $offset);

        return array_map(static fn (array $row): ConsultationDTO => ConsultationDTO::fromRow($row), $rows);
    }

    public function countConsultations(?string $status, ?int $doctorId, ?string $search, ?string $dateFrom, ?string $dateTo): int
    {
        return $this->consultationDAO->countConsultations($status, $doctorId, $search, $dateFrom, $dateTo);
    }

    /**
     * @return list<WorklistEntryDTO>
     */
    public function getWorklist(?int $doctorId, ?string $search, int $limit, int $offset): array
    {
        $rows = $this->consultationDAO->getWorklist($doctorId, $search, $limit, $offset);

        return array_map(static fn (array $row): WorklistEntryDTO => WorklistEntryDTO::fromRow($row), $rows);
    }

    public function countWorklist(?int $doctorId, ?string $search): int
    {
        return $this->consultationDAO->countWorklist($doctorId, $search);
    }
}