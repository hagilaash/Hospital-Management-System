<?php

namespace App\Service;

use App\Repository\HomeRepository;

class HomeService
{
    public function __construct(
        private HomeRepository $homeRepository
    ) {
    }

    public function getHomeModules(): array
    {
        return $this->homeRepository->getHomeModules();
    }
}