<?php

namespace App\DAO;

use App\DB\DatabaseFunction;

class LoginDAO
{
    public function __construct(
        private DatabaseFunction $databaseFunction
    ) {
    }

    public function login(string $username, string $password): array
    {
        return $this->databaseFunction->login($username, $password);
    }
}