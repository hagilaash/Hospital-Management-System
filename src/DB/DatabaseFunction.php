<?php

namespace App\DB;

use Doctrine\DBAL\Connection;

class DatabaseFunction
{
    public function __construct(
        private Connection $connection
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

    public function getHomeModules(): array
    {
        $sql = 'SELECT * FROM function_get_home_modules()';

        return $this->connection->fetchAllAssociative($sql);
    }

    public function getDoctorsList(): array
    { 
        $sql = 'SELECT * FROM ecs_get_doctors_list()';
        return $this->connection->fetchAllAssociative($sql);
    }
}