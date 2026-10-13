<?php

namespace App\EventListener;

use App\Service\AccessControlService;
use App\Service\AuthSessionService;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Deny-by-default gate for the whole application.
 *
 * - Every route requires a logged-in user, except the ones in PUBLIC_ROUTES.
 * - A route can additionally require a permission by declaring
 *   defaults: ['_permission' => 'patient.view'] in its #[Route] attribute.
 *   The permission is checked through AccessControlService.
 */
class AuthenticationListener
{
    /** Route names reachable without logging in. */
    private const PUBLIC_ROUTES = ['login'];

    public function __construct(
        private AuthSessionService $authSession,
        private AccessControlService $accessControl,
        private UrlGeneratorInterface $urlGenerator
    ) {
    }

    // Priority 5 (< 32) so the router has already set the _route attribute.
    #[AsEventListener(event: KernelEvents::REQUEST, priority: 5)]
    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $route = $request->attributes->get('_route');

        // No matched route (404 is raised earlier) or a Symfony internal
        // route such as _error / _wdt / _profiler.
        if (!is_string($route) || $route === '' || str_starts_with($route, '_')) {
            return;
        }

        if (in_array($route, self::PUBLIC_ROUTES, true)) {
            return;
        }

        if (!$this->authSession->isAuthenticated()) {
            $event->setResponse(new RedirectResponse($this->urlGenerator->generate('login')));

            return;
        }

        $permission = $request->attributes->get('_permission');
        if (is_string($permission) && !$this->accessControl->isGranted($permission)) {
            throw new AccessDeniedHttpException('You do not have permission to perform this action.');
        }
    }

    // Pages shown to a logged-in user may contain patient data: ask browsers
    // and proxies not to store them, so the Back button after logout does
    // not reveal them.
    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        // true = do not start a session just to look at it.
        if (!$request->hasSession(true) || !$this->authSession->isAuthenticated()) {
            return;
        }

        $event->getResponse()->headers->addCacheControlDirective('no-store');
    }
}