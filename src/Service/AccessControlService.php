<?php

namespace App\Service;

/**
 * Single place that decides whether the current user may perform an action.
 *
 * Current policy: every authenticated user holds every defined permission
 * (the users table has no role column yet). An unknown permission name is
 * always denied, so a typo can never silently open an action.
 *
 * When roles are introduced, only isGranted() changes. Routes and
 * controllers keep asking for the same permission names.
 */
class AccessControlService
{
    public const PATIENT_VIEW   = 'patient.view';
    public const PATIENT_CREATE = 'patient.create';
    public const PATIENT_EDIT   = 'patient.edit';
    public const PATIENT_STATUS = 'patient.status';

    private const DEFINED_PERMISSIONS = [
        self::PATIENT_VIEW,
        self::PATIENT_CREATE,
        self::PATIENT_EDIT,
        self::PATIENT_STATUS,
    ];

    public function __construct(
        private AuthSessionService $authSession
    ) {
    }

    public function isGranted(string $permission): bool
    {
        if (!$this->authSession->isAuthenticated()) {
            return false;
        }

        return in_array($permission, self::DEFINED_PERMISSIONS, true);
    }
}