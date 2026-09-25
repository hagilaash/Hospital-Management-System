<?php

namespace App\Controller;

use App\Service\HomeService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class HomeController extends AbstractController
{
    public function __construct(
        private HomeService $homeService
    ) {
    }

    #[Route('/home', name: 'home', methods: ['GET'])]
    public function home(): Response
    {
        $modules = $this->homeService->getHomeModules();

        return $this->render('home/dashboard.html.twig', [
            'modules' => $modules
        ]);
    }
}