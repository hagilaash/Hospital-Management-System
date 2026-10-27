<?php

namespace App\DB;

use Doctrine\DBAL\Connection;

class LoginDatabaseFunction
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function login(string $username, string $password): array
    {
        return $this->connection->fetchAssociative(
            'SELECT * FROM function_login(:username, :password)',
            [
                'username' => $username,
                'password' => $password,
            ]
        );
    }
}
