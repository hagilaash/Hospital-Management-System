<?php

namespace App\Controller;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController
{
    public function __construct(
        private LoggerInterface $logger
    ) {
    }

    #[Route('/dashboard', name: 'dashboard')]
    public function dashboard(): Response
    {
        $this->logger->info('Dashboard page opened');

        return new Response('Dashboard is working');
    }
}