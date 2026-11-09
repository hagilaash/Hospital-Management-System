<?php

namespace App\DTO;

/**
 * The figures on the RCM overview and dashboard pages (one database row).
 */
class RCMSummaryDTO
{
    public int $pendingCases = 0;
    public int $finalizedCases = 0;
    public int $finalizedToday = 0;
    /** Bills that exist but are not fully paid yet: they are NOT in the RCM queue. */
    public int $awaitingBillingCases = 0;
    public string $pendingAmount = '0.00';
    public string $finalizedAmount = '0.00';
    public ?string $oldestPendingAt = null;
    public int $pendingOver1Day = 0;
    public int $pendingOver3Days = 0;

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $dto = new self();

        $dto->pendingCases = (int) ($row['pending_cases'] ?? 0);
        $dto->finalizedCases = (int) ($row['finalized_cases'] ?? 0);
        $dto->finalizedToday = (int) ($row['finalized_today'] ?? 0);
        $dto->awaitingBillingCases = (int) ($row['awaiting_billing_cases'] ?? 0);
        $dto->pendingAmount = self::money($row['pending_amount'] ?? null);
        $dto->finalizedAmount = self::money($row['finalized_amount'] ?? null);
        $dto->oldestPendingAt = isset($row['oldest_pending_at']) && is_scalar($row['oldest_pending_at']) ? (string) $row['oldest_pending_at'] : null;
        $dto->pendingOver1Day = (int) ($row['pending_over_1_day'] ?? 0);
        $dto->pendingOver3Days = (int) ($row['pending_over_3_days'] ?? 0);

        return $dto;
    }

    /** All zeros, shown when the database cannot be reached. */
    public static function empty(): self
    {
        return new self();
    }

    private static function money(mixed $value): string
    {
        return is_scalar($value) && is_numeric((string) $value) ? number_format((float) $value, 2, '.', '') : '0.00';
    }
}
