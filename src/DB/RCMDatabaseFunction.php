<?php

namespace App\DB;

use Doctrine\DBAL\Connection;

/**
 * Calls the RCM PostgreSQL functions (sql/rcm/03_functions.sql), plus the two
 * existing Billing read functions RCM reuses to show a bill's items and payments.
 *
 * Same conventions as the other *DatabaseFunction classes: every value is a bound
 * parameter wrapped in CAST(... AS type), and the CAST types, bind names and
 * argument ORDER match the SQL signatures exactly.
 */
class RCMDatabaseFunction
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /** ecs_get_rcm_cases(6 params). $view is 'pending' or 'finalized'. */
    public function getCases(string $view, ?string $search, ?string $dateFrom, ?string $dateTo, int $limit, int $offset): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT * FROM ecs_get_rcm_cases(
                CAST(:view AS character varying),
                CAST(:search AS character varying),
                CAST(:date_from AS date),
                CAST(:date_to AS date),
                CAST(:limit AS integer),
                CAST(:offset AS integer)
            )',
            [
                'view' => $view,
                'search' => $search,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'limit' => $limit,
                'offset' => $offset,
            ]
        );
    }

    /** ecs_count_rcm_cases(4 params) */
    public function countCases(string $view, ?string $search, ?string $dateFrom, ?string $dateTo): int
    {
        $total = $this->connection->fetchOne(
            'SELECT ecs_count_rcm_cases(
                CAST(:view AS character varying),
                CAST(:search AS character varying),
                CAST(:date_from AS date),
                CAST(:date_to AS date)
            )',
            ['view' => $view, 'search' => $search, 'date_from' => $dateFrom, 'date_to' => $dateTo]
        );

        return (int) $total;
    }

    /** ecs_get_rcm_case_by_bill(1 param). Null when the bill does not exist. */
    public function getCaseByBill(int $billId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ecs_get_rcm_case_by_bill(CAST(:bill_id AS integer))',
            ['bill_id' => $billId]
        );

        return false === $row ? null : $row;
    }

    /** ecs_get_rcm_summary(0 params): one row of dashboard figures */
    public function getSummary(): array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM ecs_get_rcm_summary()');

        return $this->requireRow($row, 'ecs_get_rcm_summary');
    }

    /** ecs_get_rcm_history(1 param): audit trail of one bill */
    public function getHistory(int $billId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT * FROM ecs_get_rcm_history(CAST(:bill_id AS integer))',
            ['bill_id' => $billId]
        );
    }

    /** ecs_get_bill_items(1 param): existing Billing function, read only */
    public function getBillItems(int $billId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT * FROM ecs_get_bill_items(CAST(:bill_id AS integer))',
            ['bill_id' => $billId]
        );
    }

    /** ecs_get_payments_by_bill(1 param): existing Billing function, read only */
    public function getBillPayments(int $billId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT * FROM ecs_get_payments_by_bill(CAST(:bill_id AS integer))',
            ['bill_id' => $billId]
        );
    }

    /**
     * ecs_finalize_rcm(3 params).
     *
     * @return array{success: bool, message: string, rcm_id: ?int, already_finalized: bool, visit_note: ?string}
     */
    public function finalize(int $billId, ?string $remarks, string $finalizedBy): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ecs_finalize_rcm(
                CAST(:bill_id AS integer),
                CAST(:remarks AS character varying),
                CAST(:finalized_by AS character varying)
            )',
            ['bill_id' => $billId, 'remarks' => $remarks, 'finalized_by' => $finalizedBy]
        );

        return $this->requireRow($row, 'ecs_finalize_rcm');
    }

    /**
     * The summary and finalize functions always return exactly one row. If none
     * comes back the database is badly broken, so fail loudly instead of letting a
     * "false" travel up the layers and surface as a TypeError.
     */
    private function requireRow(array|false $row, string $function): array
    {
        if (false === $row) {
            throw new \RuntimeException(sprintf('Database function %s returned no row.', $function));
        }

        return $row;
    }
}
