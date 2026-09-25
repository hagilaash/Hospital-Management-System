<?php

namespace App\Repository;

use App\DAO\HomeDAO;

class HomeRepository
{
    public function __construct(
        private HomeDAO $homeDAO
    ) {
    }

    public function getHomeModules(): array
    {
        return $this->homeDAO->getHomeModules();
    }
}