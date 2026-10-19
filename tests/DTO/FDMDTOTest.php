<?php

namespace App\Tests\DTO;

use App\DTO\AppointmentDTO;
use App\DTO\AppointmentListDTO;
use App\DTO\FDMDashboardDTO;
use App\DTO\FDMReportDTO;
use App\DTO\QueueEntryDTO;
use App\DTO\QueueListDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The row-to-DTO mapping is where driver type differences (int vs string,
 * bool vs 't'/'f', '10:00:00' vs '10:00') would show up, so it is tested directly.
 */
class FDMDTOTest extends TestCase
{
    // ------------------------------------------------------------------
    // AppointmentDTO
    // ------------------------------------------------------------------

    public function testAppointmentFromAFullDetailRow(): void
    {
        $dto = AppointmentDTO::fromRow([
            'appointment_id' => 9,
            'appointment_code' => 'APT-2026-000009',
            'patient_id' => 5,
            'patient_code' => 'PAT-2026-000005',
            'patient_name' => 'Anita Raman',
            'patient_phone' => '9841012345',
            'doctor_id' => 3,
            'doctor_name' => 'Dr. Adams',
            'specialization' => 'Cardiology',
            'department' => 'Cardiology',
            'appointment_date' => '2026-11-20',
            'start_time' => '10:00:00',
            'end_time' => '10:15:00',
            'duration_minutes' => 15,
            'status' => 'Checked-In',
            'reason' => 'Checkup',
            'cancel_reason' => null,
            'cancelled_at' => null,
            'cancelled_by' => null,
            'queue_id' => 31,
            'token_number' => 4,
            'queue_status' => 'Waiting',
            'arrival_at' => '2026-11-20 09:55:00+05:30',
            'created_at' => '2026-11-01 11:00:00+05:30',
            'updated_at' => '2026-11-20 09:55:00+05:30',
            'created_by' => 'admin',
            'updated_by' => 'admin',
        ]);

        $this->assertSame(9, $dto->appointmentId);
        $this->assertSame('APT-2026-000009', $dto->appointmentCode);
        $this->assertSame('Anita Raman', $dto->patientName);
        $this->assertSame('10:00', $dto->startTime, 'seconds are cut off for the time input');
        $this->assertSame('10:15', $dto->endTime);
        $this->assertSame(15, $dto->durationMinutes);
        $this->assertSame('15', $dto->durationInput);
        $this->assertSame('Checked-In', $dto->status);
        $this->assertSame(31, $dto->queueId);
        $this->assertSame(4, $dto->tokenNumber);
        $this->assertNull($dto->cancelReason);
        $this->assertFalse($dto->canEdit());
    }

    public function testAppointmentCastsStringTypedValuesAndLeavesMissingColumnsNull(): void
    {
        // A list row has fewer columns than a detail row.
        $dto = AppointmentDTO::fromRow([
            'appointment_id' => '12',
            'patient_id' => '5',
            'doctor_id' => '3',
            'duration_minutes' => '30',
            'appointment_date' => '2026-11-20',
            'start_time' => '09:05:00',
            'status' => 'Scheduled',
        ]);

        $this->assertSame(12, $dto->appointmentId);
        $this->assertSame(5, $dto->patientId);
        $this->assertSame(3, $dto->doctorId);
        $this->assertSame(30, $dto->durationMinutes);
        $this->assertSame('09:05', $dto->startTime);
        $this->assertNull($dto->endTime);
        $this->assertNull($dto->department);
        $this->assertNull($dto->queueId);
        $this->assertTrue($dto->canEdit());
    }

    public function testOnlyScheduledAppointmentsCanBeEdited(): void
    {
        foreach (['Checked-In', 'Completed', 'Cancelled', 'No-Show'] as $status) {
            $dto = new AppointmentDTO();
            $dto->status = $status;
            $this->assertFalse($dto->canEdit(), $status);
            $this->assertFalse($dto->isScheduled(), $status);
        }

        $this->assertTrue((new AppointmentDTO())->canEdit());
    }

