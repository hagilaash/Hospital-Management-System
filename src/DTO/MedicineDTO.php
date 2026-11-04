<?php

namespace App\DTO;

/**
 * One medicine of the pharmacy catalogue, with its current stock figures.
 *  - fromInput(): the controller fills the editable fields from the medicine form;
 *  - fromRow():   the repository fills it from a database row (list or detail).
 * Data only: cleaning and validation belong to PharmacyService.
 */
class MedicineDTO
{
    public ?int $medicineId = null;
    public ?string $medicineCode = null;
    public string $medicineName = '';
    public ?string $genericName = null;
    public string $form = 'Tablet';
    public ?string $strength = null;
    public string $unit = '';
    public int $reorderLevel = 0;
    public bool $isActive = true;

    /** Stock that can be prescribed and dispensed: not expired. */
    public int $availableQuantity = 0;
    /** Of the available stock, what expires within 30 days. */
    public int $expiringQuantity = 0;
    /** Stock still on the shelf past its expiry date (never prescribed or dispensed). */
    public int $expiredQuantity = 0;
    public ?string $nearestExpiry = null;

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $dto = new self();

        $dto->medicineId = self::nullableInt($row['medicine_id'] ?? null);
        $dto->medicineCode = self::nullableString($row['medicine_code'] ?? null);
        $dto->medicineName = (string) ($row['medicine_name'] ?? '');
        $dto->genericName = self::nullableString($row['generic_name'] ?? null);
        $dto->form = (string) ($row['form'] ?? 'Tablet');
        $dto->strength = self::nullableString($row['strength'] ?? null);
        $dto->unit = (string) ($row['unit'] ?? '');
        $dto->reorderLevel = (int) ($row['reorder_level'] ?? 0);
        $flag = $row['is_active'] ?? true;
        $dto->isActive = $flag === true || $flag === 't' || $flag === 'true' || $flag === 1 || $flag === '1';

        $dto->availableQuantity = (int) ($row['available_quantity'] ?? 0);
        $dto->expiringQuantity = (int) ($row['expiring_quantity'] ?? 0);
        $dto->expiredQuantity = (int) ($row['expired_quantity'] ?? 0);
        $dto->nearestExpiry = self::nullableString($row['nearest_expiry'] ?? null);

        return $dto;
    }

    /**
     * Reads ONLY the editable fields (never an id or a stock figure). The checkbox
     * "is_active" is present when ticked and absent when not.
     *
     * @param array<string, mixed> $input
     */
    public static function fromInput(array $input): self
    {
        $dto = new self();

        $dto->medicineName = trim((string) self::nullableString($input['medicine_name'] ?? null));
        $dto->genericName = self::nullableString($input['generic_name'] ?? null);
        $dto->form = trim((string) self::nullableString($input['form'] ?? null));
        $dto->strength = self::nullableString($input['strength'] ?? null);
        $dto->unit = trim((string) self::nullableString($input['unit'] ?? null));
        $reorder = trim((string) self::nullableString($input['reorder_level'] ?? null));
        $dto->reorderLevel = ctype_digit($reorder) && strlen($reorder) <= 7 ? (int) $reorder : -1; // -1 = invalid, rejected by the service
        $dto->isActive = isset($input['is_active']) && is_scalar($input['is_active']) && (string) $input['is_active'] !== '' && (string) $input['is_active'] !== '0';

        return $dto;
    }

    /** "Paracetamol 500 mg" */
    public function displayName(): string
    {
        return $this->strength !== null && $this->strength !== '' ? $this->medicineName . ' ' . $this->strength : $this->medicineName;
    }

    public function isOutOfStock(): bool
    {
        return $this->availableQuantity === 0;
    }

    /** At or below its reorder level (only when a reorder level has been set). */
    public function isLowStock(): bool
    {
        return $this->reorderLevel > 0 && $this->availableQuantity <= $this->reorderLevel;
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    private static function nullableInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && ctype_digit($value) && strlen($value) <= 9 ? (int) $value : null;
    }
}
