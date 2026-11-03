<?php

namespace App\DAO;

use App\DB\RCMDatabaseFunction;

/**
 * Executes the RCM database operations through RCMDatabaseFunction and returns
 * the raw database results. It contains no business rules.
 */
class RCMDAO
{
    public function __construct(
        private RCMDatabaseFunction $rcmDatabaseFunction
    ) {
    }

    public function getCases(string $view, ?string $search, ?string $dateFrom, ?string $dateTo, int $limit, int $offset): array
    {
        return $this->rcmDatabaseFunction->getCases($view, $search, $dateFrom, $dateTo, $limit, $offset);
    }

    public function countCases(string $view, ?string $search, ?string $dateFrom, ?string $dateTo): int
    {
        return $this->rcmDatabaseFunction->countCases($view, $search, $dateFrom, $dateTo);
    }

    public function getCaseByBill(int $billId): ?array
    {
        return $this->rcmDatabaseFunction->getCaseByBill($billId);
    }

    public function getSummary(): array
    {
        return $this->rcmDatabaseFunction->getSummary();
    }

    public function getHistory(int $billId): array
    {
        return $this->rcmDatabaseFunction->getHistory($billId);
    }

    public function getBillItems(int $billId): array
    {
        return $this->rcmDatabaseFunction->getBillItems($billId);
    }

    public function getBillPayments(int $billId): array
    {
        return $this->rcmDatabaseFunction->getBillPayments($billId);
    }

    public function finalize(int $billId, ?string $remarks, string $finalizedBy): array
    {
        return $this->rcmDatabaseFunction->finalize($billId, $remarks, $finalizedBy);
    }
}