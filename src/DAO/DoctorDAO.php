<?php

namespace App\DAO;

use App\DB\DoctorDatabaseFunction;

class DoctorDAO
{
    public function __construct(
        private DoctorDatabaseFunction $doctorDatabaseFunction,
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
        return $this->doctorDatabaseFunction->getDoctorsList(
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
        return $this->doctorDatabaseFunction->countDoctors(
            $search,
            $specialization,
            $department,
            $status
        );
    }

    public function registerDoctor(array $data): array
    {
        return $this->doctorDatabaseFunction->registerDoctor($data);
    }
}