    public function testAppointmentFromInputReadsOnlyTheEditableFields(): void
    {
        $dto = AppointmentDTO::fromInput([
            'patient_id' => '5',
            'doctor_id' => '3',
            'appointment_date' => '2026-11-20',
            'start_time' => '10:00',
            'duration_minutes' => '30',
            'reason' => 'Checkup',
            // A crafted request tries to set fields the form does not offer:
            'status' => 'Completed',
            'appointment_id' => '99',
            'appointment_code' => 'APT-1999-000001',
            'created_by' => 'someone-else',
        ]);

        $this->assertSame(5, $dto->patientId);
        $this->assertSame(3, $dto->doctorId);
        $this->assertSame('2026-11-20', $dto->appointmentDate);
        $this->assertSame('10:00', $dto->startTime);
        $this->assertSame(30, $dto->durationMinutes);
        $this->assertSame('30', $dto->durationInput);
        $this->assertSame('Checkup', $dto->reason);
        $this->assertSame('Scheduled', $dto->status);
        $this->assertNull($dto->appointmentId);
        $this->assertNull($dto->appointmentCode);
        $this->assertNull($dto->createdBy);
    }

    #[DataProvider('idInputProvider')]
    public function testAppointmentFromInputParsesIdsStrictly(mixed $given, ?int $expected): void
    {
        $dto = AppointmentDTO::fromInput(['patient_id' => $given, 'doctor_id' => $given]);

        $this->assertSame($expected, $dto->patientId);
        $this->assertSame($expected, $dto->doctorId);
    }

    public static function idInputProvider(): array
    {
        return [
            'digits'          => ['5', 5],
            'int'             => [5, 5],
            'blank'           => ['', null],
            'negative'        => ['-1', null],
            'decimal'         => ['5.5', null],
            'letters'         => ['abc', null],
            'array'           => [['1'], null],
            'too many digits' => ['12345678901', null],
            'null'            => [null, null],
        ];
    }

    public function testAppointmentFromInputIgnoresArrayValuesAndDefaultsMissingKeys(): void
    {
        $dto = AppointmentDTO::fromInput(['appointment_date' => ['x'], 'reason' => ['y'], 'duration_minutes' => 'abc']);

        $this->assertSame('', $dto->appointmentDate);
        $this->assertNull($dto->reason);
        $this->assertSame(0, $dto->durationMinutes, 'a non-number never becomes a valid duration');
        $this->assertSame('abc', $dto->durationInput, 'but is kept so the form can show it again');

        $empty = AppointmentDTO::fromInput([]);
        $this->assertSame('', $empty->startTime);
        $this->assertNull($empty->patientId);
        $this->assertNull($empty->doctorId);
    }

    // ------------------------------------------------------------------
    // QueueEntryDTO
    // ------------------------------------------------------------------

    public function testQueueEntryFromARow(): void
    {
        $dto = QueueEntryDTO::fromRow([
            'queue_id' => '31',
            'token_number' => 4,
            'patient_id' => 5,
            'patient_code' => 'PAT-2026-000005',
            'patient_name' => 'Anita Raman',
            'patient_phone' => '9841012345',
            'doctor_id' => 3,
            'doctor_name' => 'Dr. Adams',
            'specialization' => 'Cardiology',
            'appointment_id' => 9,
            'appointment_code' => 'APT-2026-000009',
            'appointment_time' => '10:00:00',
            'is_walk_in' => 'f',
            'visit_date' => '2026-11-20',
            'arrival_at' => '2026-11-20 09:55:00+05:30',
            'status' => 'Waiting',
            'consultation_started_at' => null,
            'completed_at' => null,
            'waiting_minutes' => '12',
        ]);

        $this->assertSame(31, $dto->queueId);
        $this->assertSame(4, $dto->tokenNumber);
        $this->assertSame('10:00', $dto->appointmentTime);
        $this->assertFalse($dto->isWalkIn);
        $this->assertSame(12, $dto->waitingMinutes);
        $this->assertSame('Waiting', $dto->status);
    }

    public function testQueueEntryWalkInHasNoAppointment(): void
    {
        $dto = QueueEntryDTO::fromRow([
            'queue_id' => 40,
            'token_number' => 1,
            'appointment_id' => null,
            'appointment_code' => null,
            'appointment_time' => null,
            'is_walk_in' => true,
            'status' => 'Waiting',
            'waiting_minutes' => 0,
        ]);

        $this->assertTrue($dto->isWalkIn);
        $this->assertNull($dto->appointmentId);
        $this->assertNull($dto->appointmentTime);
        $this->assertSame(0, $dto->waitingMinutes);
    }

    public function testQueueEntryAcceptsEveryFormOfTrue(): void
    {
        foreach ([true, 't', 'true', '1', 1] as $value) {
            $this->assertTrue(QueueEntryDTO::fromRow(['is_walk_in' => $value])->isWalkIn, var_export($value, true));
        }
        foreach ([false, 'f', 'false', '0', 0, null] as $value) {
            $this->assertFalse(QueueEntryDTO::fromRow(['is_walk_in' => $value])->isWalkIn, var_export($value, true));
        }
    }

