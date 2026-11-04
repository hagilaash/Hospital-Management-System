<?php

namespace App\Repository;

use App\DAO\PharmacyDAO;
use App\DTO\MedicineDTO;
use App\DTO\PharmacySummaryDTO;
use App\DTO\PrescriptionDTO;

/**
 * Coordinates Pharmacy data access through PharmacyDAO and turns rows into DTOs.
 * A prescription is assembled here from its two database reads (context, items).
 * Write results are returned as the arrays the database produced. No business rules.
 */
class PharmacyRepository
{
    public const QUEUE_OPEN = 'open';
    public const QUEUE_DONE = 'done';

    public function __construct(
        private PharmacyDAO $pharmacyDAO
    ) {
    }

    // ------------------------------------------------------------------ medicines

    /**
     * @return array{success: bool, message: string, medicine_id: ?int, medicine_code: ?string}
     */
    public function saveMedicine(?int $medicineId, MedicineDTO $medicine, string $by): array
    {
        return $this->pharmacyDAO->saveMedicine($medicineId, $medicine, $by);
    }

    public function getMedicine(int $medicineId): ?MedicineDTO
    {
        $row = $this->pharmacyDAO->getMedicineById($medicineId);

        return $row === null ? null : MedicineDTO::fromRow($row);
    }

    /**
     * @return list<MedicineDTO>
     */
    public function getMedicines(?string $search, ?string $form, ?string $stock, int $limit, int $offset): array
    {
        $rows = $this->pharmacyDAO->getMedicinesList($search, $form, $stock, $limit, $offset);

        return array_map(static fn (array $row): MedicineDTO => MedicineDTO::fromRow($row), $rows);
    }

    public function countMedicines(?string $search, ?string $form, ?string $stock): int
    {
        return $this->pharmacyDAO->countMedicines($search, $form, $stock);
    }

    // ------------------------------------------------------------------ stock

    /**
     * @return array{success: bool, message: string, stock_id: ?int}
     */
    public function receiveStock(int $medicineId, string $batchNumber, string $expiryDate, int $quantity, ?string $unitCost, ?string $supplier, string $by): array
    {
        return $this->pharmacyDAO->receiveStock($medicineId, $batchNumber, $expiryDate, $quantity, $unitCost, $supplier, $by);
    }

    /**
     * @return array{success: bool, message: string, quantity_available: ?int}
     */
    public function adjustStock(int $stockId, int $delta, string $reason, string $by): array
    {
        return $this->pharmacyDAO->adjustStock($stockId, $delta, $reason, $by);
    }

    /** @return list<array<string, mixed>> */
    public function getStockBatches(int $medicineId): array
    {
        return $this->pharmacyDAO->getStockBatches($medicineId);
    }

    /** @return list<array<string, mixed>> */
    public function getStockMovements(int $medicineId, int $limit): array
    {
        return $this->pharmacyDAO->getStockMovements($medicineId, $limit);
    }

    // ------------------------------------------------------------------ prescribing

    /**
     * Active medicines with non-expired stock only.
     *
     * @return list<MedicineDTO>
     */
    public function searchPrescribable(?string $search, int $limit): array
    {
        $rows = $this->pharmacyDAO->searchPrescribableMedicines($search, $limit);

        return array_map(static fn (array $row): MedicineDTO => MedicineDTO::fromRow($row), $rows);
    }

    /** The prescription of a consultation or by prescription id, with its items; null when not found. */
    public function getPrescription(?int $consultationId, ?int $prescriptionId): ?PrescriptionDTO
    {
        $row = $this->pharmacyDAO->getPrescriptionContext($consultationId, $prescriptionId);
        if ($row === null) {
            return null;
        }

        $prescription = PrescriptionDTO::fromRow($row);
        if ($prescription->prescriptionId !== null) {
            $prescription->items = $this->pharmacyDAO->getPrescriptionItems($prescription->prescriptionId);
            $prescription->itemCount = count($prescription->items);
        }

        return $prescription;
    }

    /**
     * @return array{success: bool, message: string, item_id: ?int, prescription_id: ?int, prescription_code: ?string}
     */
    public function addPrescriptionItem(int $consultationId, int $medicineId, string $dosage, string $frequency, int $durationDays, int $quantity, ?string $instructions, string $by): array
    {
        return $this->pharmacyDAO->addPrescriptionItem($consultationId, $medicineId, $dosage, $frequency, $durationDays, $quantity, $instructions, $by);
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function removePrescriptionItem(int $itemId, string $by): array
    {
        return $this->pharmacyDAO->removePrescriptionItem($itemId, $by);
    }

    // ------------------------------------------------------------------ dispensing

    /**
     * @return list<PrescriptionDTO>
     */
    public function getDispenseQueue(string $view, ?string $search, int $limit, int $offset): array
    {
        $rows = $this->pharmacyDAO->getDispenseQueue($view, $search, $limit, $offset);

        return array_map(static fn (array $row): PrescriptionDTO => PrescriptionDTO::fromRow($row), $rows);
    }

    public function countDispenseQueue(string $view, ?string $search): int
    {
        return $this->pharmacyDAO->countDispenseQueue($view, $search);
    }

    /**
     * @param array<int, int> $quantities prescription item id => quantity
     *
     * @return array{success: bool, message: string, status: ?string}
     */
    public function dispense(int $prescriptionId, array $quantities, string $by): array
    {
        return $this->pharmacyDAO->dispensePrescription($prescriptionId, $quantities, $by);
    }

    // ------------------------------------------------------------------ dashboard

    public function getSummary(): PharmacySummaryDTO
    {
        return PharmacySummaryDTO::fromRow($this->pharmacyDAO->getSummary());
    }
}
