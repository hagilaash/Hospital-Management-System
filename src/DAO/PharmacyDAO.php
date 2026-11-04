<?php

namespace App\DAO;

use App\DB\PharmacyDatabaseFunction;
use App\DTO\MedicineDTO;

/**
 * Executes the Pharmacy database operations through PharmacyDatabaseFunction. It only
 * unpacks DTOs into scalar arguments (and the dispense lines into the JSON the SQL
 * function reads) and returns the raw database results; it contains no business rules.
 */
class PharmacyDAO
{
    public function __construct(
        private PharmacyDatabaseFunction $pharmacyDatabaseFunction
    ) {
    }

    public function saveMedicine(?int $medicineId, MedicineDTO $medicine, string $by): array
    {
        return $this->pharmacyDatabaseFunction->saveMedicine(
            $medicineId,
            $medicine->medicineName,
            $medicine->genericName,
            $medicine->form,
            $medicine->strength,
            $medicine->unit,
            $medicine->reorderLevel,
            $medicine->isActive,
            $by
        );
    }

    public function getMedicineById(int $medicineId): ?array
    {
        return $this->pharmacyDatabaseFunction->getMedicineById($medicineId);
    }

    public function getMedicinesList(?string $search, ?string $form, ?string $stock, int $limit, int $offset): array
    {
        return $this->pharmacyDatabaseFunction->getMedicinesList($search, $form, $stock, $limit, $offset);
    }

    public function countMedicines(?string $search, ?string $form, ?string $stock): int
    {
        return $this->pharmacyDatabaseFunction->countMedicines($search, $form, $stock);
    }

    public function receiveStock(int $medicineId, string $batchNumber, string $expiryDate, int $quantity, ?string $unitCost, ?string $supplier, string $by): array
    {
        return $this->pharmacyDatabaseFunction->receiveStock($medicineId, $batchNumber, $expiryDate, $quantity, $unitCost, $supplier, $by);
    }

    public function adjustStock(int $stockId, int $delta, string $reason, string $by): array
    {
        return $this->pharmacyDatabaseFunction->adjustStock($stockId, $delta, $reason, $by);
    }

    public function getStockBatches(int $medicineId): array
    {
        return $this->pharmacyDatabaseFunction->getStockBatches($medicineId);
    }

    public function getStockMovements(int $medicineId, int $limit): array
    {
        return $this->pharmacyDatabaseFunction->getStockMovements($medicineId, $limit);
    }

    public function searchPrescribableMedicines(?string $search, int $limit): array
    {
        return $this->pharmacyDatabaseFunction->searchPrescribableMedicines($search, $limit);
    }

    public function getPrescriptionContext(?int $consultationId, ?int $prescriptionId): ?array
    {
        return $this->pharmacyDatabaseFunction->getPrescriptionContext($consultationId, $prescriptionId);
    }

    public function getPrescriptionItems(int $prescriptionId): array
    {
        return $this->pharmacyDatabaseFunction->getPrescriptionItems($prescriptionId);
    }

    public function addPrescriptionItem(int $consultationId, int $medicineId, string $dosage, string $frequency, int $durationDays, int $quantity, ?string $instructions, string $by): array
    {
        return $this->pharmacyDatabaseFunction->addPrescriptionItem($consultationId, $medicineId, $dosage, $frequency, $durationDays, $quantity, $instructions, $by);
    }

    public function removePrescriptionItem(int $itemId, string $by): array
    {
        return $this->pharmacyDatabaseFunction->removePrescriptionItem($itemId, $by);
    }

    public function getDispenseQueue(string $view, ?string $search, int $limit, int $offset): array
    {
        return $this->pharmacyDatabaseFunction->getDispenseQueue($view, $search, $limit, $offset);
    }

    public function countDispenseQueue(string $view, ?string $search): int
    {
        return $this->pharmacyDatabaseFunction->countDispenseQueue($view, $search);
    }

    /**
     * @param array<int, int> $quantities prescription item id => quantity to dispense
     */
    public function dispensePrescription(int $prescriptionId, array $quantities, string $by): array
    {
        $lines = [];
        foreach ($quantities as $itemId => $quantity) {
            $lines[] = ['item_id' => (string) $itemId, 'quantity' => (string) $quantity];
        }

        return $this->pharmacyDatabaseFunction->dispensePrescription(
            $prescriptionId,
            json_encode($lines, JSON_THROW_ON_ERROR),
            $by
        );
    }

    public function getSummary(): array
    {
        return $this->pharmacyDatabaseFunction->getSummary();
    }
}
