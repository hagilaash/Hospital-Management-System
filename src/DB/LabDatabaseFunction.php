<?php

namespace App\DB;

use Doctrine\DBAL\Connection;

/**
 * Calls the Laboratory PostgreSQL functions (sql/lab/02_functions.sql).
 *
 * Same conventions as the other *DatabaseFunction classes: every value is a bound parameter wrapped in
 * CAST(... AS type), and the CAST types, bind names and argument ORDER match the SQL signatures exactly.
 */
class LabDatabaseFunction
{
    public function __construct(
        private Connection $connection
    ) {
    }

    // ------------------------------------------------------------------ catalogue

    /**
     * ecs_save_lab_test(11 params). $testId null creates, otherwise updates.
     *
     * @return array{success: bool, message: string, test_id: ?int, test_code: ?string}
     */
    public function saveTest(
        ?int $testId,
        string $name,
        string $category,
        string $sampleType,
        string $resultType,
        ?string $unit,
        ?string $refLow,
        ?string $refHigh,
        ?string $referenceText,
        bool $isActive,
        string $by
    ): array {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ecs_save_lab_test(
                CAST(:test_id AS integer),
                CAST(:name AS character varying),
                CAST(:category AS character varying),
                CAST(:sample_type AS character varying),
                CAST(:result_type AS character varying),
                CAST(:unit AS character varying),
                CAST(:ref_low AS numeric),
                CAST(:ref_high AS numeric),
                CAST(:reference_text AS character varying),
                CAST(:is_active AS boolean),
                CAST(:by AS character varying)
            )',
            [
                'test_id' => $testId,
                'name' => $name,
                'category' => $category,
                'sample_type' => $sampleType,
                'result_type' => $resultType,
                'unit' => $unit,
                'ref_low' => $refLow,
                'ref_high' => $refHigh,
                'reference_text' => $referenceText,
                'is_active' => $isActive ? 'true' : 'false',
                'by' => $by,
            ]
        );

