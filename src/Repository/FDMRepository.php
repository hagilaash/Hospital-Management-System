<?php

namespace App\Repository;

use App\DAO\FDMDAO;
use App\DTO\AppointmentDTO;
use App\DTO\FDMDashboardDTO;
use App\DTO\QueueEntryDTO;

/**
 * Coordinates FDM data access through FDMDAO and turns database rows into DTOs.
 * Write results and report rows are returned as the arrays the database produced.
 * No business rules live here.
 */
class FDMRepository
{
    public function __construct(
        private FDMDAO $fdmDAO
    ) {
    }

    /** The hospital's current day as 'Y-m-d'. */
    public function getHospitalToday(): string
    {
        return $this->fdmDAO->getHospitalToday();
    }

    // ---- appointments -------------------------------------------------

    /**
     * @return array{success: bool, message: string, appointment_id: ?int, appointment_code: ?string}
     */
    public function createAppointment(AppointmentDTO $appointment, string $createdBy): array
    {
        return $this->fdmDAO->createAppointment($appointment, $createdBy);
    }

    /**
     * @return array{success: bool, message: string, appointment_id: ?int, appointment_code: ?string}
     */
    public function updateAppointment(int $appointmentId, AppointmentDTO $appointment, string $updatedBy): array
    {
        return $this->fdmDAO->updateAppointment($appointmentId, $appointment, $updatedBy);
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function cancelAppointment(int $appointmentId, string $reason, string $cancelledBy): array
    {
        return $this->fdmDAO->cancelAppointment($appointmentId, $reason, $cancelledBy);
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function markAppointmentNoShow(int $appointmentId, string $updatedBy): array
    {
        return $this->fdmDAO->markAppointmentNoShow($appointmentId, $updatedBy);
    }

    public function getAppointmentById(int $appointmentId): ?AppointmentDTO
    {
        $row = $this->fdmDAO->getAppointmentById($appointmentId);

        return $row === null ? null : AppointmentDTO::fromRow($row);
    }

    /**
     * @return list<AppointmentDTO>
     */
    public function getAppointmentsList(
        ?string $dateFrom,
        ?string $dateTo,
        ?int $doctorId,
        ?int $patientId,
        ?string $status,
        ?string $search,
        int $limit,
        int $offset
    ): array {
        $rows = $this->fdmDAO->getAppointmentsList(
            $dateFrom,
            $dateTo,
            $doctorId,
            $patientId,
            $status,
            $search,
            $limit,
            $offset
        );

        return array_map(
            static fn (array $row): AppointmentDTO => AppointmentDTO::fromRow($row),
            $rows
        );
    }

    public function countAppointments(
        ?string $dateFrom,
        ?string $dateTo,
        ?int $doctorId,
        ?int $patientId,
        ?string $status,
        ?string $search
    ): int {
        return $this->fdmDAO->countAppointments($dateFrom, $dateTo, $doctorId, $patientId, $status, $search);
    }

    // ---- check-in and queue -------------------------------------------

    /**
     * @return array{success: bool, message: string, queue_id: ?int, token_number: ?int}
     */
    public function checkInAppointment(int $appointmentId, string $checkedInBy): array
    {
        return $this->fdmDAO->checkInAppointment($appointmentId, $checkedInBy);
    }

    /**
     * @return array{success: bool, message: string, queue_id: ?int, token_number: ?int}
     */
    public function checkInWalkIn(int $patientId, int $doctorId, string $checkedInBy): array
    {
        return $this->fdmDAO->checkInWalkIn($patientId, $doctorId, $checkedInBy);
    }

    /**
     * @return list<QueueEntryDTO>
     */
    public function getQueueList(
        ?string $visitDate,
        ?int $doctorId,
        ?string $status,
        ?string $search,
        int $limit,
        int $offset
    ): array {
        $rows = $this->fdmDAO->getQueueList($visitDate, $doctorId, $status, $search, $limit, $offset);

        return array_map(
            static fn (array $row): QueueEntryDTO => QueueEntryDTO::fromRow($row),
            $rows
        );
    }

    public function countQueue(?string $visitDate, ?int $doctorId, ?string $status, ?string $search): int
    {
        return $this->fdmDAO->countQueue($visitDate, $doctorId, $status, $search);
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function updateQueueStatus(int $queueId, string $newStatus, string $updatedBy, ?string $reason): array
    {
        return $this->fdmDAO->updateQueueStatus($queueId, $newStatus, $updatedBy, $reason);
    }

    // ---- dashboard and reports ----------------------------------------

    public function getDashboardSummary(?string $date): FDMDashboardDTO
    {
        return FDMDashboardDTO::fromRow($this->fdmDAO->getDashboardSummary($date));
    }

    /** @return list<array<string, mixed>> report_date, registrations */
    public function reportRegistrations(?string $dateFrom, ?string $dateTo): array
    {
        return $this->fdmDAO->reportRegistrations($dateFrom, $dateTo);
    }

    /** @return list<array<string, mixed>> one row per doctor */
    public function reportAppointments(?string $dateFrom, ?string $dateTo): array
    {
        return $this->fdmDAO->reportAppointments($dateFrom, $dateTo);
    }

    /** @return list<array<string, mixed>> cancelled appointments */
    public function reportCancellations(?string $dateFrom, ?string $dateTo): array
    {
        return $this->fdmDAO->reportCancellations($dateFrom, $dateTo);
    }

    /** @return list<array<string, mixed>> one row per doctor */
    public function reportQueueSummary(?string $dateFrom, ?string $dateTo): array
    {
        return $this->fdmDAO->reportQueueSummary($dateFrom, $dateTo);
    }
}
