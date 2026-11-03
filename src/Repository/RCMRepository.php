<?php

namespace App\Repository;

use App\DAO\RCMDAO;
use App\DTO\RCMCaseDTO;
use App\DTO\RCMSummaryDTO;

/**
 * Coordinates RCM data access through RCMDAO and turns rows into DTOs. A full case
 * is assembled here from its database reads (case, items, payments, history).
 * Finalization results are returned as the array the database produced.
 * No business rules live here.
 */
class RCMRepository
{
    public const VIEW_PENDING = 'pending';
    public const VIEW_FINALIZED = 'finalized';

    public function __construct(
        private RCMDAO $rcmDAO
    ) {
    }

    public function getSummary(): RCMSummaryDTO
    {
        return RCMSummaryDTO::fromRow($this->rcmDAO->getSummary());
    }

    /**
     * @return list<RCMCaseDTO>
     */
    public function getCases(string $view, ?string $search, ?string $dateFrom, ?string $dateTo, int $limit, int $offset): array
    {
        $rows = $this->rcmDAO->getCases($view, $search, $dateFrom, $dateTo, $limit, $offset);

        return array_map(static fn (array $row): RCMCaseDTO => RCMCaseDTO::fromRow($row), $rows);
    }

    public function countCases(string $view, ?string $search, ?string $dateFrom, ?string $dateTo): int
    {
        return $this->rcmDAO->countCases($view, $search, $dateFrom, $dateTo);
    }

    /** One case with its bill items, payments and history, or null when the bill does not exist. */
    public function getCase(int $billId): ?RCMCaseDTO
    {
        $row = $this->rcmDAO->getCaseByBill($billId);
        if ($row === null) {
            return null;
        }

        $case = RCMCaseDTO::fromRow($row);
        $case->items = $this->rcmDAO->getBillItems($billId);
        $case->payments = $this->rcmDAO->getBillPayments($billId);
        $case->history = $this->rcmDAO->getHistory($billId);

        return $case;
    }

    /**
     * @return array{success: bool, message: string, rcm_id: ?int, already_finalized: bool, visit_note: ?string}
     */
    public function finalize(int $billId, ?string $remarks, string $finalizedBy): array
    {
        return $this->rcmDAO->finalize($billId, $remarks, $finalizedBy);
    }
}