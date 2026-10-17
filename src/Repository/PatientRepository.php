<?php

namespace App\Repository;

use App\DAO\PatientDAO;
use App\DTO\PatientDTO;

/**
 * Coordinates patient data access through PatientDAO and turns database rows
 * into PatientDTO objects. Write results and duplicate rows are returned as
 * the arrays the database produced. No business rules live here.
 */
class PatientRepository
{
    public function __construct(
        private PatientDAO $patientDAO,
    ) {
    }

    /**
     * @return array{success: bool, message: string, patient_id: ?int, patient_code: ?string, duplicate_warning: bool}
     */
    public function registerPatient(PatientDTO $patient, string $createdBy, bool $confirmDuplicate): array
    {
        return $this->patientDAO->registerPatient($patient, $createdBy, $confirmDuplicate);
    }

    /**
     * @return array{success: bool, message: string, patient_id: ?int, patient_code: ?string, duplicate_warning: bool}
     */
    public function updatePatient(
        int $patientId,
        PatientDTO $patient,
        string $updatedBy,
        bool $confirmDuplicate,
    ): array {
        return $this->patientDAO->updatePatient($patientId, $patient, $updatedBy, $confirmDuplicate);
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function setPatientStatus(int $patientId, bool $isActive, string $updatedBy): array
    {
        return $this->patientDAO->setPatientStatus($patientId, $isActive, $updatedBy);
    }

    /**
     * Rows with: patient_id, patient_code, first_name, last_name,
     * date_of_birth, phone, is_active, match_reason.
     */
    public function findPossibleDuplicates(PatientDTO $patient, ?int $excludePatientId = null): array
    {
        return $this->patientDAO->findPossibleDuplicates($patient, $excludePatientId);
    }

    public function getPatientById(int $patientId): ?PatientDTO
    {
        $row = $this->patientDAO->getPatientById($patientId);

        return null === $row ? null : PatientDTO::fromRow($row);
    }

    /**
     * @return list<PatientDTO>
     */
    public function getPatientsList(?string $search, string $status, int $limit, int $offset): array
    {
        $rows = $this->patientDAO->getPatientsList($search, $status, $limit, $offset);

        return array_map(
            static fn (array $row): PatientDTO => PatientDTO::fromRow($row),
            $rows
        );
    }

    public function countPatients(?string $search, string $status): int
    {
        return $this->patientDAO->countPatients($search, $status);
    }
}
