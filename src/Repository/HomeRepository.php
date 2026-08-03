<?php

namespace app\Repository

use Doctrine\DBAL\Connection;

class HomeRepository
{
    public function__construct(
        private Connection $connection
    ){

    }
    public function getHomeModules(): array
    {
        $sql="select * from function_get_home_modules()";
        return $this->connection->fetchAllAssociative($sql);
    }
}