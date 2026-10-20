<?php

namespace App\DTO;

/**
 * Everything the reports page shows for a date range.
 *
 * The four report functions are called independently. If one of them fails its
 * section is left empty and its name is added to $unavailable, so one broken
 * report never takes the whole page down.
 */
class FDMReportDTO
{
    /**
     * @param list<array<string, mixed>> $registrations     report_date, registrations
     * @param list<array<string, mixed>> $appointments      one row per doctor
     * @param list<array<string, mixed>> $cancellations     cancelled appointments (max 500)
     * @param list<array<string, mixed>> $queue             one row per doctor
     * @param array<string, int>         $appointmentTotals
     * @param array<string, int>         $queueTotals
     * @param list<string>               $unavailable       names of sections that could not be loaded
     */
    public function __construct(
        public readonly string $dateFrom,
        public readonly string $dateTo,
        public readonly ?int $doctorId,
        public readonly array $registrations,
        public readonly array $appointments,
        public readonly array $cancellations,
        public readonly array $queue,
        public readonly array $appointmentTotals,
        public readonly array $queueTotals,
        public readonly int $registrationTotal,
        public readonly array $unavailable = [],
    ) {
    }

    public function isUnavailable(string $section): bool
    {
        return in_array($section, $this->unavailable, true);
    }

    /** True when the cancellation list was cut off at the SQL function's 500-row limit. */
    public function cancellationsTruncated(): bool
    {
        return count($this->cancellations) >= 500;
    }
}
