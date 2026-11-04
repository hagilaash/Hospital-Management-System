<?php

namespace App\DTO;

/**
 * The figures on the pharmacy dashboard (one database row).
 */
class PharmacySummaryDTO
{
    public int $totalMedicines = 0;
    public int $activeMedicines = 0;
    public int $inStockMedicines = 0;
    public int $lowStockMedicines = 0;
    public int $outOfStockMedicines = 0;
    /** Batches with stock that expire within 30 days. */
    public int $expiringBatches = 0;
    /** Batches that still hold stock past their expiry date. */
    public int $expiredBatches = 0;
    public int $openPrescriptions = 0;
    public int $dispensedToday = 0;

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $dto = new self();

        $dto->totalMedicines = (int) ($row['total_medicines'] ?? 0);
        $dto->activeMedicines = (int) ($row['active_medicines'] ?? 0);
        $dto->inStockMedicines = (int) ($row['in_stock_medicines'] ?? 0);
        $dto->lowStockMedicines = (int) ($row['low_stock_medicines'] ?? 0);
        $dto->outOfStockMedicines = (int) ($row['out_of_stock_medicines'] ?? 0);
        $dto->expiringBatches = (int) ($row['expiring_batches'] ?? 0);
        $dto->expiredBatches = (int) ($row['expired_batches'] ?? 0);
        $dto->openPrescriptions = (int) ($row['open_prescriptions'] ?? 0);
        $dto->dispensedToday = (int) ($row['dispensed_today'] ?? 0);

        return $dto;
    }

    /** All zeros, shown when the database cannot be reached. */
    public static function empty(): self
    {
        return new self();
    }
}
