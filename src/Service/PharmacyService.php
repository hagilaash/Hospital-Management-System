<?php

namespace App\Service;

use App\Common\Input;
use App\DTO\MedicineDTO;
use App\DTO\PagedListDTO;
use App\DTO\PharmacySummaryDTO;
use App\DTO\PrescriptionDTO;
use App\Repository\PharmacyRepository;

/**
 * Business logic for the Pharmacy: medicines, stock, prescribing from OP Consultation
 * and dispensing.
 *
 * - Cleans and validates every field before it reaches the database. The database then
 *   enforces the same limits plus the rules that need locking: never prescribe more than
 *   the non-expired stock, no prescribing after the consultation has been sent to billing,
 *   dispense earliest-expiry-first, never dispense expired stock, never dispense more than
 *   what is left on the prescription, and all-or-nothing dispensing.
 * - Prices and billing of medicines are deliberately NOT part of this module.
 *
 * Every write returns one array shape:
 *
 *   [
 *     'success'         => bool,
 *     'message'         => string,                  // safe to show to the user
 *     'errors'          => array<string, string>,   // fieldName => message
 *     'medicine_id'     => ?int,
 *     'stock_id'        => ?int,
 *     'item_id'         => ?int,
 *     'prescription_id' => ?int,
 *     'status'          => ?string,                 // prescription status after dispensing
 *   ]
 */
class PharmacyService
{
    public const FORMS = ['Tablet', 'Capsule', 'Syrup', 'Injection', 'Ointment', 'Drops', 'Inhaler', 'Other'];
    public const STOCK_FILTERS = ['all', 'in_stock', 'low', 'out', 'expiring'];
    public const FREQUENCIES = [
        'Once a day', 'Twice a day', 'Three times a day', 'Four times a day',
        'Every 8 hours', 'Every 12 hours', 'At bedtime', 'As needed',
    ];

    public const PER_PAGE = 10;
    public const SEARCH_RESULTS = 20;
    public const MOVEMENTS_SHOWN = 20;

    private const MESSAGE_VALIDATION = 'Please correct the errors below';
    private const MESSAGE_NOT_SIGNED_IN = 'You must be signed in to perform this action';

    public function __construct(
        private PharmacyRepository $pharmacyRepository,
    ) {
    }

    // ------------------------------------------------------------------
    // Reads
    // ------------------------------------------------------------------

    public function getSummary(): PharmacySummaryDTO
    {
        return $this->pharmacyRepository->getSummary();
    }

