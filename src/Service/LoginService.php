<?php

namespace App\Service;

use App\Repository\LoginRepository;

class LoginService
{
    public function __construct(
        private LoginRepository $loginRepository
    ) {
    }

    public function login(string $username, string $password): array
    { 
        return $this->loginRepository->login($username, $password);
    }
}