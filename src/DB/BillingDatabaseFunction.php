<?php

namespace App\DB;

use Doctrine\DBAL\Connection;

/**
 * Calls the Billing PostgreSQL functions (sql/billing_module.sql).
 *
 * Same conventions as the other *DatabaseFunction classes: every value is a bound
 * parameter wrapped in CAST(... AS type), and the CAST types, bind names and
 * argument ORDER match the SQL signatures exactly. Money is bound as a string and
 * cast to numeric on the database side, so no float ever touches an amount.
 */
class BillingDatabaseFunction
{
    public function __construct(
        private Connection $connection
    ) {
    }

    /**
     * ecs_create_bill(8 params). $itemsJson is a JSON array of
     * {"description", "quantity", "unit_price"}.
     *
     * @return array{success: bool, message: string, bill_id: ?int, bill_number: ?string}
     */
    public function createBill(
        int $patientId,
        ?int $consultationId,
        string $itemsJson,
        string $discountAmount,
        ?string $discountReason,
        string $taxPercent,
        ?string $notes,
        string $createdBy
    ): array {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ecs_create_bill(
                CAST(:patient_id AS integer),
                CAST(:consultation_id AS integer),
                CAST(:items AS jsonb),
                CAST(:discount_amount AS numeric),
                CAST(:discount_reason AS character varying),
                CAST(:tax_percent AS numeric),
                CAST(:notes AS character varying),
                CAST(:created_by AS character varying)
            )',
            [
                'patient_id' => $patientId,
                'consultation_id' => $consultationId,
                'items' => $itemsJson,
                'discount_amount' => $discountAmount,
                'discount_reason' => $discountReason,
                'tax_percent' => $taxPercent,
                'notes' => $notes,
                'created_by' => $createdBy,
            ]
        );

        return $this->requireRow($row, 'ecs_create_bill');
    }

    /**
     * ecs_record_payment(6 params)
     *
     * @return array{success: bool, message: string, payment_id: ?int, payment_reference: ?string, duplicate: bool}
     */
    public function recordPayment(
        int $billId,
        string $amount,
        string $method,
        ?string $transactionRef,
        string $idempotencyKey,
        string $receivedBy
    ): array {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ecs_record_payment(
                CAST(:bill_id AS integer),
                CAST(:amount AS numeric),
                CAST(:method AS character varying),
                CAST(:transaction_ref AS character varying),
                CAST(:idempotency_key AS character varying),
                CAST(:received_by AS character varying)
            )',
            [
                'bill_id' => $billId,
                'amount' => $amount,
                'method' => $method,
                'transaction_ref' => $transactionRef,
                'idempotency_key' => $idempotencyKey,
                'received_by' => $receivedBy,
            ]
        );

        return $this->requireRow($row, 'ecs_record_payment');
    }

    /**
     * ecs_cancel_bill(3 params)
     *
     * @return array{success: bool, message: string}
     */
    public function cancelBill(int $billId, string $reason, string $cancelledBy): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ecs_cancel_bill(
                CAST(:bill_id AS integer),
                CAST(:reason AS character varying),
                CAST(:cancelled_by AS character varying)
            )',
            ['bill_id' => $billId, 'reason' => $reason, 'cancelled_by' => $cancelledBy]
        );

        return $this->requireRow($row, 'ecs_cancel_bill');
    }

    /** ecs_get_bill_by_id(1 param). Null when the bill does not exist. */
    public function getBillById(int $billId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ecs_get_bill_by_id(CAST(:bill_id AS integer))',
            ['bill_id' => $billId]
        );

        return $row === false ? null : $row;
    }

    /** ecs_get_bill_items(1 param) */
    public function getBillItems(int $billId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT * FROM ecs_get_bill_items(CAST(:bill_id AS integer))',
            ['bill_id' => $billId]
        );
    }

    /** ecs_get_payments_by_bill(1 param) */
    public function getPaymentsByBill(int $billId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT * FROM ecs_get_payments_by_bill(CAST(:bill_id AS integer))',
            ['bill_id' => $billId]
        );
    }

    /** ecs_get_bills_list(6 params) */
    public function getBillsList(?string $status, ?string $search, ?string $dateFrom, ?string $dateTo, int $limit, int $offset): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT * FROM ecs_get_bills_list(
                CAST(:status AS character varying),
                CAST(:search AS character varying),
                CAST(:date_from AS date),
                CAST(:date_to AS date),
                CAST(:limit AS integer),
                CAST(:offset AS integer)
            )',
            [
                'status' => $status,
                'search' => $search,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'limit' => $limit,
                'offset' => $offset,
            ]
        );
    }

    /** ecs_count_bills(4 params) */
    public function countBills(?string $status, ?string $search, ?string $dateFrom, ?string $dateTo): int
    {
        $total = $this->connection->fetchOne(
            'SELECT ecs_count_bills(
                CAST(:status AS character varying),
                CAST(:search AS character varying),
                CAST(:date_from AS date),
                CAST(:date_to AS date)
            )',
            ['status' => $status, 'search' => $search, 'date_from' => $dateFrom, 'date_to' => $dateTo]
        );

        return (int) $total;
    }

    /** ecs_get_billing_summary(0 params): one row of dashboard figures */
    public function getBillingSummary(): array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM ecs_get_billing_summary()');

        return $this->requireRow($row, 'ecs_get_billing_summary');
    }

    /** ecs_get_billable_consultations(3 params) */
    public function getBillableConsultations(?int $patientId, ?int $consultationId, int $limit): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT * FROM ecs_get_billable_consultations(
                CAST(:patient_id AS integer),
                CAST(:consultation_id AS integer),
                CAST(:limit AS integer)
            )',
            ['patient_id' => $patientId, 'consultation_id' => $consultationId, 'limit' => $limit]
        );
    }

    /**
     * The write functions always return exactly one row. If none comes back the
     * database is badly broken, so fail loudly instead of letting a "false"
     * travel up the layers and surface as a TypeError.
     */
    private function requireRow(array|false $row, string $function): array
    {
        if ($row === false) {
            throw new \RuntimeException(sprintf('Database function %s returned no row.', $function));
        }

        return $row;
    }
}