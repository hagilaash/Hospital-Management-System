<?php

namespace App\Service;

use App\Repository\DoctorRepository;

class DoctorService
{
    public function __construct(
        private DoctorRepository $doctorRepository
    ) {
    }

    public function getDoctorsList(): array
    {
        return $this->doctorRepository->getDoctorsList();
    }
}