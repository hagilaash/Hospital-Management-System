<?php

namespace App\Repository;

use App\DAO\LoginDAO;

class LoginRepository
{
    public function __construct(
        private LoginDAO $loginDAO
    ) {
    }

    public function login(string $username, string $password): array
    {
        return $this->loginDAO->login($username, $password);
    }
}