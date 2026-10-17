<?php

namespace App\Repository;

use App\DAO\DoctorDAO;

class DoctorRepository
{
    public function __construct(
        private DoctorDAO $doctorDAO,
    ) {
    }

    public function getDoctorsList(
        int $limit,
        int $offset,
        ?string $search = null,
        ?string $specialization = null,
        ?string $department = null,
        string $status = 'Active',
    ): array {
        return $this->doctorDAO->getDoctorsList(
            $limit,
            $offset,
            $search,
            $specialization,
            $department,
            $status
        );
    }

    public function countDoctors(
        ?string $search = null,
        ?string $specialization = null,
        ?string $department = null,
        string $status = 'Active',
    ): int {
        return $this->doctorDAO->countDoctors(
            $search,
            $specialization,
            $department,
            $status
        );
    }

    public function registerDoctor(array $data): array
    {
        return $this->doctorDAO->registerDoctor($data);
    }
}
