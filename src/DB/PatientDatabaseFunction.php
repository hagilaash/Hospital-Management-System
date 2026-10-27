<?php

namespace App\DB;

use Doctrine\DBAL\Connection;

class PatientDatabaseFunction
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    
}
