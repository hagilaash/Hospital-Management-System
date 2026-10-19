<?php

namespace App\Tests\Service;

use App\DTO\AppointmentDTO;
use App\DTO\DoctorDTO;
use App\DTO\FDMDashboardDTO;
use App\DTO\PatientDTO;
use App\DTO\PatientListDTO;
use App\Repository\FDMRepository;
use App\Service\DoctorService;
use App\Service\FDMService;
use App\Service\PatientService;
use Doctrine\DBAL\Exception as DbalException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for FDMService. Repository, PatientService, DoctorService and the
 * logger are mocked, so nothing here touches PostgreSQL.
 */
class FDMServiceTest extends TestCase
{
    private const TODAY = '2026-10-18';

    private FDMRepository&MockObject $repository;
    private PatientService&MockObject $patientService;
    private DoctorService&MockObject $doctorService;
    private LoggerInterface&MockObject $logger;
    private FDMService $service;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(FDMRepository::class);
        $this->patientService = $this->createMock(PatientService::class);
        $this->doctorService = $this->createMock(DoctorService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->repository->method('getHospitalToday')->willReturn(self::TODAY);

        $this->service = new FDMService($this->repository, $this->patientService, $this->doctorService, $this->logger);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function validAppointment(): AppointmentDTO
    {
        $appointment = new AppointmentDTO();
        $appointment->patientId = 5;
        $appointment->doctorId = 3;
        $appointment->appointmentDate = '2026-11-20';
        $appointment->startTime = '10:00';
        $appointment->durationInput = '15';
        $appointment->durationMinutes = 15;
        $appointment->reason = 'Checkup';

        return $appointment;
    }

    private function created(): array
    {
        return [
            'success' => true,
            'message' => 'Appointment created successfully',
            'appointment_id' => 9,
            'appointment_code' => 'APT-2026-000009',
        ];
    }

    private function refused(string $message): array
    {
        return ['success' => false, 'message' => $message, 'appointment_id' => null, 'appointment_code' => null];
    }

    private function dbalException(): DbalException
    {
        return new class('database is down') extends \Exception implements DbalException {
        };
    }

    // ------------------------------------------------------------------
    // createAppointment
    // ------------------------------------------------------------------

    public function testCreateNormalisesInputAndCallsRepository(): void
    {
        $input = $this->validAppointment();
        $input->appointmentDate = ' 2026-11-20 ';
        $input->startTime = '10:00:00';
        $input->durationInput = ' 30 ';
        $input->reason = '  Follow   up  visit ';

        $captured = null;
        $this->repository->expects($this->once())
            ->method('createAppointment')
            ->with($this->isInstanceOf(AppointmentDTO::class), 'admin')
            ->willReturnCallback(function (AppointmentDTO $appointment) use (&$captured): array {
                $captured = $appointment;

                return $this->created();
            });

        $result = $this->service->createAppointment($input, ' admin ');

        $this->assertInstanceOf(AppointmentDTO::class, $captured);
        $this->assertSame('2026-11-20', $captured->appointmentDate);
        $this->assertSame('10:00', $captured->startTime);
        $this->assertSame(30, $captured->durationMinutes);
        $this->assertSame('Follow up visit', $captured->reason);
        $this->assertSame(5, $captured->patientId);
        $this->assertSame(3, $captured->doctorId);

        // What the user typed is untouched, so the form can be shown again.
        $this->assertSame(' 2026-11-20 ', $input->appointmentDate);
        $this->assertSame('10:00:00', $input->startTime);

        $this->assertTrue($result['success']);
        $this->assertSame(9, $result['appointment_id']);
        $this->assertSame('APT-2026-000009', $result['appointment_code']);
        $this->assertSame([], $result['errors']);
    }

    public function testCreateTurnsAnEmptyReasonIntoNull(): void
    {
        $input = $this->validAppointment();
        $input->reason = '   ';

        $captured = null;
        $this->repository->expects($this->once())
            ->method('createAppointment')
            ->willReturnCallback(function (AppointmentDTO $appointment) use (&$captured): array {
                $captured = $appointment;

                return $this->created();
            });

        $this->service->createAppointment($input, 'admin');

        $this->assertNull($captured->reason);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    #[DataProvider('invalidAppointmentProvider')]
    public function testCreateRejectsInvalidInputWithoutCallingTheRepository(array $overrides, string $expectedField): void
    {
        $input = $this->validAppointment();
        foreach ($overrides as $property => $value) {
            $input->$property = $value;
        }

        $this->repository->expects($this->never())->method('createAppointment');

        $result = $this->service->createAppointment($input, 'admin');

        $this->assertFalse($result['success']);
        $this->assertSame('Please correct the errors below', $result['message']);
        $this->assertArrayHasKey($expectedField, $result['errors']);
        $this->assertNotSame('', $result['errors'][$expectedField]);
        $this->assertNull($result['appointment_id']);
    }

    public static function invalidAppointmentProvider(): array
    {
        return [
            'no patient'                 => [['patientId' => null], 'patientId'],
            'patient id zero'            => [['patientId' => 0], 'patientId'],
            'no doctor'                  => [['doctorId' => null], 'doctorId'],
            'date blank'                 => [['appointmentDate' => '  '], 'appointmentDate'],
            'date not a date'            => [['appointmentDate' => 'tomorrow'], 'appointmentDate'],
            'date wrong format'          => [['appointmentDate' => '20/11/2026'], 'appointmentDate'],
            'date impossible'            => [['appointmentDate' => '2026-02-31'], 'appointmentDate'],
            'date invalid UTF-8'         => [['appointmentDate' => "\xB1\x31"], 'appointmentDate'],
            'time blank'                 => [['startTime' => ''], 'startTime'],
            'time out of range'          => [['startTime' => '25:00'], 'startTime'],
            'time not a time'            => [['startTime' => 'noon'], 'startTime'],
            'time not on a 5-min mark'   => [['startTime' => '10:07'], 'startTime'],
            'time with seconds'          => [['startTime' => '10:00:30'], 'startTime'],
            'duration blank'             => [['durationInput' => ''], 'durationMinutes'],
            'duration not a multiple'    => [['durationInput' => '7'], 'durationMinutes'],
            'duration too short'         => [['durationInput' => '0'], 'durationMinutes'],
            'duration too long'          => [['durationInput' => '125'], 'durationMinutes'],
            'duration not a number'      => [['durationInput' => 'abc'], 'durationMinutes'],
            'duration negative'          => [['durationInput' => '-5'], 'durationMinutes'],
            'reason too long'            => [['reason' => str_repeat('x', 256)], 'reason'],
        ];
    }

    public function testCreateReportsEveryInvalidFieldAtOnce(): void
    {
        $this->repository->expects($this->never())->method('createAppointment');

        $result = $this->service->createAppointment(new AppointmentDTO(), 'admin');

        foreach (['patientId', 'doctorId', 'appointmentDate', 'startTime'] as $field) {
            $this->assertArrayHasKey($field, $result['errors']);
        }
    }

    public function testCreateAcceptsEdgeValues(): void
    {
        $this->repository->expects($this->exactly(3))
            ->method('createAppointment')
            ->willReturn($this->created());

        foreach ([['durationInput' => '5'], ['durationInput' => '120'], ['startTime' => '23:55']] as $overrides) {
            $input = $this->validAppointment();
            foreach ($overrides as $property => $value) {
                $input->$property = $value;
            }

            $this->assertTrue($this->service->createAppointment($input, 'admin')['success'], json_encode($overrides));
        }
    }

    public function testCreateRequiresASignedInUser(): void
    {
        $this->repository->expects($this->never())->method('createAppointment');

        $result = $this->service->createAppointment($this->validAppointment(), '   ');

        $this->assertFalse($result['success']);
        $this->assertSame('You must be signed in to perform this action', $result['message']);
    }

    public function testCreateAttachesAnOverlapRefusalToTheTimeField(): void
    {
        $message = 'Doctor already has an appointment from 10:00 to 10:15 (APT-2026-000001)';
        $this->repository->method('createAppointment')->willReturn($this->refused($message));

        $result = $this->service->createAppointment($this->validAppointment(), 'admin');

        $this->assertFalse($result['success']);
        $this->assertSame($message, $result['message']);
        $this->assertSame(['startTime' => $message], $result['errors']);
    }

    #[DataProvider('databaseMessageProvider')]
    public function testDatabaseRefusalsAreAttachedToTheRightField(string $message, ?string $expectedField): void
    {
        $this->repository->method('createAppointment')->willReturn($this->refused($message));

        $result = $this->service->createAppointment($this->validAppointment(), 'admin');

        $this->assertFalse($result['success']);
        if ($expectedField === null) {
            $this->assertSame([], $result['errors']);
        } else {
            $this->assertSame([$expectedField => $message], $result['errors']);
        }
    }

    public static function databaseMessageProvider(): array
    {
        return [
            'patient missing'    => ['Patient not found', 'patientId'],
            'patient inactive'   => ['Inactive patients cannot be given appointments', 'patientId'],
            'doctor missing'     => ['Doctor not found', 'doctorId'],
            'doctor inactive'    => ['Doctor is not active', 'doctorId'],
            'in the past'        => ['Appointment time cannot be in the past', 'startTime'],
            'boundary'           => ['Appointment time must be on a 5-minute boundary (for example 10:00 or 10:05)', 'startTime'],
            'midnight'           => ['Appointment must finish before midnight', 'startTime'],
            'too far ahead'      => ['Appointments can be booked at most 365 days ahead', 'appointmentDate'],
            'duration'           => ['Duration must be between 5 and 120 minutes, in steps of 5', 'durationMinutes'],
            'reason'             => ['Reason must be 255 characters or fewer', 'reason'],
            'general message'    => ['One or more values are not valid', null],
        ];
    }

    public function testResultToleratesTextBooleansFromTheDriver(): void
    {
        $this->repository->method('createAppointment')->willReturn([
            'success' => 't',
            'message' => 'Appointment created successfully',
            'appointment_id' => '12',
            'appointment_code' => 'APT-2026-000012',
        ]);

        $result = $this->service->createAppointment($this->validAppointment(), 'admin');

        $this->assertTrue($result['success']);
        $this->assertSame(12, $result['appointment_id']);
    }

    // ------------------------------------------------------------------
    // updateAppointment
    // ------------------------------------------------------------------

    public function testUpdatePassesTheIdAndDoesNotNeedAPatient(): void
    {
        $input = $this->validAppointment();
        $input->patientId = null; // editing never changes the patient

        $this->repository->expects($this->once())
            ->method('updateAppointment')
            ->with(7, $this->isInstanceOf(AppointmentDTO::class), 'admin')
            ->willReturn(['success' => true, 'message' => 'Appointment updated successfully', 'appointment_id' => 7, 'appointment_code' => 'APT-2026-000007']);

        $result = $this->service->updateAppointment(7, $input, 'admin');

        $this->assertTrue($result['success']);
        $this->assertSame(7, $result['appointment_id']);
    }

    public function testUpdateRejectsInvalidInputAndBadIds(): void
    {
        $this->repository->expects($this->never())->method('updateAppointment');

        $bad = $this->validAppointment();
        $bad->durationInput = '7';
        $invalid = $this->service->updateAppointment(7, $bad, 'admin');
        $this->assertArrayHasKey('durationMinutes', $invalid['errors']);

        $notFound = $this->service->updateAppointment(0, $this->validAppointment(), 'admin');
        $this->assertSame('Appointment not found', $notFound['message']);

        $noUser = $this->service->updateAppointment(7, $this->validAppointment(), '');
        $this->assertFalse($noUser['success']);
    }

    public function testUpdatePassesTheEditingRefusalThrough(): void
    {
        $this->repository->method('updateAppointment')->willReturn($this->refused('Only scheduled appointments can be changed'));

        $result = $this->service->updateAppointment(7, $this->validAppointment(), 'admin');

        $this->assertFalse($result['success']);
        $this->assertSame('Only scheduled appointments can be changed', $result['message']);
        $this->assertSame([], $result['errors']);
    }

    // ------------------------------------------------------------------
    // cancelAppointment and no-show
    // ------------------------------------------------------------------

    public function testCancelNeedsAReason(): void
    {
        $this->repository->expects($this->never())->method('cancelAppointment');

        foreach (['', '   ', null] as $reason) {
            $result = $this->service->cancelAppointment(7, $reason, 'admin');

            $this->assertFalse($result['success']);
            $this->assertSame('Cancellation reason is required', $result['message']);
            $this->assertArrayHasKey('reason', $result['errors']);
        }
    }

    public function testCancelRejectsAnOverLongReason(): void
    {
        $this->repository->expects($this->never())->method('cancelAppointment');

        $result = $this->service->cancelAppointment(7, str_repeat('x', 256), 'admin');

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('reason', $result['errors']);
    }

    public function testCancelPassesACleanedReasonToTheRepository(): void
    {
        $this->repository->expects($this->once())
            ->method('cancelAppointment')
            ->with(7, 'Patient called to cancel', 'admin')
            ->willReturn(['success' => true, 'message' => 'Appointment cancelled successfully']);

        $result = $this->service->cancelAppointment(7, "  Patient   called\tto cancel ", 'admin');

        $this->assertTrue($result['success']);
        $this->assertSame('Appointment cancelled successfully', $result['message']);
    }

    public function testCancelPassesTheStatusRefusalThrough(): void
    {
        $this->repository->method('cancelAppointment')
            ->willReturn(['success' => false, 'message' => 'Checked-in appointments must be cancelled from the consultation queue']);

        $result = $this->service->cancelAppointment(7, 'Changed mind', 'admin');

        $this->assertFalse($result['success']);
        $this->assertSame('Checked-in appointments must be cancelled from the consultation queue', $result['message']);
        $this->assertSame([], $result['errors']);
    }

    public function testCancelAndNoShowRejectBadIdsAndMissingUser(): void
    {
        $this->repository->expects($this->never())->method('cancelAppointment');
        $this->repository->expects($this->never())->method('markAppointmentNoShow');

        $this->assertSame('Appointment not found', $this->service->cancelAppointment(0, 'x', 'admin')['message']);
        $this->assertFalse($this->service->cancelAppointment(7, 'x', ' ')['success']);
        $this->assertSame('Appointment not found', $this->service->markNoShow(-1, 'admin')['message']);
        $this->assertFalse($this->service->markNoShow(7, '')['success']);
    }

    public function testMarkNoShowPassesTheDatabaseResultThrough(): void
    {
        $this->repository->expects($this->exactly(2))
            ->method('markAppointmentNoShow')
            ->with(7, 'admin')
            ->willReturnOnConsecutiveCalls(
                ['success' => true, 'message' => 'Appointment marked as no-show'],
                ['success' => false, 'message' => 'The appointment time has not passed yet']
            );

        $this->assertTrue($this->service->markNoShow(7, 'admin')['success']);
        $this->assertSame('The appointment time has not passed yet', $this->service->markNoShow(7, 'admin')['message']);
    }

    // ------------------------------------------------------------------
    // Check-in
    // ------------------------------------------------------------------

    public function testCheckInReturnsTheTokenTheDatabaseGenerated(): void
    {
        $this->repository->expects($this->once())
            ->method('checkInAppointment')
            ->with(7, 'admin')
            ->willReturn(['success' => true, 'message' => 'Patient checked in successfully', 'queue_id' => 31, 'token_number' => 4]);

        $result = $this->service->checkInAppointment(7, 'admin');

        $this->assertTrue($result['success']);
        $this->assertSame(31, $result['queue_id']);
        $this->assertSame(4, $result['token_number']);
    }

    public function testDuplicateCheckInIsRefusedAndPointsToTheExistingEntry(): void
    {
        $this->repository->method('checkInAppointment')->willReturn([
            'success' => false,
            'message' => 'Patient is already checked in for this appointment',
            'queue_id' => 31,
            'token_number' => 4,
        ]);

        $result = $this->service->checkInAppointment(7, 'admin');

        $this->assertFalse($result['success']);
        $this->assertSame('Patient is already checked in for this appointment', $result['message']);
        $this->assertSame(31, $result['queue_id']);
        $this->assertSame(4, $result['token_number']);
    }

    public function testCheckInRejectsBadIdsAndMissingUser(): void
    {
        $this->repository->expects($this->never())->method('checkInAppointment');

        $this->assertSame('Appointment not found', $this->service->checkInAppointment(0, 'admin')['message']);
        $this->assertFalse($this->service->checkInAppointment(7, '  ')['success']);
    }

    public function testWalkInPassesPatientDoctorAndUser(): void
    {
        $this->repository->expects($this->once())
            ->method('checkInWalkIn')
            ->with(5, 3, 'admin')
            ->willReturn(['success' => true, 'message' => 'Walk-in patient checked in successfully', 'queue_id' => 40, 'token_number' => 1]);

        $result = $this->service->checkInWalkIn(5, 3, ' admin ');

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['token_number']);
    }

    public function testWalkInValidatesBothIds(): void
    {
        $this->repository->expects($this->never())->method('checkInWalkIn');

        $result = $this->service->checkInWalkIn(0, 0, 'admin');

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('patientId', $result['errors']);
        $this->assertArrayHasKey('doctorId', $result['errors']);
    }

    public function testWalkInRefusalForAnExistingScheduledAppointmentIsPassedThrough(): void
    {
        $message = 'Patient has a scheduled appointment today with this doctor (APT-2026-000001). Check in against the appointment instead';
        $this->repository->method('checkInWalkIn')
            ->willReturn(['success' => false, 'message' => $message, 'queue_id' => null, 'token_number' => null]);

        $result = $this->service->checkInWalkIn(5, 3, 'admin');

        $this->assertFalse($result['success']);
        $this->assertSame($message, $result['message']);
        $this->assertNull($result['token_number']);
    }

    // ------------------------------------------------------------------
    // Queue status
    // ------------------------------------------------------------------

    public function testQueueStatusRejectsStatusesThatAreNotActions(): void
    {
        $this->repository->expects($this->never())->method('updateQueueStatus');

        foreach (['Waiting', 'Done', '', 'completed'] as $status) {
            $result = $this->service->updateQueueStatus(31, $status, 'admin');

            $this->assertFalse($result['success'], $status);
            $this->assertSame('Invalid queue status', $result['message']);
        }
    }

    public function testQueueCancelNeedsAReason(): void
    {
        $this->repository->expects($this->never())->method('updateQueueStatus');

        $result = $this->service->updateQueueStatus(31, 'Cancelled', 'admin', '  ');

        $this->assertFalse($result['success']);
        $this->assertSame('Cancellation reason is required', $result['message']);
        $this->assertArrayHasKey('reason', $result['errors']);
    }

    public function testQueueCancelPassesTheReason(): void
    {
        $this->repository->expects($this->once())
            ->method('updateQueueStatus')
            ->with(31, 'Cancelled', 'admin', 'Left without being seen')
            ->willReturn(['success' => true, 'message' => 'Queue entry cancelled']);

        $this->assertTrue($this->service->updateQueueStatus(31, 'Cancelled', 'admin', ' Left without   being seen ')['success']);
    }

    public function testOtherQueueActionsNeverCarryAReason(): void
    {
        $this->repository->expects($this->exactly(2))
            ->method('updateQueueStatus')
            ->willReturnCallback(function (int $id, string $status, string $by, ?string $reason): array {
                $this->assertNull($reason, $status);

                return ['success' => true, 'message' => 'ok'];
            });

        $this->service->updateQueueStatus(31, 'In Consultation', 'admin', 'ignored');
        $this->service->updateQueueStatus(31, 'Completed', 'admin', 'ignored');
    }

    public function testQueueTransitionRefusalIsPassedThrough(): void
    {
        $this->repository->method('updateQueueStatus')
            ->willReturn(['success' => false, 'message' => 'Cannot change status from Waiting to Completed']);

        $result = $this->service->updateQueueStatus(31, 'Completed', 'admin');

        $this->assertFalse($result['success']);
        $this->assertSame('Cannot change status from Waiting to Completed', $result['message']);
    }

    public function testQueueStatusRejectsBadIdAndMissingUser(): void
    {
        $this->repository->expects($this->never())->method('updateQueueStatus');

        $this->assertSame('Queue entry not found', $this->service->updateQueueStatus(0, 'Completed', 'admin')['message']);
        $this->assertFalse($this->service->updateQueueStatus(31, 'Completed', '')['success']);
    }

    // ------------------------------------------------------------------
    // Appointment list
    // ------------------------------------------------------------------

    public function testListDefaultsToTodayAllStatusesFirstPage(): void
    {
        $this->repository->expects($this->once())
            ->method('countAppointments')
            ->with(self::TODAY, self::TODAY, null, null, 'all', null)
            ->willReturn(25);
        $this->repository->expects($this->once())
            ->method('getAppointmentsList')
            ->with(self::TODAY, self::TODAY, null, null, 'all', null, 10, 0)
            ->willReturn(array_fill(0, 10, new AppointmentDTO()));

        $list = $this->service->getAppointments(null, null, null, null, null, null);

        $this->assertSame(self::TODAY, $list->dateFrom);
        $this->assertSame(self::TODAY, $list->dateTo);
        $this->assertSame(self::TODAY, $list->today);
        $this->assertSame('all', $list->status);
        $this->assertSame(25, $list->total);
        $this->assertSame(3, $list->totalPages);
        $this->assertSame(1, $list->page);
        $this->assertSame(10, $list->perPage);
        $this->assertTrue($list->hasNextPage());
        $this->assertFalse($list->hasPreviousPage());
        $this->assertSame(1, $list->getFirstItemNumber());
        $this->assertSame(10, $list->getLastItemNumber());
    }

    public function testClearedDatesMeanNoLimit(): void
    {
        $this->repository->expects($this->once())
            ->method('countAppointments')
            ->with(null, null, null, null, 'all', null)
            ->willReturn(0);

        $list = $this->service->getAppointments('', '  ', null, null, null, null);

        $this->assertNull($list->dateFrom);
        $this->assertNull($list->dateTo);
    }

    public function testAnInvalidDateFallsBackToToday(): void
    {
        $this->repository->expects($this->once())
            ->method('countAppointments')
            ->with(self::TODAY, '2026-11-02', null, null, 'all', null)
            ->willReturn(0);

        $this->service->getAppointments('2026-02-31', '2026-11-02', null, null, null, null);
    }

    public function testAReversedRangeIsSwapped(): void
    {
        $this->repository->expects($this->once())
            ->method('countAppointments')
            ->with('2026-10-01', '2026-10-31', null, null, 'all', null)
            ->willReturn(0);

        $list = $this->service->getAppointments('2026-10-31', '2026-10-01', null, null, null, null);

        $this->assertSame('2026-10-01', $list->dateFrom);
        $this->assertSame('2026-10-31', $list->dateTo);
    }

    #[DataProvider('appointmentStatusProvider')]
    public function testListNormalisesTheStatusFilter(?string $given, string $expected): void
    {
        $this->repository->expects($this->once())
            ->method('countAppointments')
            ->with(self::TODAY, self::TODAY, null, null, $expected, null)
            ->willReturn(0);

        $this->assertSame($expected, $this->service->getAppointments(null, null, null, null, $given, null)->status);
    }

    public static function appointmentStatusProvider(): array
    {
        return [
            'exact'              => ['Scheduled', 'Scheduled'],
            'lower case'         => ['scheduled', 'Scheduled'],
            'upper case'         => ['CHECKED-IN', 'Checked-In'],
            'no-show'            => ['no-show', 'No-Show'],
            'cancelled spaced'   => ['  Cancelled ', 'Cancelled'],
            'all'                => ['all', 'all'],
            'unknown'            => ['Deleted', 'all'],
            'injection attempt'  => ["Scheduled' OR 1=1 --", 'all'],
            'empty'              => ['', 'all'],
            'null'               => [null, 'all'],
        ];
    }

    #[DataProvider('idFilterProvider')]
    public function testListParsesDoctorAndPatientFilters(?string $given, ?int $expected): void
    {
        $this->repository->expects($this->once())
            ->method('countAppointments')
            ->with(self::TODAY, self::TODAY, $expected, $expected, 'all', null)
            ->willReturn(0);

        $list = $this->service->getAppointments(null, null, $given, $given, null, null);

        $this->assertSame($expected, $list->doctorId);
        $this->assertSame($expected, $list->patientId);
    }

    public static function idFilterProvider(): array
    {
        return [
            'a number'      => ['5', 5],
            'blank'         => ['', null],
            'null'          => [null, null],
            'zero'          => ['0', null],
            'negative'      => ['-1', null],
            'not a number'  => ['abc', null],
            'decimal'       => ['5.5', null],
            'too many digits' => ['12345678901', null],
        ];
    }

    public function testListMovesAnOutOfRangePageToTheLastPage(): void
    {
        $this->repository->method('countAppointments')->willReturn(25);
        $this->repository->expects($this->once())
            ->method('getAppointmentsList')
            ->with(self::TODAY, self::TODAY, null, null, 'all', null, 10, 20)
            ->willReturn(array_fill(0, 5, new AppointmentDTO()));

        $list = $this->service->getAppointments(null, null, null, null, null, null, 99);

        $this->assertSame(3, $list->page);
        $this->assertSame(21, $list->getFirstItemNumber());
        $this->assertSame(25, $list->getLastItemNumber());
    }

    public function testListSkipsTheQueryWhenThereAreNoAppointments(): void
    {
        $this->repository->method('countAppointments')->willReturn(0);
        $this->repository->expects($this->never())->method('getAppointmentsList');

        $list = $this->service->getAppointments(null, null, null, null, null, 'nobody');

        $this->assertSame(0, $list->total);
        $this->assertSame(1, $list->page);
        $this->assertSame(1, $list->totalPages);
        $this->assertSame([], $list->appointments);
        $this->assertSame(0, $list->getFirstItemNumber());
        $this->assertSame(0, $list->getLastItemNumber());
    }

    #[DataProvider('searchProvider')]
    public function testSearchIsCleanedAndLimited(?string $given, ?string $expected): void
    {
        $this->repository->expects($this->once())
            ->method('countAppointments')
            ->with(self::TODAY, self::TODAY, null, null, 'all', $expected)
            ->willReturn(0);

        $this->assertSame($expected, $this->service->getAppointments(null, null, null, null, null, $given)->search);
    }

    public static function searchProvider(): array
    {
        return [
            'null'                  => [null, null],
            'blank'                 => ['   ', null],
            'trimmed and collapsed' => ['  anita   raman ', 'anita raman'],
            'appointment number'    => ['APT-2026-000001', 'APT-2026-000001'],
            'truncated to 100'      => [str_repeat('a', 150), str_repeat('a', 100)],
            'invalid UTF-8'         => ["\xB1\x31", null],
        ];
    }

    // ------------------------------------------------------------------
    // Queue list
    // ------------------------------------------------------------------

    public function testQueueDefaultsToTodayOpenEntriesTwentyPerPage(): void
    {
        $this->repository->expects($this->once())
            ->method('countQueue')
            ->with(self::TODAY, null, 'open', null)
            ->willReturn(45);
        $this->repository->expects($this->once())
            ->method('getQueueList')
            ->with(self::TODAY, null, 'open', null, 20, 0)
            ->willReturn([]);

        $queue = $this->service->getQueue(null, null, null, null);

        $this->assertSame(self::TODAY, $queue->visitDate);
        $this->assertSame('open', $queue->status);
        $this->assertSame(20, $queue->perPage);
        $this->assertSame(3, $queue->totalPages);
        $this->assertSame(self::TODAY, $queue->today);
    }

    public function testQueueClearedDateMeansAnyDay(): void
    {
        $this->repository->expects($this->once())
            ->method('countQueue')
            ->with(null, 3, 'all', null)
            ->willReturn(0);

        $queue = $this->service->getQueue('', '3', 'ALL', null);

        $this->assertNull($queue->visitDate);
        $this->assertSame(3, $queue->doctorId);
    }

    #[DataProvider('queueStatusProvider')]
    public function testQueueNormalisesTheStatusFilter(?string $given, string $expected): void
    {
        $this->repository->expects($this->once())
            ->method('countQueue')
            ->with(self::TODAY, null, $expected, null)
            ->willReturn(0);

        $this->assertSame($expected, $this->service->getQueue(null, null, $given, null)->status);
    }

    public static function queueStatusProvider(): array
    {
        return [
            'open'            => ['open', 'open'],
            'waiting'         => ['waiting', 'Waiting'],
            'in consultation' => ['in consultation', 'In Consultation'],
            'completed'       => ['COMPLETED', 'Completed'],
            'all'             => ['all', 'all'],
            'unknown'         => ['Done', 'open'],
            'empty'           => ['', 'open'],
            'null'            => [null, 'open'],
        ];
    }

    public function testQueueMovesAnOutOfRangePageToTheLastPage(): void
    {
        $this->repository->method('countQueue')->willReturn(45);
        $this->repository->expects($this->once())
            ->method('getQueueList')
            ->with(self::TODAY, null, 'open', null, 20, 40)
            ->willReturn([]);

        $this->assertSame(3, $this->service->getQueue(null, null, null, null, 99)->page);
    }

    // ------------------------------------------------------------------
    // Reports
    // ------------------------------------------------------------------

    public function testReportDefaultsToTheLastSevenDays(): void
    {
        $this->repository->expects($this->once())->method('reportRegistrations')->with('2026-10-12', self::TODAY)->willReturn([]);
        $this->repository->expects($this->once())->method('reportAppointments')->with('2026-10-12', self::TODAY)->willReturn([]);
        $this->repository->expects($this->once())->method('reportCancellations')->with('2026-10-12', self::TODAY)->willReturn([]);
        $this->repository->expects($this->once())->method('reportQueueSummary')->with('2026-10-12', self::TODAY)->willReturn([]);

        $report = $this->service->getReport('', '', '');

        $this->assertSame('2026-10-12', $report->dateFrom);
        $this->assertSame(self::TODAY, $report->dateTo);
        $this->assertNull($report->doctorId);
        $this->assertSame([], $report->unavailable);
    }

    public function testReportSwapsAReversedRangeAndCapsItAt366Days(): void
    {
        $swapped = $this->service->getReport('2026-10-18', '2026-10-01', null);
        $this->assertSame('2026-10-01', $swapped->dateFrom);
        $this->assertSame('2026-10-18', $swapped->dateTo);

        $capped = $this->service->getReport('2020-01-01', '2026-10-18', null);
        $this->assertSame('2025-10-18', $capped->dateFrom);
        $this->assertSame('2026-10-18', $capped->dateTo);
    }

    public function testReportIgnoresInvalidDates(): void
    {
        $report = $this->service->getReport('2026-02-31', 'soon', null);

        $this->assertSame('2026-10-12', $report->dateFrom);
        $this->assertSame(self::TODAY, $report->dateTo);
    }

    public function testReportTotalsAndTheDoctorFilter(): void
    {
        $this->repository->method('reportRegistrations')->willReturn([
            ['report_date' => '2026-10-17', 'registrations' => 2],
            ['report_date' => '2026-10-18', 'registrations' => '3'],
        ]);
        $this->repository->method('reportAppointments')->willReturn([
            ['doctor_id' => 3, 'doctor_name' => 'A', 'specialization' => 'X', 'total' => 4, 'scheduled' => 1, 'checked_in' => 1, 'completed' => 1, 'cancelled' => 1, 'no_show' => 0],
            ['doctor_id' => 9, 'doctor_name' => 'B', 'specialization' => 'Y', 'total' => 2, 'scheduled' => 0, 'checked_in' => 0, 'completed' => 1, 'cancelled' => 0, 'no_show' => 1],
        ]);
        $this->repository->method('reportCancellations')->willReturn([]);
        $this->repository->method('reportQueueSummary')->willReturn([
            ['doctor_id' => 3, 'doctor_name' => 'A', 'specialization' => 'X', 'total' => 5, 'walk_ins' => 2, 'waiting' => 1, 'in_consultation' => 0, 'completed' => 3, 'cancelled' => 1, 'avg_wait_minutes' => '7.5', 'avg_consultation_minutes' => null],
            ['doctor_id' => 9, 'doctor_name' => 'B', 'specialization' => 'Y', 'total' => 1, 'walk_ins' => 0, 'waiting' => 0, 'in_consultation' => 1, 'completed' => 0, 'cancelled' => 0, 'avg_wait_minutes' => null, 'avg_consultation_minutes' => null],
        ]);

        $all = $this->service->getReport(null, null, null);
        $this->assertSame(5, $all->registrationTotal);
        $this->assertSame(6, $all->appointmentTotals['total']);
        $this->assertSame(2, $all->appointmentTotals['completed']);
        $this->assertSame(6, $all->queueTotals['total']);
        $this->assertSame(2, $all->queueTotals['walk_ins']);

        $oneDoctor = $this->service->getReport(null, null, '3');
        $this->assertSame(3, $oneDoctor->doctorId);
        $this->assertCount(1, $oneDoctor->appointments);
        $this->assertCount(1, $oneDoctor->queue);
        $this->assertSame(4, $oneDoctor->appointmentTotals['total']);
        $this->assertSame(5, $oneDoctor->registrationTotal, 'registrations are not filtered by doctor');
    }

    public function testOneBrokenReportDoesNotTakeTheOthersDown(): void
    {
        $this->repository->method('reportRegistrations')->willReturn([['report_date' => '2026-10-18', 'registrations' => 1]]);
        $this->repository->method('reportAppointments')->willReturn([]);
        $this->repository->method('reportCancellations')->willThrowException($this->dbalException());
        $this->repository->method('reportQueueSummary')->willReturn([]);
        $this->logger->expects($this->once())->method('error');

        $report = $this->service->getReport(null, null, null);

        $this->assertSame(['cancellations'], $report->unavailable);
        $this->assertTrue($report->isUnavailable('cancellations'));
        $this->assertFalse($report->isUnavailable('registrations'));
        $this->assertSame([], $report->cancellations);
        $this->assertSame(1, $report->registrationTotal);
    }

    // ------------------------------------------------------------------
    // Lookups
    // ------------------------------------------------------------------

    public function testActiveDoctorsAreMappedAndSortedByName(): void
    {
        $this->doctorService->method('getDoctorsList')->willReturn([
            new DoctorDTO(2, 'dr. zane', 'Neurology', null, null, null, null, null, null, null, null, '1'),
            new DoctorDTO(7, 'Dr. Adams', 'Cardiology', null, null, null, null, null, null, null, null, '1'),
            new DoctorDTO(1, 'Dr. Adams', 'Cardiology', null, null, null, null, null, null, null, null, '1'),
        ]);

        $doctors = $this->service->getActiveDoctors();

        $this->assertSame([1, 7, 2], array_column($doctors, 'id'));
        $this->assertSame('Dr. Adams', $doctors[0]['name']);
        $this->assertSame('Cardiology', $doctors[0]['specialization']);
    }

    public function testPatientSearchUsesTheActivePatientFilter(): void
    {
        $page = new PatientListDTO([], 0, 1, 10, 1, 'anita', 'active');
        $this->patientService->expects($this->once())
            ->method('getPatientsList')
            ->with('anita', 'active', 2)
            ->willReturn($page);

        $this->assertSame($page, $this->service->searchPatients('anita', 2));
    }

    public function testGetPatientDelegatesToThePatientModule(): void
    {
        $patient = new PatientDTO();
        $this->patientService->expects($this->once())->method('getPatient')->with(5)->willReturn($patient);

        $this->assertSame($patient, $this->service->getPatient(5));
    }

    public function testGetAppointmentIgnoresBadIdsAndReturnsTheRepositoryResult(): void
    {
        $appointment = new AppointmentDTO();
        $this->repository->expects($this->once())->method('getAppointmentById')->with(9)->willReturn($appointment);

        $this->assertNull($this->service->getAppointment(0));
        $this->assertNull($this->service->getAppointment(-4));
        $this->assertSame($appointment, $this->service->getAppointment(9));
    }

    public function testTodayAndDashboardComeFromTheRepository(): void
    {
        $this->assertSame(self::TODAY, $this->service->getToday());

        $summary = FDMDashboardDTO::empty('2026-10-18');
        $this->repository->expects($this->once())->method('getDashboardSummary')->with(null)->willReturn($summary);

        $this->assertSame($summary, $this->service->getDashboard());
    }
}
