<?php

namespace App\DB;

use Doctrine\DBAL\Connection;

class DatabaseFunction
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function login(string $username, string $password): array
    {
        return $this->connection->fetchAssociative(
            'SELECT * FROM function_login(:username, :password)',
            [
                'username' => $username,
                'password' => $password,
            ]
        );
    }

    // public function getHomeModules(): array
    // {
    //     $sql = 'SELECT * FROM function_get_home_modules()';

    //     return $this->connection->fetchAllAssociative($sql);
    // }

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

    // ------------------------------------------------------------------
    // Patient Management
    //
    // Every value is bound as a parameter and wrapped in CAST(... AS type)
    // so PostgreSQL never has to guess the type of a NULL. The CAST types and
    // the ORDER of the arguments match the signatures in
    // sql/patients_module.sql exactly. Booleans are sent as the text
    // 'true' / 'false' and cast on the database side.
    // ------------------------------------------------------------------

    /**
     * ecs_register_patient(12 params).
     *
     * @return array{success: bool, message: string, patient_id: ?int, patient_code: ?string, duplicate_warning: bool}
     */
    public function registerPatient(
        string $firstName,
        string $lastName,
        string $gender,
        string $dateOfBirth,
        string $phone,
        ?string $email,
        ?string $address,
        ?string $bloodGroup,
        ?string $emergencyContactName,
        ?string $emergencyContactPhone,
        string $createdBy,
        bool $confirmDuplicate,
    ): array {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ecs_register_patient(
                CAST(:first_name AS character varying),
                CAST(:last_name AS character varying),
                CAST(:gender AS character varying),
                CAST(:date_of_birth AS date),
                CAST(:phone AS character varying),
                CAST(:email AS character varying),
                CAST(:address AS character varying),
                CAST(:blood_group AS character varying),
                CAST(:emergency_contact_name AS character varying),
                CAST(:emergency_contact_phone AS character varying),
                CAST(:created_by AS character varying),
                CAST(:confirm_duplicate AS boolean)
            )',
            [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'gender' => $gender,
                'date_of_birth' => $dateOfBirth,
                'phone' => $phone,
                'email' => $email,
                'address' => $address,
                'blood_group' => $bloodGroup,
                'emergency_contact_name' => $emergencyContactName,
                'emergency_contact_phone' => $emergencyContactPhone,
                'created_by' => $createdBy,
                'confirm_duplicate' => $confirmDuplicate ? 'true' : 'false',
            ]
        );

        return $this->requireRow($row, 'ecs_register_patient');
    }

    /**
     * ecs_update_patient(13 params).
     *
     * @return array{success: bool, message: string, patient_id: ?int, patient_code: ?string, duplicate_warning: bool}
     */
    public function updatePatient(
        int $patientId,
        string $firstName,
        string $lastName,
        string $gender,
        string $dateOfBirth,
        string $phone,
        ?string $email,
        ?string $address,
        ?string $bloodGroup,
        ?string $emergencyContactName,
        ?string $emergencyContactPhone,
        string $updatedBy,
        bool $confirmDuplicate,
    ): array {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ecs_update_patient(
                CAST(:patient_id AS integer),
                CAST(:first_name AS character varying),
                CAST(:last_name AS character varying),
                CAST(:gender AS character varying),
                CAST(:date_of_birth AS date),
                CAST(:phone AS character varying),
                CAST(:email AS character varying),
                CAST(:address AS character varying),
                CAST(:blood_group AS character varying),
                CAST(:emergency_contact_name AS character varying),
                CAST(:emergency_contact_phone AS character varying),
                CAST(:updated_by AS character varying),
                CAST(:confirm_duplicate AS boolean)
            )',
            [
                'patient_id' => $patientId,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'gender' => $gender,
                'date_of_birth' => $dateOfBirth,
                'phone' => $phone,
                'email' => $email,
                'address' => $address,
                'blood_group' => $bloodGroup,
                'emergency_contact_name' => $emergencyContactName,
                'emergency_contact_phone' => $emergencyContactPhone,
                'updated_by' => $updatedBy,
                'confirm_duplicate' => $confirmDuplicate ? 'true' : 'false',
            ]
        );

        return $this->requireRow($row, 'ecs_update_patient');
    }

    /**
     * ecs_set_patient_status(3 params).
     *
     * @return array{success: bool, message: string}
     */
    public function setPatientStatus(int $patientId, bool $isActive, string $updatedBy): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ecs_set_patient_status(
                CAST(:patient_id AS integer),
                CAST(:is_active AS boolean),
                CAST(:updated_by AS character varying)
            )',
            [
                'patient_id' => $patientId,
                'is_active' => $isActive ? 'true' : 'false',
                'updated_by' => $updatedBy,
            ]
        );

        return $this->requireRow($row, 'ecs_set_patient_status');
    }

    /**
     * ecs_find_possible_duplicates(5 params): up to 10 rows.
     * Columns: patient_id, patient_code, first_name, last_name,
     * date_of_birth, phone, is_active, match_reason.
     */
    public function findPossibleDuplicates(
        string $firstName,
        string $lastName,
        string $dateOfBirth,
        string $phone,
        ?int $excludePatientId,
    ): array {
        return $this->connection->fetchAllAssociative(
            'SELECT * FROM ecs_find_possible_duplicates(
                CAST(:first_name AS character varying),
                CAST(:last_name AS character varying),
                CAST(:date_of_birth AS date),
                CAST(:phone AS character varying),
                CAST(:exclude_patient_id AS integer)
            )',
            [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'date_of_birth' => $dateOfBirth,
                'phone' => $phone,
                'exclude_patient_id' => $excludePatientId,
            ]
        );
    }

    /**
     * ecs_get_patient_by_id(1 param). Returns null when the patient does not
     * exist (the one place a "no row" result is a normal outcome).
     */
    public function getPatientById(int $patientId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ecs_get_patient_by_id(CAST(:patient_id AS integer))',
            ['patient_id' => $patientId]
        );

        return false === $row ? null : $row;
    }

    /**
     * ecs_get_patients_list(4 params).
     * $status must be exactly 'all', 'active' or 'inactive' (the SQL function
     * returns no rows for any other value); the Service guarantees that.
     */
    public function getPatientsList(?string $search, string $status, int $limit, int $offset): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT * FROM ecs_get_patients_list(
                CAST(:search AS character varying),
                CAST(:status AS character varying),
                CAST(:limit AS integer),
                CAST(:offset AS integer)
            )',
            [
                'search' => $search,
                'status' => $status,
                'limit' => $limit,
                'offset' => $offset,
            ]
        );
    }

    /**
     * ecs_count_patients(2 params).
     */
    public function countPatients(?string $search, string $status): int
    {
        $total = $this->connection->fetchOne(
            'SELECT ecs_count_patients(
                CAST(:search AS character varying),
                CAST(:status AS character varying)
            )',
            [
                'search' => $search,
                'status' => $status,
            ]
        );

        return (int) $total;
    }

    /**
     * The write functions always return exactly one row. If none comes back
     * something is badly wrong with the database, so fail loudly instead of
     * letting a "false" travel up the layers and surface as a TypeError.
     */
    private function requireRow(array|false $row, string $function): array
    {
        if (false === $row) {
            throw new \RuntimeException(sprintf('Database function %s returned no row.', $function));
        }

        return $row;
    }
}
