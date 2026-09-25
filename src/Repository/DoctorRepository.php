<?php

namespace App\Repository;

use App\DAO\DoctorDAO;

class DoctorRepository
{
    public function __construct(
        private DoctorDAO $doctorDAO
    ) {
    }

    public function getDoctorsList(): array
    {
        return $this->doctorDAO->getDoctorsList();
    }
}