        return $this->requireRow($row, 'ecs_save_lab_test');
    }

    /** ecs_get_lab_test_by_id(1 param). Null when it does not exist. */
    public function getTestById(int $testId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ecs_get_lab_test_by_id(CAST(:test_id AS integer))',
            ['test_id' => $testId]
        );

        return $row === false ? null : $row;
    }

    /** ecs_get_lab_tests_list(5 params). $status: all | active | inactive */
    public function getTestsList(?string $search, ?string $category, ?string $status, int $limit, int $offset): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT * FROM ecs_get_lab_tests_list(
                CAST(:search AS character varying),
                CAST(:category AS character varying),
                CAST(:status AS character varying),
                CAST(:limit AS integer),
                CAST(:offset AS integer)
            )',
            ['search' => $search, 'category' => $category, 'status' => $status, 'limit' => $limit, 'offset' => $offset]
        );
    }

    /** ecs_count_lab_tests(3 params) */
    public function countTests(?string $search, ?string $category, ?string $status): int
    {
        $total = $this->connection->fetchOne(
            'SELECT ecs_count_lab_tests(
                CAST(:search AS character varying),
                CAST(:category AS character varying),
                CAST(:status AS character varying)
            )',
            ['search' => $search, 'category' => $category, 'status' => $status]
        );

        return (int) $total;
    }

    /** ecs_search_orderable_lab_tests(2 params): active tests only */
    public function searchOrderableTests(?string $search, int $limit): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT * FROM ecs_search_orderable_lab_tests(
                CAST(:search AS character varying),
                CAST(:limit AS integer)
            )',
            ['search' => $search, 'limit' => $limit]
        );
    }

    // ------------------------------------------------------------------ one order

    /** ecs_get_lab_order_context(2 params): by consultation or by order. Null when not found. */
    public function getOrderContext(?int $consultationId, ?int $orderId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ecs_get_lab_order_context(
                CAST(:consultation_id AS integer),
                CAST(:order_id AS integer)
            )',
            ['consultation_id' => $consultationId, 'order_id' => $orderId]
        );

        return $row === false ? null : $row;
    }

    /** ecs_get_lab_order_items(1 param) */
    public function getOrderItems(int $orderId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT * FROM ecs_get_lab_order_items(CAST(:order_id AS integer))',
            ['order_id' => $orderId]
        );
    }

    /** ecs_get_lab_order_samples(1 param) */
    public function getOrderSamples(int $orderId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT * FROM ecs_get_lab_order_samples(CAST(:order_id AS integer))',
            ['order_id' => $orderId]
        );
    }

    // ------------------------------------------------------------------ the doctor

    /**
     * ecs_add_lab_order_item(5 params)
     *
     * @return array{success: bool, message: string, item_id: ?int, order_id: ?int, order_code: ?string}
     */
    public function addOrderItem(int $consultationId, int $testId, string $priority, ?string $notes, string $by): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ecs_add_lab_order_item(
                CAST(:consultation_id AS integer),
                CAST(:test_id AS integer),
                CAST(:priority AS character varying),
                CAST(:notes AS character varying),
                CAST(:by AS character varying)
            )',
            ['consultation_id' => $consultationId, 'test_id' => $testId, 'priority' => $priority, 'notes' => $notes, 'by' => $by]
        );

        return $this->requireRow($row, 'ecs_add_lab_order_item');
    }

    /**
     * ecs_remove_lab_order_item(2 params)
     *
     * @return array{success: bool, message: string}
     */
    public function removeOrderItem(int $itemId, string $by): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ecs_remove_lab_order_item(
                CAST(:item_id AS integer),
                CAST(:by AS character varying)
            )',
            ['item_id' => $itemId, 'by' => $by]
        );

        return $this->requireRow($row, 'ecs_remove_lab_order_item');
    }

    // ------------------------------------------------------------------ the lab

    /** ecs_get_lab_worklist(4 params). $view: pending | done */
    public function getWorklist(string $view, ?string $search, int $limit, int $offset): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT * FROM ecs_get_lab_worklist(
                CAST(:view AS character varying),
                CAST(:search AS character varying),
                CAST(:limit AS integer),
                CAST(:offset AS integer)
            )',
            ['view' => $view, 'search' => $search, 'limit' => $limit, 'offset' => $offset]
        );
    }

    /** ecs_count_lab_worklist(2 params) */
    public function countWorklist(string $view, ?string $search): int
    {
        $total = $this->connection->fetchOne(
            'SELECT ecs_count_lab_worklist(
                CAST(:view AS character varying),
                CAST(:search AS character varying)
            )',
            ['view' => $view, 'search' => $search]
        );

        return (int) $total;
    }

    /**
     * ecs_collect_lab_sample(4 params)
     *
     * @return array{success: bool, message: string, sample_id: ?int, sample_code: ?string, test_count: int}
     */
    public function collectSample(int $orderId, string $sampleType, ?string $notes, string $by): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ecs_collect_lab_sample(
                CAST(:order_id AS integer),
                CAST(:sample_type AS character varying),
                CAST(:notes AS character varying),
                CAST(:by AS character varying)
            )',
            ['order_id' => $orderId, 'sample_type' => $sampleType, 'notes' => $notes, 'by' => $by]
        );

        return $this->requireRow($row, 'ecs_collect_lab_sample');
    }

    /**
     * ecs_enter_lab_result(5 params)
     *
     * @return array{success: bool, message: string, result_flag: ?string}
     */
    public function enterResult(int $itemId, string $value, ?string $flag, ?string $comment, string $by): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ecs_enter_lab_result(
                CAST(:item_id AS integer),
                CAST(:value AS character varying),
                CAST(:flag AS character varying),
                CAST(:comment AS character varying),
                CAST(:by AS character varying)
            )',
            ['item_id' => $itemId, 'value' => $value, 'flag' => $flag, 'comment' => $comment, 'by' => $by]
        );

        return $this->requireRow($row, 'ecs_enter_lab_result');
    }

    /**
     * ecs_cancel_lab_item(3 params)
     *
     * @return array{success: bool, message: string}
     */
    public function cancelItem(int $itemId, string $reason, string $by): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ecs_cancel_lab_item(
                CAST(:item_id AS integer),
                CAST(:reason AS character varying),
                CAST(:by AS character varying)
            )',
            ['item_id' => $itemId, 'reason' => $reason, 'by' => $by]
        );

        return $this->requireRow($row, 'ecs_cancel_lab_item');
    }

    // ------------------------------------------------------------------ dashboard

    /** ecs_get_lab_summary(0 params): one row of dashboard figures */
    public function getSummary(): array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM ecs_get_lab_summary()');

        return $this->requireRow($row, 'ecs_get_lab_summary');
    }

    /**
     * The write and summary functions always return exactly one row. If none comes back the database is
     * badly broken, so fail loudly instead of letting a "false" travel up the layers and surface as a TypeError.
     */
    private function requireRow(array|false $row, string $function): array
    {
        if ($row === false) {
            throw new \RuntimeException(sprintf('Database function %s returned no row.', $function));
        }

        return $row;
    }
}