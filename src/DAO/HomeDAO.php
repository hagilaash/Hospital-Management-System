<?php

namespace App\DAO;

use App\DB\DatabaseFunction;

class HomeDAO
{
    public function __construct(
        private DatabaseFunction $databaseFunction
    ) {
    }

    public function getHomeModules(): array
    {
        return $this->databaseFunction->getHomeModules();
    }
}