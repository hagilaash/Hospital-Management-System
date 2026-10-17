<?php

namespace App\Service;

use App\DTO\DoctorDTO;
use App\Repository\DoctorRepository;

class DoctorService
{
    public function __construct(
        private DoctorRepository $doctorRepository,
    ) {
    }

    /**
     * @return DoctorDTO[]
     */
    public function getDoctorsList(int $page = 1, int $pageSize = 10, ?string $search = null, ?string $specialization = null, ?string $department = null, string $status = 'Active'): array
    {
        $page = max(1, $page);
        $pageSize = max(1, min(100, $pageSize));
        $offset = ($page - 1) * $pageSize;
        $doctors = $this->doctorRepository->getDoctorsList($pageSize, $offset, $search, $specialization, $department, $status);

        return array_map(fn (array $doctor) => new DoctorDTO((int) $doctor['doctor_id'], $doctor['doctor_name'], $doctor['specialization'], $doctor['department'], $doctor['phone_no'], $doctor['email'], $doctor['qualification'], $doctor['gender'], $doctor['date_of_birth'], $doctor['created_at'], $doctor['updated_at'], isset($doctor['is_active']) ? (string) $doctor['is_active'] : null), $doctors);
    }

    public function countDoctors(
        ?string $search = null,
        ?string $specialization = null,
        ?string $department = null,
        string $status = 'Active',
    ): int {
        return $this->doctorRepository->countDoctors(
            $search,
            $specialization,
            $department,
            $status
        );
    }

    public function registerDoctor(array $data): array
    {
        $data = [
            'doctor_name' => trim($data['doctor_name'] ?? ''),
            'specialization' => trim($data['specialization'] ?? ''),
            'department' => trim($data['department'] ?? ''),
            'phone_no' => trim($data['phone_no'] ?? ''),
            'email' => trim($data['email'] ?? ''),
            'qualification' => trim($data['qualification'] ?? ''),
            'gender' => trim($data['gender'] ?? ''),
            'date_of_birth' => trim($data['date_of_birth'] ?? ''),
        ];

        if ('' === $data['doctor_name']) {
            return [
                'success' => false,
                'message' => 'Doctor name is required.',
            ];
        }

        if ('' === $data['specialization']) {
            return [
                'success' => false,
                'message' => 'Specialization is required.',
            ];
        }

        if ('' === $data['date_of_birth']) {
            $data['date_of_birth'] = null;
        }

        foreach ([
            'department',
            'phone_no',
            'email',
            'qualification',
            'gender',
        ] as $field) {
            if ('' === $data[$field]) {
                $data[$field] = null;
            }
        }

        return $this->doctorRepository->registerDoctor($data);
    }
}
