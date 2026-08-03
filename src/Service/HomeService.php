<?php

namespace app\Service;

use app\repository\HomeRepository;

class HomeService
{
    public function__construct(
        private HomeRepository $home_repository
    ){

    }
    public function getHomeModules(): array
    {
        return $this->homerepository-->getModules();
    }
}