    /**
     * The medicine catalogue with stock figures, 10 per page.
     * Filters: search (name, generic name, code), form, stock (all / in_stock / low / out / expiring).
     */
    public function getMedicines(?string $search, ?string $form, ?string $stock, int $page = 1): PagedListDTO
    {
        $search = Input::search($search);
        $form = Input::whitelist($form, self::FORMS, '');
        $form = '' === $form ? null : $form;
        $stock = Input::whitelist($stock, self::STOCK_FILTERS, 'all');

        $total = $this->pharmacyRepository->countMedicines($search, $form, $stock);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $page), $totalPages);

        $items = $total > 0
            ? $this->pharmacyRepository->getMedicines($search, $form, $stock, self::PER_PAGE, ($page - 1) * self::PER_PAGE)
            : [];

        return new PagedListDTO($items, $total, $page, self::PER_PAGE, $totalPages, ['search' => $search, 'form' => $form, 'stock' => $stock]);
    }

    public function getMedicine(int $medicineId): ?MedicineDTO
    {
        return $medicineId < 1 ? null : $this->pharmacyRepository->getMedicine($medicineId);
    }

    /** @return list<array<string, mixed>> */
    public function getStockBatches(int $medicineId): array
    {
        return $this->pharmacyRepository->getStockBatches($medicineId);
    }

    /** @return list<array<string, mixed>> */
    public function getStockMovements(int $medicineId): array
    {
        return $this->pharmacyRepository->getStockMovements($medicineId, self::MOVEMENTS_SHOWN);
    }

    /**
     * The medicines a doctor may prescribe: active, with non-expired stock. Each result
     * carries its available quantity.
     *
     * @return list<MedicineDTO>
     */
    public function searchPrescribable(?string $search): array
    {
        return $this->pharmacyRepository->searchPrescribable(Input::search($search), self::SEARCH_RESULTS);
    }

    /** A consultation's prescription (header and items), or null when the consultation does not exist. */
    public function getPrescriptionForConsultation(int $consultationId): ?PrescriptionDTO
    {
        return $consultationId < 1 ? null : $this->pharmacyRepository->getPrescription($consultationId, null);
    }

    public function getPrescription(int $prescriptionId): ?PrescriptionDTO
    {
        return $prescriptionId < 1 ? null : $this->pharmacyRepository->getPrescription(null, $prescriptionId);
    }

    /**
     * The dispensing queue, 10 per page. $view: 'open' (waiting to be dispensed, oldest first)
     * or 'done' (dispensed, newest first).
     */
    public function getDispenseQueue(?string $view, ?string $search, int $page = 1): PagedListDTO
    {
        $view = Input::whitelist($view, [PharmacyRepository::QUEUE_OPEN, PharmacyRepository::QUEUE_DONE], PharmacyRepository::QUEUE_OPEN);
        $search = Input::search($search);

        $total = $this->pharmacyRepository->countDispenseQueue($view, $search);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $page), $totalPages);

        $items = $total > 0
            ? $this->pharmacyRepository->getDispenseQueue($view, $search, self::PER_PAGE, ($page - 1) * self::PER_PAGE)
            : [];

        return new PagedListDTO($items, $total, $page, self::PER_PAGE, $totalPages, ['view' => $view, 'search' => $search]);
    }

    // ------------------------------------------------------------------
    // Medicines
    // ------------------------------------------------------------------

    /** $medicineId null adds a medicine, otherwise edits it. */
    public function saveMedicine(?int $medicineId, MedicineDTO $input, string $performedBy): array
    {
        $performedBy = trim($performedBy);
        if ('' === $performedBy) {
            return $this->failure(self::MESSAGE_NOT_SIGNED_IN);
        }

        $medicine = new MedicineDTO();
        $medicine->medicineName = Input::clean($input->medicineName);
        $medicine->genericName = Input::nullIfEmpty(Input::clean($input->genericName));
        $medicine->form = Input::whitelist($input->form, self::FORMS, '');
        $medicine->strength = Input::nullIfEmpty(Input::clean($input->strength));
        $medicine->unit = Input::clean($input->unit);
        $medicine->reorderLevel = $input->reorderLevel;
        $medicine->isActive = $input->isActive;

        $errors = [];
        if ('' === $medicine->medicineName) {
            $errors['medicineName'] = 'Medicine name is required';
        } elseif (mb_strlen($medicine->medicineName) > 150) {
            $errors['medicineName'] = 'Medicine name must be 150 characters or fewer';
        }
        if (null !== $medicine->genericName && mb_strlen($medicine->genericName) > 150) {
            $errors['genericName'] = 'Generic name must be 150 characters or fewer';
        }
        if ('' === $medicine->form) {
            $errors['form'] = 'Select a form';
        }
        if (null !== $medicine->strength && mb_strlen($medicine->strength) > 50) {
            $errors['strength'] = 'Strength must be 50 characters or fewer';
        }
        if ('' === $medicine->unit) {
            $errors['unit'] = 'Unit is required, for example tablet, ml or vial';
        } elseif (mb_strlen($medicine->unit) > 20) {
            $errors['unit'] = 'Unit must be 20 characters or fewer';
        }
        if ($medicine->reorderLevel < 0 || $medicine->reorderLevel > 1000000) {
            $errors['reorderLevel'] = 'Reorder level must be a whole number from 0 to 1000000';
        }
        if ([] !== $errors) {
            return $this->failure(self::MESSAGE_VALIDATION, $errors);
        }

        $db = $this->pharmacyRepository->saveMedicine($medicineId, $medicine, $performedBy);

        $result = $this->resultFrom($db, [
            'Medicine name' => 'medicineName',
            'This medicine' => 'medicineName',
            'Select a valid form' => 'form',
            'Unit is required' => 'unit',
            'Strength must' => 'strength',
            'Generic name' => 'genericName',
            'Reorder level' => 'reorderLevel',
        ]);
        $result['medicine_id'] = isset($db['medicine_id']) ? (int) $db['medicine_id'] : null;

        return $result;
    }

    // ------------------------------------------------------------------
    // Stock
    // ------------------------------------------------------------------

    public function receiveStock(
        int $medicineId,
        ?string $batchNumber,
        ?string $expiryDate,
        ?string $quantity,
        ?string $unitCost,
        ?string $supplier,
        string $performedBy,
    ): array {
        if ($medicineId < 1) {
            return $this->failure('Medicine not found');
        }
        $performedBy = trim($performedBy);
        if ('' === $performedBy) {
            return $this->failure(self::MESSAGE_NOT_SIGNED_IN);
        }

        $errors = [];

        $batch = Input::clean($batchNumber);
        if ('' === $batch) {
            $errors['batchNumber'] = 'A batch number is required';
        } elseif (mb_strlen($batch) > 50) {
            $errors['batchNumber'] = 'The batch number must be 50 characters or fewer';
        }

        $expiry = trim((string) $expiryDate);
        if (!Input::isValidDate($expiry)) {
            $errors['expiryDate'] = 'Enter a valid expiry date';
        }

        $quantityText = trim((string) $quantity);
        if (1 !== preg_match('/^\d{1,7}$/', $quantityText) || (int) $quantityText < 1 || (int) $quantityText > 1000000) {
            $errors['quantity'] = 'The quantity must be a whole number from 1 to 1000000';
        }

        $cost = null;
        if ('' !== trim((string) $unitCost)) {
            $cost = Input::money($unitCost, 8);
            if (null === $cost) {
                $errors['unitCost'] = 'The unit cost must be an amount with at most 2 decimals';
            }
        }

        $supplierText = Input::nullIfEmpty(Input::clean($supplier));
        if (null !== $supplierText && mb_strlen($supplierText) > 150) {
            $errors['supplier'] = 'The supplier must be 150 characters or fewer';
        }

        if ([] !== $errors) {
            return $this->failure(self::MESSAGE_VALIDATION, $errors);
        }

        $db = $this->pharmacyRepository->receiveStock($medicineId, $batch, $expiry, (int) $quantityText, $cost, $supplierText, $performedBy);

        $result = $this->resultFrom($db, [
            'A batch number' => 'batchNumber',
            'Batch ' => 'batchNumber',
            'An expiry date' => 'expiryDate',
            'The expiry date' => 'expiryDate',
            'The quantity' => 'quantity',
            'The unit cost' => 'unitCost',
            'The supplier' => 'supplier',
        ]);
        $result['stock_id'] = isset($db['stock_id']) ? (int) $db['stock_id'] : null;

        return $result;
    }

    /**
     * $change is a signed whole number: "10" adds ten, "-5" removes five from that batch.
     * A reason is mandatory (damage, expiry write-off, stock-take difference ...).
     */
    public function adjustStock(int $stockId, ?string $change, ?string $reason, string $performedBy): array
    {
        if ($stockId < 1) {
            return $this->failure('Stock batch not found');
        }
        $performedBy = trim($performedBy);
        if ('' === $performedBy) {
            return $this->failure(self::MESSAGE_NOT_SIGNED_IN);
        }

        $errors = [];
        $changeText = str_replace('+', '', trim((string) $change));
        if (1 !== preg_match('/^-?\d{1,7}$/', $changeText) || 0 === (int) $changeText || abs((int) $changeText) > 1000000) {
            $errors['change'] = 'Enter a whole number other than zero (negative removes stock)';
        }
        $reasonText = Input::clean($reason);
        if ('' === $reasonText) {
            $errors['reason'] = 'A reason is required';
        } elseif (mb_strlen($reasonText) > 255) {
            $errors['reason'] = 'The reason must be 255 characters or fewer';
        }
        if ([] !== $errors) {
            return $this->failure(self::MESSAGE_VALIDATION, $errors);
        }

        $db = $this->pharmacyRepository->adjustStock($stockId, (int) $changeText, $reasonText, $performedBy);

        $result = $this->resultFrom($db, ['Cannot remove' => 'change', 'Enter a quantity' => 'change', 'A reason' => 'reason']);
        $result['stock_id'] = $stockId;

        return $result;
    }

    // ------------------------------------------------------------------
    // Prescribing (OP Consultation)
    // ------------------------------------------------------------------

    public function addPrescriptionItem(
        int $consultationId,
        ?string $medicineId,
        ?string $dosage,
        ?string $frequency,
        ?string $durationDays,
        ?string $quantity,
        ?string $instructions,
        string $performedBy,
    ): array {
        if ($consultationId < 1) {
            return $this->failure('Consultation not found');
        }
        $performedBy = trim($performedBy);
        if ('' === $performedBy) {
            return $this->failure(self::MESSAGE_NOT_SIGNED_IN);
        }

        $errors = [];

        $medicine = Input::positiveInt($medicineId);
        if (null === $medicine) {
            $errors['medicineId'] = 'Select a medicine';
        }

        $dosageText = Input::clean($dosage);
        if ('' === $dosageText) {
            $errors['dosage'] = 'The dosage is required, for example 1 tablet';
        } elseif (mb_strlen($dosageText) > 50) {
            $errors['dosage'] = 'The dosage must be 50 characters or fewer';
        }

        $frequencyText = trim((string) $frequency);
        if (!in_array($frequencyText, self::FREQUENCIES, true)) {
            $errors['frequency'] = 'Select how often the medicine is taken';
        }

        $daysText = trim((string) $durationDays);
        if (1 !== preg_match('/^\d{1,3}$/', $daysText) || (int) $daysText < 1 || (int) $daysText > 365) {
            $errors['durationDays'] = 'The duration must be from 1 to 365 days';
        }

        $quantityText = trim((string) $quantity);
        if (1 !== preg_match('/^\d{1,4}$/', $quantityText) || (int) $quantityText < 1) {
            $errors['quantity'] = 'The quantity must be a whole number from 1 to 9999';
        }

        $instructionText = Input::nullIfEmpty(Input::clean($instructions));
        if (null !== $instructionText && mb_strlen($instructionText) > 255) {
            $errors['instructions'] = 'The instructions must be 255 characters or fewer';
        }

        if ([] !== $errors) {
            return $this->failure(self::MESSAGE_VALIDATION, $errors);
        }

        $db = $this->pharmacyRepository->addPrescriptionItem(
            $consultationId,
            (int) $medicine,
            $dosageText,
            $frequencyText,
            (int) $daysText,
            (int) $quantityText,
            $instructionText,
            $performedBy
        );

        $result = $this->resultFrom($db, [
            'Only ' => 'quantity',
            'The quantity' => 'quantity',
            'The duration' => 'durationDays',
            'The dosage' => 'dosage',
            'The frequency' => 'frequency',
            'Medicine not found' => 'medicineId',
            'This medicine is already' => 'medicineId',
        ]);
        $result['item_id'] = isset($db['item_id']) ? (int) $db['item_id'] : null;
        $result['prescription_id'] = isset($db['prescription_id']) ? (int) $db['prescription_id'] : null;

        return $result;
    }

    public function removePrescriptionItem(int $itemId, string $performedBy): array
    {
        if ($itemId < 1) {
            return $this->failure('Prescription item not found');
        }
        $performedBy = trim($performedBy);
        if ('' === $performedBy) {
            return $this->failure(self::MESSAGE_NOT_SIGNED_IN);
        }

        return $this->resultFrom($this->pharmacyRepository->removePrescriptionItem($itemId, $performedBy), []);
    }

    // ------------------------------------------------------------------
    // Dispensing
    // ------------------------------------------------------------------

    /**
     * @param array<int|string, mixed> $quantities prescription item id => quantity typed by the pharmacist;
     *                                             blank or 0 means "not now"
     */
    public function dispense(int $prescriptionId, array $quantities, string $performedBy): array
    {
        if ($prescriptionId < 1) {
            return $this->failure('Prescription not found');
        }
        $performedBy = trim($performedBy);
        if ('' === $performedBy) {
            return $this->failure(self::MESSAGE_NOT_SIGNED_IN);
        }

        $lines = [];
        $errors = [];
        foreach ($quantities as $itemId => $text) {
            $id = Input::positiveInt((string) $itemId);
            $text = is_scalar($text) ? trim((string) $text) : '';
            if (null === $id || '' === $text || '0' === $text) {
                continue;
            }
            if (1 !== preg_match('/^\d{1,5}$/', $text) || (int) $text < 1) {
                $errors['qty_'.$itemId] = 'Enter a whole number';
                continue;
            }
            $lines[$id] = (int) $text;
        }

        if ([] !== $errors) {
            return $this->failure(self::MESSAGE_VALIDATION, $errors);
        }
        if ([] === $lines) {
            return $this->failure('Enter the quantity to dispense for at least one medicine');
        }

        $db = $this->pharmacyRepository->dispense($prescriptionId, $lines, $performedBy);

        $result = $this->resultFrom($db, []);
        $result['prescription_id'] = $prescriptionId;
        $result['status'] = isset($db['status']) ? (string) $db['status'] : null;

        return $result;
    }

    // ------------------------------------------------------------------
    // Result building
    // ------------------------------------------------------------------

    /**
     * success + message from a database row; a refusal that belongs to one field is also
     * attached to that field so the form can show it next to the input.
     *
     * @param array<string, string> $fieldMap database message prefix => form field
     */
    private function resultFrom(array $db, array $fieldMap): array
    {
        $result = $this->failure((string) ($db['message'] ?? ''));
        $result['success'] = Input::isTrue($db['success'] ?? false);

        if (!$result['success']) {
            foreach ($fieldMap as $prefix => $field) {
                if (str_starts_with($result['message'], $prefix)) {
                    $result['errors'][$field] = $result['message'];
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * The uniform result shape, pre-filled as a failure.
     *
     * @param array<string, string> $errors
     */
    private function failure(string $message, array $errors = []): array
    {
        return [
            'success' => false,
            'message' => $message,
            'errors' => $errors,
            'medicine_id' => null,
            'stock_id' => null,
            'item_id' => null,
            'prescription_id' => null,
            'status' => null,
        ];
    }
}
