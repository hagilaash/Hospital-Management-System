<?php

namespace App\DAO;

use App\DB\DatabaseFunction;
use App\DTO\PatientDTO;

/**
 * Executes the patient database operations through DatabaseFunction.
 * It only unpacks DTOs into the scalar arguments DatabaseFunction expects and
 * returns the raw database results; it contains no business rules.
 */
class PatientDAO
{
    public function __construct(
        private DatabaseFunction $databaseFunction,
    ) {
    }

    public function registerPatient(PatientDTO $patient, string $createdBy, bool $confirmDuplicate): array
    {
        return $this->databaseFunction->registerPatient(
            $patient->firstName,
            $patient->lastName,
            $patient->gender,
            $patient->dateOfBirth,
            $patient->phone,
            $patient->email,
            $patient->address,
            $patient->bloodGroup,
            $patient->emergencyContactName,
            $patient->emergencyContactPhone,
            $createdBy,
            $confirmDuplicate
        );
    }

    public function updatePatient(
        int $patientId,
        PatientDTO $patient,
        string $updatedBy,
        bool $confirmDuplicate,
    ): array {
        return $this->databaseFunction->updatePatient(
            $patientId,
            $patient->firstName,
            $patient->lastName,
            $patient->gender,
            $patient->dateOfBirth,
            $patient->phone,
            $patient->email,
            $patient->address,
            $patient->bloodGroup,
            $patient->emergencyContactName,
            $patient->emergencyContactPhone,
            $updatedBy,
            $confirmDuplicate
        );
    }

    public function setPatientStatus(int $patientId, bool $isActive, string $updatedBy): array
    {
        return $this->databaseFunction->setPatientStatus($patientId, $isActive, $updatedBy);
    }

    public function findPossibleDuplicates(PatientDTO $patient, ?int $excludePatientId): array
    {
        return $this->databaseFunction->findPossibleDuplicates(
            $patient->firstName,
            $patient->lastName,
            $patient->dateOfBirth,
            $patient->phone,
            $excludePatientId
        );
    }

    public function getPatientById(int $patientId): ?array
    {
        return $this->databaseFunction->getPatientById($patientId);
    }

    public function getPatientsList(?string $search, string $status, int $limit, int $offset): array
    {
        return $this->databaseFunction->getPatientsList($search, $status, $limit, $offset);
    }

    public function countPatients(?string $search, string $status): int
    {
        return $this->databaseFunction->countPatients($search, $status);
    }
}
