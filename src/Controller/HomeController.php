<?php

namespace App\Controller;

use App\Service\HomeService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    public function __construct(
        private HomeService $homeService,
        private LoggerInterface $logger,
    ) {
    }

    #[Route('/home', name: 'home', methods: ['GET'])]
    public function home(): Response
    {
        $modules = $this->homeService->getHomeModules();
        $this->logger->info('Home page requested'); // Log the request for the home page
        return $this->render('home/dashboard.html.twig', [
            'modules' => $modules,
        ]);
    }
}
