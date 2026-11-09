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

    // Billing permissions
    public const BILLING_VIEW = 'billing.view';
    public const BILLING_CREATE = 'billing.create';
    public const BILLING_PAYMENT = 'billing.payment';
    public const BILLING_CANCEL = 'billing.cancel';

    // RCM permissions
    public const RCM_VIEW = 'rcm.view';
    public const RCM_FINALIZE = 'rcm.finalize';

    // Pharmacy permissions
    public const PHARMACY_VIEW = 'pharmacy.view';
    public const PHARMACY_MANAGE = 'pharmacy.manage';
    public const PHARMACY_DISPENSE = 'pharmacy.dispense';
    public const PHARMACY_PRESCRIBE = 'pharmacy.prescribe';

    // Laboratory permissions
    public const LAB_VIEW = 'lab.view';
    public const LAB_MANAGE = 'lab.manage';
    public const LAB_COLLECT = 'lab.collect';
    public const LAB_ORDER = 'lab.order';
    public const LAB_RESULT = 'lab.result';

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
        self::BILLING_VIEW,
        self::BILLING_CREATE,
        self::BILLING_PAYMENT,
        self::BILLING_CANCEL,
        self::RCM_VIEW,
        self::RCM_FINALIZE,
        self::PHARMACY_VIEW,
        self::PHARMACY_MANAGE,
        self::PHARMACY_DISPENSE,
        self::PHARMACY_PRESCRIBE,
        self::LAB_VIEW,
        self::LAB_MANAGE,
        self::LAB_COLLECT,
        self::LAB_ORDER,
        self::LAB_RESULT,
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
