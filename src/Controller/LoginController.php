<?php

namespace App\Controller;

use App\Service\AuthSessionService;
use App\Service\LoginService;
use Doctrine\DBAL\Exception as DbalException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class LoginController extends AbstractController
{
    public function __construct(
        private LoginService $loginService,
        private AuthSessionService $authSession,
        private LoggerInterface $logger
    ) {
    }

    #[Route('/login', name: 'login', methods: ['GET', 'POST'])]
    public function login(Request $request): Response
    {
        if ($this->authSession->isAuthenticated()) {
            return $this->redirectToRoute('home');
        }

        if (!$request->isMethod('POST')) {
            return $this->render('login/login.html.twig');
        }

        if (!$this->isCsrfTokenValid('login', $request->request->getString('_token'))) {
            return $this->render('login/login.html.twig', [
                'error' => 'Your session has expired. Please try again.',
            ], new Response('', Response::HTTP_BAD_REQUEST));
        }

        $username = $request->request->getString('username');
        $password = $request->request->getString('password');

        if ($username === '' || $password === '') {
            return $this->render('login/login.html.twig', [
                'error' => 'Username and password are required.',
                'username' => $username,
            ]);
        }

        try {
            $result = $this->loginService->login($username, $password);
        } catch (DbalException $exception) {
            $this->logger->error('Login failed because of a database error.', ['exception' => $exception]);

            return $this->render('login/login.html.twig', [
                'error' => 'The service is temporarily unavailable. Please try again later.',
                'username' => $username,
            ], new Response('', Response::HTTP_SERVICE_UNAVAILABLE));
        }

        if (($result['success'] ?? false) === true) {
            $this->authSession->login($username);

            return $this->redirectToRoute('home');
        }

        return $this->render('login/login.html.twig', [
            'error' => $result['message'] ?? 'Invalid username or password',
            'username' => $username,
        ]);
    }

    #[Route('/logout', name: 'logout', methods: ['POST'])]
    public function logout(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('logout', $request->request->getString('_token'))) {
            $this->addFlash('error', 'Your session has expired. Please try again.');

            return $this->redirectToRoute('home');
        }

        $this->authSession->logout();
        $this->addFlash('success', 'You have been logged out.');

        return $this->redirectToRoute('login');
    }
}