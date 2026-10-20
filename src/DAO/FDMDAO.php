<?php

namespace App\DAO;

use App\DB\FDMDatabaseFunction;
use App\DTO\AppointmentDTO;

/**
 * Executes the FDM database operations through FDMDatabaseFunction.
 * It only unpacks DTOs into the scalar arguments FDMDatabaseFunction expects and
 * returns the raw database results; it contains no business rules.
 */
class FDMDAO
{
    public function __construct(
        private FDMDatabaseFunction $fdmDatabaseFunction
    ) {
    }

    public function getHospitalToday(): string
    {
        return $this->fdmDatabaseFunction->getHospitalToday();
    }

    // ---- appointments -------------------------------------------------

    public function createAppointment(AppointmentDTO $appointment, string $createdBy): array
    {
        return $this->fdmDatabaseFunction->createAppointment(
            (int) $appointment->patientId,
            (int) $appointment->doctorId,
            $appointment->appointmentDate,
            $appointment->startTime,
            $appointment->durationMinutes,
            $appointment->reason,
            $createdBy
        );
    }

    public function updateAppointment(int $appointmentId, AppointmentDTO $appointment, string $updatedBy): array
    {
        return $this->fdmDatabaseFunction->updateAppointment(
            $appointmentId,
            (int) $appointment->doctorId,
            $appointment->appointmentDate,
            $appointment->startTime,
            $appointment->durationMinutes,
            $appointment->reason,
            $updatedBy
        );
    }

    public function cancelAppointment(int $appointmentId, string $reason, string $cancelledBy): array
    {
        return $this->fdmDatabaseFunction->cancelAppointment($appointmentId, $reason, $cancelledBy);
    }

    public function markAppointmentNoShow(int $appointmentId, string $updatedBy): array
    {
        return $this->fdmDatabaseFunction->markAppointmentNoShow($appointmentId, $updatedBy);
    }

    public function getAppointmentById(int $appointmentId): ?array
    {
        return $this->fdmDatabaseFunction->getAppointmentById($appointmentId);
    }

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
        return $this->fdmDatabaseFunction->getAppointmentsList(
            $dateFrom,
            $dateTo,
            $doctorId,
            $patientId,
            $status,
            $search,
            $limit,
            $offset
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
        return $this->fdmDatabaseFunction->countAppointments($dateFrom, $dateTo, $doctorId, $patientId, $status, $search);
    }

    // ---- check-in and queue -------------------------------------------

    public function checkInAppointment(int $appointmentId, string $checkedInBy): array
    {
        return $this->fdmDatabaseFunction->checkInAppointment($appointmentId, $checkedInBy);
    }

    public function checkInWalkIn(int $patientId, int $doctorId, string $checkedInBy): array
    {
        return $this->fdmDatabaseFunction->checkInWalkIn($patientId, $doctorId, $checkedInBy);
    }

    public function getQueueList(
        ?string $visitDate,
        ?int $doctorId,
        ?string $status,
        ?string $search,
        int $limit,
        int $offset
    ): array {
        return $this->fdmDatabaseFunction->getQueueList($visitDate, $doctorId, $status, $search, $limit, $offset);
    }

    public function countQueue(?string $visitDate, ?int $doctorId, ?string $status, ?string $search): int
    {
        return $this->fdmDatabaseFunction->countQueue($visitDate, $doctorId, $status, $search);
    }

    public function updateQueueStatus(int $queueId, string $newStatus, string $updatedBy, ?string $reason): array
    {
        return $this->fdmDatabaseFunction->updateQueueStatus($queueId, $newStatus, $updatedBy, $reason);
    }

    // ---- dashboard and reports ----------------------------------------

    public function getDashboardSummary(?string $date): array
    {
        return $this->fdmDatabaseFunction->getDashboardSummary($date);
    }

    public function reportRegistrations(?string $dateFrom, ?string $dateTo): array
    {
        return $this->fdmDatabaseFunction->reportRegistrations($dateFrom, $dateTo);
    }

    public function reportAppointments(?string $dateFrom, ?string $dateTo): array
    {
        return $this->fdmDatabaseFunction->reportAppointments($dateFrom, $dateTo);
    }

    public function reportCancellations(?string $dateFrom, ?string $dateTo): array
    {
        return $this->fdmDatabaseFunction->reportCancellations($dateFrom, $dateTo);
    }

    public function reportQueueSummary(?string $dateFrom, ?string $dateTo): array
    {
        return $this->fdmDatabaseFunction->reportQueueSummary($dateFrom, $dateTo);
    }
}
