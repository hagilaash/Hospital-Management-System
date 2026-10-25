<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

class AuthSessionService
{
    private const KEY_USERNAME = 'auth.username';
    private const KEY_LOGIN_AT = 'auth.login_at';

    public function __construct(
        private RequestStack $requestStack,
    ) {
    }

    /**
     * Marks the current session as authenticated.
     * The session ID is regenerated first so an ID known before login
     * (session fixation) is useless afterwards.
     */
    public function login(string $username): void
    {
        $session = $this->requestStack->getSession();
        $session->migrate(true);
        $session->set(self::KEY_USERNAME, $username);
        $session->set(self::KEY_LOGIN_AT, time());
    }

    /**
     * Destroys the session completely (data + cookie ID).
     */
    public function logout(): void
    {
        $this->requestStack->getSession()->invalidate();
    }

    public function isAuthenticated(): bool
    {
        return null !== $this->getUsername();
    }

    public function getUsername(): ?string
    {
        $session = $this->currentSession();
        if (null === $session) {
            return null;
        }

        $username = $session->get(self::KEY_USERNAME);

        return is_string($username) && '' !== $username ? $username : null;
    }

    private function currentSession(): ?SessionInterface
    {
        $request = $this->requestStack->getCurrentRequest();

        return null !== $request && $request->hasSession() ? $request->getSession() : null;
    }
}
