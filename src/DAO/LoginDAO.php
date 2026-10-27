<?php

namespace App\DAO;

use App\DB\DatabaseFunction;
use App\DB\LoginDatabaseFunction;

class LoginDAO
{
    public function __construct(
        private DatabaseFunction $databaseFunction,
        private LoginDatabaseFunction $loginDatabaseFunction,
    ) {
    }

    public function login(string $username, string $password): array
    {
        return $this->loginDatabaseFunction->login($username, $password);
    }
}
