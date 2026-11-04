<?php

namespace App\DB;

use Doctrine\DBAL\Connection;

/**
 * Calls the Pharmacy PostgreSQL functions (sql/pharmacy/02_functions.sql).
 *
 * Same conventions as the other *DatabaseFunction classes: every value is a bound
 * parameter wrapped in CAST(... AS type), and the CAST types, bind names and argument
 * ORDER match the SQL signatures exactly. Booleans are sent as 'true' / 'false'.
 */
class PharmacyDatabaseFunction
{
    public function __construct(
        private Connection $connection
    ) {
    }

    // ------------------------------------------------------------------ medicines

    /**
     * ecs_save_medicine(9 params). $medicineId null creates, otherwise updates.
     *
     * @return array{success: bool, message: string, medicine_id: ?int, medicine_code: ?string}
     */
    public function saveMedicine(
        ?int $medicineId,
        string $name,
        ?string $generic,
        string $form,
        ?string $strength,
        string $unit,
        int $reorderLevel,
        bool $isActive,
        string $by
    ): array {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ecs_save_medicine(
                CAST(:medicine_id AS integer),
                CAST(:name AS character varying),
                CAST(:generic AS character varying),
                CAST(:form AS character varying),
                CAST(:strength AS character varying),
                CAST(:unit AS character varying),
                CAST(:reorder_level AS integer),
                CAST(:is_active AS boolean),
                CAST(:by AS character varying)
            )',
            [
                'medicine_id' => $medicineId,
                'name' => $name,
                'generic' => $generic,
                'form' => $form,
                'strength' => $strength,
                'unit' => $unit,
                'reorder_level' => $reorderLevel,
                'is_active' => $isActive ? 'true' : 'false',
                'by' => $by,
            ]
        );

