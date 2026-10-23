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
    // Patient permissions
    public const PATIENT_VIEW = 'patient.view';
    public const PATIENT_CREATE = 'patient.create';
    public const PATIENT_EDIT = 'patient.edit';
    public const PATIENT_STATUS = 'patient.status';

    // Front Desk Management permissions
    public const FDM_VIEW = 'fdm.view';
    public const FDM_APPOINTMENT_CREATE = 'fdm.appointment.create';
    public const FDM_APPOINTMENT_EDIT = 'fdm.appointment.edit';
    public const FDM_APPOINTMENT_CANCEL = 'fdm.appointment.cancel';
    public const FDM_CHECK_IN = 'fdm.check_in';
    public const FDM_QUEUE_UPDATE = 'fdm.queue.update';
    public const FDM_REPORTS = 'fdm.reports';

    // OP Consultation permissions
    public const OPC_VIEW = 'opc.view';
    public const OPC_MANAGE = 'opc.manage';

    private const DEFINED_PERMISSIONS = [
        self::PATIENT_VIEW,
        self::PATIENT_CREATE,
        self::PATIENT_EDIT,
        self::PATIENT_STATUS,
        self::FDM_VIEW,
        self::FDM_APPOINTMENT_CREATE,
        self::FDM_APPOINTMENT_EDIT,
        self::FDM_APPOINTMENT_CANCEL,
        self::FDM_CHECK_IN,
        self::FDM_QUEUE_UPDATE,
        self::FDM_REPORTS,
        self::OPC_VIEW,
        self::OPC_MANAGE,
    ];

    public function __construct(
        private AuthSessionService $authSession,
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
