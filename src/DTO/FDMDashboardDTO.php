<?php

namespace App\DTO;

/**
 * The front desk's counts for one hospital day (ecs_get_fdm_dashboard_summary).
 */
class FDMDashboardDTO
{
    public function __construct(
        public readonly string $reportDate,
        public readonly int $registrations,
        public readonly int $appointmentsTotal,
        public readonly int $appointmentsScheduled,
        public readonly int $appointmentsCheckedIn,
        public readonly int $appointmentsCompleted,
        public readonly int $appointmentsCancelled,
        public readonly int $appointmentsNoShow,
        public readonly int $queueWaiting,
        public readonly int $queueInConsultation,
        public readonly int $queueCompleted,
        public readonly int $queueCancelled,
        public readonly int $walkIns
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (string) ($row['report_date'] ?? ''),
            (int) ($row['registrations'] ?? 0),
            (int) ($row['appointments_total'] ?? 0),
            (int) ($row['appointments_scheduled'] ?? 0),
            (int) ($row['appointments_checked_in'] ?? 0),
            (int) ($row['appointments_completed'] ?? 0),
            (int) ($row['appointments_cancelled'] ?? 0),
            (int) ($row['appointments_no_show'] ?? 0),
            (int) ($row['queue_waiting'] ?? 0),
            (int) ($row['queue_in_consultation'] ?? 0),
            (int) ($row['queue_completed'] ?? 0),
            (int) ($row['queue_cancelled'] ?? 0),
            (int) ($row['walk_ins'] ?? 0)
        );
    }

    /** All zeros, used when the database cannot be reached. */
    public static function empty(string $reportDate = ''): self
    {
        return new self($reportDate, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0);
    }
}
