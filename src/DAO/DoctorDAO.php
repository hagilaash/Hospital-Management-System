<?php

namespace App\DAO;

use App\DB\DatabaseFunction;

class DoctorDAO
{
    public function __construct(
        private DatabaseFunction $databaseFunction
    ) {
    }

    public function getDoctorsList(): array
    {
        $result = $this->databaseFunction->getDoctorsList();
        dd($result);
        return $result;
    }
}