    #[DataProvider('queueActionsProvider')]
    public function testQueueEntryOffersOnlyTheAllowedActions(string $status, bool $open, bool $start, bool $complete, bool $cancel): void
    {
        $dto = QueueEntryDTO::fromRow(['status' => $status]);

        $this->assertSame($open, $dto->isOpen(), "$status isOpen");
        $this->assertSame($start, $dto->canStart(), "$status canStart");
        $this->assertSame($complete, $dto->canComplete(), "$status canComplete");
        $this->assertSame($cancel, $dto->canCancel(), "$status canCancel");
    }

    public static function queueActionsProvider(): array
    {
        return [
            'waiting'         => ['Waiting', true, true, false, true],
            'in consultation' => ['In Consultation', true, false, true, true],
            'completed'       => ['Completed', false, false, false, false],
            'cancelled'       => ['Cancelled', false, false, false, false],
        ];
    }

    // ------------------------------------------------------------------
    // Dashboard and report DTOs
    // ------------------------------------------------------------------

    public function testDashboardFromARowCastsBigintCounts(): void
    {
        $dto = FDMDashboardDTO::fromRow([
            'report_date' => '2026-10-18',
            'registrations' => '3',
            'appointments_total' => 10,
            'appointments_scheduled' => '4',
            'appointments_checked_in' => 2,
            'appointments_completed' => 2,
            'appointments_cancelled' => 1,
            'appointments_no_show' => 1,
            'queue_waiting' => 2,
            'queue_in_consultation' => 1,
            'queue_completed' => 2,
            'queue_cancelled' => 0,
            'walk_ins' => '2',
        ]);

        $this->assertSame('2026-10-18', $dto->reportDate);
        $this->assertSame(3, $dto->registrations);
        $this->assertSame(10, $dto->appointmentsTotal);
        $this->assertSame(4, $dto->appointmentsScheduled);
        $this->assertSame(2, $dto->walkIns);
    }

    public function testDashboardEmptyIsAllZeros(): void
    {
        $dto = FDMDashboardDTO::empty();

        $this->assertSame('', $dto->reportDate);
        $this->assertSame(0, $dto->appointmentsTotal + $dto->queueWaiting + $dto->registrations + $dto->walkIns);
    }

    public function testReportDtoKnowsWhichSectionsFailed(): void
    {
        $report = new FDMReportDTO('2026-10-12', '2026-10-18', null, [], [], array_fill(0, 500, ['x' => 1]), [], [], [], 0, ['queue']);

        $this->assertTrue($report->isUnavailable('queue'));
        $this->assertFalse($report->isUnavailable('appointments'));
        $this->assertTrue($report->cancellationsTruncated());
        $this->assertFalse((new FDMReportDTO('a', 'b', null, [], [], [], [], [], [], 0))->cancellationsTruncated());
    }

    // ------------------------------------------------------------------
    // List DTOs: pager helpers
    // ------------------------------------------------------------------

    public function testAppointmentListPagerHelpers(): void
    {
        $middle = new AppointmentListDTO(array_fill(0, 10, new AppointmentDTO()), 45, 3, 10, 5, null, null, null, null, 'all', null, '2026-10-18');

        $this->assertTrue($middle->hasPreviousPage());
        $this->assertTrue($middle->hasNextPage());
        $this->assertSame(21, $middle->getFirstItemNumber());
        $this->assertSame(30, $middle->getLastItemNumber());

        $last = new AppointmentListDTO(array_fill(0, 5, new AppointmentDTO()), 45, 5, 10, 5, null, null, null, null, 'all', null, '2026-10-18');

        $this->assertFalse($last->hasNextPage());
        $this->assertSame(41, $last->getFirstItemNumber());
        $this->assertSame(45, $last->getLastItemNumber());
    }

    public function testQueueListPagerHelpers(): void
    {
        $page = new QueueListDTO(array_fill(0, 20, new QueueEntryDTO()), 45, 1, 20, 3, '2026-10-18', null, 'open', null, '2026-10-18');

        $this->assertFalse($page->hasPreviousPage());
        $this->assertTrue($page->hasNextPage());
        $this->assertSame(1, $page->getFirstItemNumber());
        $this->assertSame(20, $page->getLastItemNumber());

        $empty = new QueueListDTO([], 0, 1, 20, 1, null, null, 'open', null, '');
        $this->assertSame(0, $empty->getFirstItemNumber());
        $this->assertSame(0, $empty->getLastItemNumber());
        $this->assertFalse($empty->hasNextPage());
    }
}
