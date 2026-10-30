<?php

namespace App\DAO;

use App\DB\BillingDatabaseFunction;
use App\DTO\BillDTO;

/**
 * Executes the Billing database operations through BillingDatabaseFunction.
 * It only unpacks DTOs into the scalar arguments that class expects (including
 * turning the item rows into the JSON the SQL function reads) and returns the raw
 * database results; it contains no business rules.
 */
class BillingDAO
{
    public function __construct(
        private BillingDatabaseFunction $billingDatabaseFunction,
    ) {
    }

    public function createBill(BillDTO $bill, string $createdBy): array
    {
        $items = array_map(
            static fn (array $item): array => [
                'description' => (string) ($item['description'] ?? ''),
                'quantity' => (string) ($item['quantity'] ?? ''),
                'unit_price' => (string) ($item['unit_price'] ?? ''),
            ],
            $bill->items
        );

        return $this->billingDatabaseFunction->createBill(
            (int) $bill->patientId,
            $bill->consultationId,
            json_encode($items, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            $bill->discountAmount,
            $bill->discountReason,
            $bill->taxPercent,
            $bill->notes,
            $createdBy
        );
    }

    public function recordPayment(int $billId, string $amount, string $method, ?string $transactionRef, string $idempotencyKey, string $receivedBy): array
    {
        return $this->billingDatabaseFunction->recordPayment($billId, $amount, $method, $transactionRef, $idempotencyKey, $receivedBy);
    }

    public function cancelBill(int $billId, string $reason, string $cancelledBy): array
    {
        return $this->billingDatabaseFunction->cancelBill($billId, $reason, $cancelledBy);
    }

    public function getBillById(int $billId): ?array
    {
        return $this->billingDatabaseFunction->getBillById($billId);
    }

    public function getBillItems(int $billId): array
    {
        return $this->billingDatabaseFunction->getBillItems($billId);
    }

    public function getPaymentsByBill(int $billId): array
    {
        return $this->billingDatabaseFunction->getPaymentsByBill($billId);
    }

    public function getBillsList(?string $status, ?string $search, ?string $dateFrom, ?string $dateTo, int $limit, int $offset): array
    {
        return $this->billingDatabaseFunction->getBillsList($status, $search, $dateFrom, $dateTo, $limit, $offset);
    }

    public function countBills(?string $status, ?string $search, ?string $dateFrom, ?string $dateTo): int
    {
        return $this->billingDatabaseFunction->countBills($status, $search, $dateFrom, $dateTo);
    }

    public function getBillingSummary(): array
    {
        return $this->billingDatabaseFunction->getBillingSummary();
    }

    public function getBillableConsultations(?int $patientId, ?int $consultationId, int $limit): array
    {
        return $this->billingDatabaseFunction->getBillableConsultations($patientId, $consultationId, $limit);
    }
}