        return $this->requireRow($row, 'ecs_save_medicine');
    }

    /** ecs_get_medicine_by_id(1 param). Null when it does not exist. */
    public function getMedicineById(int $medicineId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ecs_get_medicine_by_id(CAST(:medicine_id AS integer))',
            ['medicine_id' => $medicineId]
        );

        return $row === false ? null : $row;
    }

    /** ecs_get_medicines_list(5 params). $stock: all | in_stock | low | out | expiring */
    public function getMedicinesList(?string $search, ?string $form, ?string $stock, int $limit, int $offset): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT * FROM ecs_get_medicines_list(
                CAST(:search AS character varying),
                CAST(:form AS character varying),
                CAST(:stock AS character varying),
                CAST(:limit AS integer),
                CAST(:offset AS integer)
            )',
            ['search' => $search, 'form' => $form, 'stock' => $stock, 'limit' => $limit, 'offset' => $offset]
        );
    }

    /** ecs_count_medicines(3 params) */
    public function countMedicines(?string $search, ?string $form, ?string $stock): int
    {
        $total = $this->connection->fetchOne(
            'SELECT ecs_count_medicines(
                CAST(:search AS character varying),
                CAST(:form AS character varying),
                CAST(:stock AS character varying)
            )',
            ['search' => $search, 'form' => $form, 'stock' => $stock]
        );

        return (int) $total;
    }

    // ------------------------------------------------------------------ stock

    /**
     * ecs_receive_stock(7 params). $unitCost is a 2-decimal string or null.
     *
     * @return array{success: bool, message: string, stock_id: ?int}
     */
    public function receiveStock(
        int $medicineId,
        string $batchNumber,
        string $expiryDate,
        int $quantity,
        ?string $unitCost,
        ?string $supplier,
        string $by
    ): array {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ecs_receive_stock(
                CAST(:medicine_id AS integer),
                CAST(:batch_number AS character varying),
                CAST(:expiry_date AS date),
                CAST(:quantity AS integer),
                CAST(:unit_cost AS numeric),
                CAST(:supplier AS character varying),
                CAST(:by AS character varying)
            )',
            [
                'medicine_id' => $medicineId,
                'batch_number' => $batchNumber,
                'expiry_date' => $expiryDate,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'supplier' => $supplier,
                'by' => $by,
            ]
        );

        return $this->requireRow($row, 'ecs_receive_stock');
    }

    /**
     * ecs_adjust_stock(4 params)
     *
     * @return array{success: bool, message: string, quantity_available: ?int}
     */
    public function adjustStock(int $stockId, int $delta, string $reason, string $by): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ecs_adjust_stock(
                CAST(:stock_id AS integer),
                CAST(:delta AS integer),
                CAST(:reason AS character varying),
                CAST(:by AS character varying)
            )',
            ['stock_id' => $stockId, 'delta' => $delta, 'reason' => $reason, 'by' => $by]
        );

        return $this->requireRow($row, 'ecs_adjust_stock');
    }

    /** ecs_get_stock_batches(1 param) */
    public function getStockBatches(int $medicineId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT * FROM ecs_get_stock_batches(CAST(:medicine_id AS integer))',
            ['medicine_id' => $medicineId]
        );
    }

    /** ecs_get_stock_movements(2 params) */
    public function getStockMovements(int $medicineId, int $limit): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT * FROM ecs_get_stock_movements(
                CAST(:medicine_id AS integer),
                CAST(:limit AS integer)
            )',
            ['medicine_id' => $medicineId, 'limit' => $limit]
        );
    }

    // ------------------------------------------------------------------ prescribing (OP Consultation)

    /** ecs_search_prescribable_medicines(2 params): active medicines with stock only */
    public function searchPrescribableMedicines(?string $search, int $limit): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT * FROM ecs_search_prescribable_medicines(
                CAST(:search AS character varying),
                CAST(:limit AS integer)
            )',
            ['search' => $search, 'limit' => $limit]
        );
    }

    /** ecs_get_prescription_context(2 params): by consultation or by prescription. Null when not found. */
    public function getPrescriptionContext(?int $consultationId, ?int $prescriptionId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ecs_get_prescription_context(
                CAST(:consultation_id AS integer),
                CAST(:prescription_id AS integer)
            )',
            ['consultation_id' => $consultationId, 'prescription_id' => $prescriptionId]
        );

        return $row === false ? null : $row;
    }

    /** ecs_get_prescription_items(1 param) */
    public function getPrescriptionItems(int $prescriptionId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT * FROM ecs_get_prescription_items(CAST(:prescription_id AS integer))',
            ['prescription_id' => $prescriptionId]
        );
    }

    /**
     * ecs_add_prescription_item(8 params)
     *
     * @return array{success: bool, message: string, item_id: ?int, prescription_id: ?int, prescription_code: ?string}
     */
    public function addPrescriptionItem(
        int $consultationId,
        int $medicineId,
        string $dosage,
        string $frequency,
        int $durationDays,
        int $quantity,
        ?string $instructions,
        string $by
    ): array {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ecs_add_prescription_item(
                CAST(:consultation_id AS integer),
                CAST(:medicine_id AS integer),
                CAST(:dosage AS character varying),
                CAST(:frequency AS character varying),
                CAST(:duration_days AS integer),
                CAST(:quantity AS integer),
                CAST(:instructions AS character varying),
                CAST(:by AS character varying)
            )',
            [
                'consultation_id' => $consultationId,
                'medicine_id' => $medicineId,
                'dosage' => $dosage,
                'frequency' => $frequency,
                'duration_days' => $durationDays,
                'quantity' => $quantity,
                'instructions' => $instructions,
                'by' => $by,
            ]
        );

        return $this->requireRow($row, 'ecs_add_prescription_item');
    }

    /**
     * ecs_remove_prescription_item(2 params)
     *
     * @return array{success: bool, message: string}
     */
    public function removePrescriptionItem(int $itemId, string $by): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ecs_remove_prescription_item(
                CAST(:item_id AS integer),
                CAST(:by AS character varying)
            )',
            ['item_id' => $itemId, 'by' => $by]
        );

        return $this->requireRow($row, 'ecs_remove_prescription_item');
    }

    // ------------------------------------------------------------------ dispensing

    /** ecs_get_dispense_queue(4 params). $view: open | done */
    public function getDispenseQueue(string $view, ?string $search, int $limit, int $offset): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT * FROM ecs_get_dispense_queue(
                CAST(:view AS character varying),
                CAST(:search AS character varying),
                CAST(:limit AS integer),
                CAST(:offset AS integer)
            )',
            ['view' => $view, 'search' => $search, 'limit' => $limit, 'offset' => $offset]
        );
    }

    /** ecs_count_dispense_queue(2 params) */
    public function countDispenseQueue(string $view, ?string $search): int
    {
        $total = $this->connection->fetchOne(
            'SELECT ecs_count_dispense_queue(
                CAST(:view AS character varying),
                CAST(:search AS character varying)
            )',
            ['view' => $view, 'search' => $search]
        );

        return (int) $total;
    }

    /**
     * ecs_dispense_prescription(3 params). $itemsJson: [{"item_id": "12", "quantity": "5"}, ...]
     *
     * @return array{success: bool, message: string, status: ?string}
     */
    public function dispensePrescription(int $prescriptionId, string $itemsJson, string $by): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ecs_dispense_prescription(
                CAST(:prescription_id AS integer),
                CAST(:items AS jsonb),
                CAST(:by AS character varying)
            )',
            ['prescription_id' => $prescriptionId, 'items' => $itemsJson, 'by' => $by]
        );

        return $this->requireRow($row, 'ecs_dispense_prescription');
    }

    // ------------------------------------------------------------------ dashboard

    /** ecs_get_pharmacy_summary(0 params): one row of dashboard figures */
    public function getSummary(): array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM ecs_get_pharmacy_summary()');

        return $this->requireRow($row, 'ecs_get_pharmacy_summary');
    }

    /**
     * The write and summary functions always return exactly one row. If none comes back
     * the database is badly broken, so fail loudly instead of letting a "false" travel up
     * the layers and surface as a TypeError.
     */
    private function requireRow(array|false $row, string $function): array
    {
        if ($row === false) {
            throw new \RuntimeException(sprintf('Database function %s returned no row.', $function));
        }

        return $row;
    }
}
