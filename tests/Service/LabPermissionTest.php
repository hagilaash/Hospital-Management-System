<?php

namespace App\Tests\Service;

use App\Service\AccessControlService;
use App\Service\AuthSessionService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Permission checks for the Laboratory. Policy today: any authenticated user holds every DEFINED
 * permission; unknown names and anonymous users are always denied.
 */
class LabPermissionTest extends TestCase
{
    private AuthSessionService&MockObject $session;

    protected function setUp(): void
    {
        $this->session = $this->createMock(AuthSessionService::class);
    }

    public function testTheLabPermissionsHaveTheConventionalNames(): void
    {
        $this->assertSame('lab.view', AccessControlService::LAB_VIEW);
        $this->assertSame('lab.manage', AccessControlService::LAB_MANAGE);
        $this->assertSame('lab.order', AccessControlService::LAB_ORDER);
        $this->assertSame('lab.collect', AccessControlService::LAB_COLLECT);
        $this->assertSame('lab.result', AccessControlService::LAB_RESULT);
    }

    public function testASignedInUserHoldsEveryLabPermission(): void
    {
        $this->session->method('isAuthenticated')->willReturn(true);
        $access = new AccessControlService($this->session);

        foreach ([AccessControlService::LAB_VIEW, AccessControlService::LAB_MANAGE, AccessControlService::LAB_ORDER, AccessControlService::LAB_COLLECT, AccessControlService::LAB_RESULT] as $permission) {
            $this->assertTrue($access->isGranted($permission), $permission);
        }
    }

    public function testAnAnonymousUserHoldsNone(): void
    {
        $this->session->method('isAuthenticated')->willReturn(false);
        $access = new AccessControlService($this->session);

        $this->assertFalse($access->isGranted(AccessControlService::LAB_VIEW));
        $this->assertFalse($access->isGranted(AccessControlService::LAB_RESULT));
        $this->assertFalse($access->isGranted(AccessControlService::LAB_ORDER));
    }

    public function testATypoInAPermissionNameIsDenied(): void
    {
        $this->session->method('isAuthenticated')->willReturn(true);
        $access = new AccessControlService($this->session);

        $this->assertFalse($access->isGranted('lab.results'));
        $this->assertFalse($access->isGranted('lab.'));
    }

    public function testEarlierModulesKeepTheirPermissions(): void
    {
        $this->session->method('isAuthenticated')->willReturn(true);
        $access = new AccessControlService($this->session);

        foreach ([AccessControlService::FDM_VIEW, AccessControlService::BILLING_VIEW, AccessControlService::RCM_VIEW, AccessControlService::PHARMACY_VIEW, AccessControlService::OPC_VIEW] as $permission) {
            $this->assertTrue($access->isGranted($permission), $permission);
        }
    }
}
