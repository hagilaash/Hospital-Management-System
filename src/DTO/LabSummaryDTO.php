<?php

namespace App\DTO;

/**
 * The figures on the laboratory dashboard (one database row).
 */
class LabSummaryDTO
{
    public int $activeTests = 0;
    public int $pendingOrders = 0;
    public int $urgentOrders = 0;
    /** Tests whose sample has not been collected yet. */
    public int $awaitingSample = 0;
    /** Tests with a sample but no result yet. */
    public int $awaitingResult = 0;
    public int $resultedToday = 0;
    public int $abnormalToday = 0;
    public int $completedToday = 0;

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $dto = new self();

        $dto->activeTests = (int) ($row['active_tests'] ?? 0);
        $dto->pendingOrders = (int) ($row['pending_orders'] ?? 0);
        $dto->urgentOrders = (int) ($row['urgent_orders'] ?? 0);
        $dto->awaitingSample = (int) ($row['awaiting_sample'] ?? 0);
        $dto->awaitingResult = (int) ($row['awaiting_result'] ?? 0);
        $dto->resultedToday = (int) ($row['resulted_today'] ?? 0);
        $dto->abnormalToday = (int) ($row['abnormal_today'] ?? 0);
        $dto->completedToday = (int) ($row['completed_today'] ?? 0);

        return $dto;
    }

    /** All zeros, shown when the database cannot be reached. */
    public static function empty(): self
    {
        return new self();
    }
}