<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Service\LoginService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

class LoginController extends AbstractController
{
    public function __construct(
        private LoginService $loginService
    ) {
    }

    #[Route('/login', name: 'login', methods: ['GET', 'POST'])]
    public function login(Request $request): Response
    {
        if ($request->isMethod('POST')) {

            $username = $request->request->get('username');
            $password = $request->request->get('password');

            $result = $this->loginService->login($username, $password);

            if ($result['success'] === true) {
                return $this->redirectToRoute('home');
            }

            return $this->render('login/login.html.twig', [
                'error' => $result['message']
            ]);
        }

        return $this->render('login/login.html.twig');
    }
}