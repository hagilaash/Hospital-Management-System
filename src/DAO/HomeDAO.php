<?php

namespace App\DAO;

use App\DB\HomeDatabaseFunction;

class HomeDAO
{
    public function __construct(
        private HomeDatabaseFunction $homeDatabaseFunction
    ) {
    }

    public function getHomeModules(): array
    {
        return $this->homeDatabaseFunction->getHomeModules();
    }
}
