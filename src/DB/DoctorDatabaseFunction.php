<?php

namespace App\DB;

use Doctrine\DBAL\Connection;

class DoctorDatabaseFunction
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * ecs_count_doctors().
     * Returns the total number of active doctors.
     */
    public function countDoctors(
        ?string $search = null,
        ?string $specialization = null,
        ?string $department = null,
        string $status = 'Active',
    ): int {
        $sql = '
        SELECT public.ecs_count_doctors(
            CAST(:search AS VARCHAR),
            CAST(:specialization AS VARCHAR),
            CAST(:department AS VARCHAR),
            CAST(:status AS VARCHAR)
        )
    ';

        return (int) $this->connection->fetchOne($sql, [
            'search' => $search,
            'specialization' => $specialization,
            'department' => $department,
            'status' => $status,
        ]);
    }

    public function getDoctorsList(
        int $limit,
        int $offset,
        ?string $search = null,
        ?string $specialization = null,
        ?string $department = null,
        string $status = 'Active',
    ): array {
        $sql = '
        SELECT *
        FROM public.ecs_get_doctors_list(
            CAST(:limit AS INTEGER),
            CAST(:offset AS INTEGER),
            CAST(:search AS VARCHAR),
            CAST(:specialization AS VARCHAR),
            CAST(:department AS VARCHAR),
            CAST(:status AS VARCHAR)
        )
    ';

        return $this->connection->fetchAllAssociative($sql, [
            'limit' => $limit,
            'offset' => $offset,
            'search' => $search,
            'specialization' => $specialization,
            'department' => $department,
            'status' => $status,
        ]);
    }

    public function registerDoctor(array $data): array
    {
        $sql = '
            SELECT *
            FROM public.ecs_register_doctor(
                :doctor_name,
                :specialization,
                :department,
                :phone_no,
                :email,
                :qualification,
                :gender,
                CAST(:date_of_birth AS DATE)
            )
        ';

        return $this->connection->fetchAssociative($sql, [
            'doctor_name' => $data['doctor_name'],
            'specialization' => $data['specialization'],
            'department' => $data['department'],
            'phone_no' => $data['phone_no'],
            'email' => $data['email'],
            'qualification' => $data['qualification'],
            'gender' => $data['gender'],
            'date_of_birth' => $data['date_of_birth'],
        ]) ?: [];
    }
}
