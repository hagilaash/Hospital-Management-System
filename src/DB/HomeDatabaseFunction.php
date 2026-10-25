<?php

namespace App\DB;

use Doctrine\DBAL\Connection;

class HomeDatabaseFunction
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function getHomeModules(): array
    {
        $sql = 'SELECT * FROM function_get_home_modules()';

        return $this->connection->fetchAllAssociative($sql);
    }
}