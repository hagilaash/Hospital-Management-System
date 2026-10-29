<?php

namespace App\Repository;

use App\DAO\BillingDAO;
use App\DTO\BillDTO;

/**
 * Coordinates Billing data access through BillingDAO and turns rows into DTOs.
 * A bill is assembled here from its three database reads (header, items,
 * payments). Write results are returned as the arrays the database produced.
 * No business rules live here.
 */
class BillingRepository
{
    public function __construct(
        private BillingDAO $billingDAO
    ) {
    }

    /**
     * @return array{success: bool, message: string, bill_id: ?int, bill_number: ?string}
     */
    public function createBill(BillDTO $bill, string $createdBy): array
    {
        return $this->billingDAO->createBill($bill, $createdBy);
    }

    /**
     * @return array{success: bool, message: string, payment_id: ?int, payment_reference: ?string, duplicate: bool}
     */
    public function recordPayment(int $billId, string $amount, string $method, ?string $transactionRef, string $idempotencyKey, string $receivedBy): array
    {
        return $this->billingDAO->recordPayment($billId, $amount, $method, $transactionRef, $idempotencyKey, $receivedBy);
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function cancelBill(int $billId, string $reason, string $cancelledBy): array
    {
        return $this->billingDAO->cancelBill($billId, $reason, $cancelledBy);
    }

    /** One bill with its items and payment history, or null when it does not exist. */
    public function getBill(int $billId): ?BillDTO
    {
        $row = $this->billingDAO->getBillById($billId);
        if ($row === null) {
            return null;
        }

        $bill = BillDTO::fromRow($row);
        $bill->items = $this->billingDAO->getBillItems($billId);
        $bill->payments = $this->billingDAO->getPaymentsByBill($billId);

        return $bill;
    }

    /**
     * @return list<BillDTO>
     */
    public function getBills(?string $status, ?string $search, ?string $dateFrom, ?string $dateTo, int $limit, int $offset): array
    {
        $rows = $this->billingDAO->getBillsList($status, $search, $dateFrom, $dateTo, $limit, $offset);

        return array_map(static fn (array $row): BillDTO => BillDTO::fromRow($row), $rows);
    }

    public function countBills(?string $status, ?string $search, ?string $dateFrom, ?string $dateTo): int
    {
        return $this->billingDAO->countBills($status, $search, $dateFrom, $dateTo);
    }

    /** @return array<string, mixed> */
    public function getBillingSummary(): array
    {
        return $this->billingDAO->getBillingSummary();
    }

    /** @return list<array<string, mixed>> consultations ready to be billed (no live bill yet) */
    public function getBillableConsultations(?int $patientId, ?int $consultationId, int $limit): array
    {
        return $this->billingDAO->getBillableConsultations($patientId, $consultationId, $limit);
    }
}