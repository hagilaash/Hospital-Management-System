<?php

namespace App\DTO;

/**
 * One bill, in both directions:
 *  - fromInput(): the controller fills the editable fields from the "new bill" form;
 *  - fromRow():   the repository fills it from a database row (list or detail).
 *
 * All money values are STRINGS exactly as PostgreSQL numeric(12,2) returns them
 * ("1500.00"); they are never converted to floats. Data only: cleaning and
 * validation belong to BillingService, and every amount is recalculated by
 * PostgreSQL, so nothing the browser sends as a total is ever used.
 */
class BillDTO
{
    public const STATUS_PENDING = 'Pending';
    public const STATUS_PARTIALLY_PAID = 'Partially Paid';
    public const STATUS_PAID = 'Paid';
    public const STATUS_CANCELLED = 'Cancelled';

    public ?int $billId = null;
    public ?string $billNumber = null;
    public ?string $billDate = null;

    public ?int $patientId = null;
    public ?string $patientCode = null;
    public ?string $patientName = null;
    public ?string $patientPhone = null;
    public ?string $patientAddress = null;

    public ?int $doctorId = null;
    public ?string $doctorName = null;
    public ?string $specialization = null;

    public ?int $appointmentId = null;
    public ?string $appointmentCode = null;
    public ?string $appointmentDate = null;

    public ?int $consultationId = null;
    public ?string $consultationCode = null;

    public string $subtotalAmount = '0.00';
    public string $discountAmount = '0.00';
    public ?string $discountReason = null;
    public string $taxPercent = '0.00';
    public string $taxAmount = '0.00';
    public string $totalAmount = '0.00';
    public string $paidAmount = '0.00';
    public string $balanceAmount = '0.00';

    public string $status = self::STATUS_PENDING;
    public ?string $notes = null;
    public ?string $cancelReason = null;
    public ?string $cancelledAt = null;
    public ?string $cancelledBy = null;
    public ?string $createdBy = null;

    /**
     * Form rows (description, quantity, unit_price as typed) on input, or the
     * stored items (item_id, line_no, description, quantity, unit_price, line_total) on output.
     *
     * @var list<array<string, mixed>>
     */
    public array $items = [];

    /** @var list<array<string, mixed>> payment history (detail page only) */
    public array $payments = [];

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $dto = new self();

        $dto->billId = self::nullableInt($row['bill_id'] ?? null);
        $dto->billNumber = self::nullableString($row['bill_number'] ?? null);
        $dto->billDate = self::nullableString($row['bill_date'] ?? null);

        $dto->patientId = self::nullableInt($row['patient_id'] ?? null);
        $dto->patientCode = self::nullableString($row['patient_code'] ?? null);
        $dto->patientName = self::nullableString($row['patient_name'] ?? null);
        $dto->patientPhone = self::nullableString($row['patient_phone'] ?? null);
        $dto->patientAddress = self::nullableString($row['patient_address'] ?? null);

        $dto->doctorId = self::nullableInt($row['doctor_id'] ?? null);
        $dto->doctorName = self::nullableString($row['doctor_name'] ?? null);
        $dto->specialization = self::nullableString($row['specialization'] ?? null);

        $dto->appointmentId = self::nullableInt($row['appointment_id'] ?? null);
        $dto->appointmentCode = self::nullableString($row['appointment_code'] ?? null);
        $dto->appointmentDate = self::nullableString($row['appointment_date'] ?? null);

        $dto->consultationId = self::nullableInt($row['consultation_id'] ?? null);
        $dto->consultationCode = self::nullableString($row['consultation_code'] ?? null);

        $dto->subtotalAmount = self::money($row['subtotal_amount'] ?? null);
        $dto->discountAmount = self::money($row['discount_amount'] ?? null);
        $dto->discountReason = self::nullableString($row['discount_reason'] ?? null);
        $dto->taxPercent = self::money($row['tax_percent'] ?? null);
        $dto->taxAmount = self::money($row['tax_amount'] ?? null);
        $dto->totalAmount = self::money($row['total_amount'] ?? null);
        $dto->paidAmount = self::money($row['paid_amount'] ?? null);
        $dto->balanceAmount = self::money($row['balance_amount'] ?? null);

        $dto->status = (string) ($row['status'] ?? self::STATUS_PENDING);
        $dto->notes = self::nullableString($row['notes'] ?? null);
        $dto->cancelReason = self::nullableString($row['cancel_reason'] ?? null);
        $dto->cancelledAt = self::nullableString($row['cancelled_at'] ?? null);
        $dto->cancelledBy = self::nullableString($row['cancelled_by'] ?? null);
        $dto->createdBy = self::nullableString($row['created_by'] ?? null);

        return $dto;
    }

    /**
     * Reads ONLY what the "new bill" form offers. Totals, status, paid amount and
     * bill number are never read from the request. Item rows that are completely
     * blank are dropped; non-scalar values are ignored.
     *
     * @param array<string, mixed> $input
     */
    public static function fromInput(array $input): self
    {
        $dto = new self();

        $dto->patientId = self::nullableInt($input['patient_id'] ?? null);
        $dto->consultationId = self::nullableInt($input['consultation_id'] ?? null);
        $dto->discountAmount = self::nullableString($input['discount_amount'] ?? null) ?? '';
        $dto->discountReason = self::nullableString($input['discount_reason'] ?? null);
        $dto->taxPercent = self::nullableString($input['tax_percent'] ?? null) ?? '';
        $dto->notes = self::nullableString($input['notes'] ?? null);

        $descriptions = is_array($input['item_description'] ?? null) ? array_values($input['item_description']) : [];
        $quantities = is_array($input['item_quantity'] ?? null) ? array_values($input['item_quantity']) : [];
        $prices = is_array($input['item_price'] ?? null) ? array_values($input['item_price']) : [];

        foreach ($descriptions as $i => $description) {
            $row = [
                'description' => trim((string) self::nullableString($description)),
                'quantity' => trim((string) self::nullableString($quantities[$i] ?? null)),
                'unit_price' => trim((string) self::nullableString($prices[$i] ?? null)),
            ];
            if ($row['description'] === '' && $row['quantity'] === '' && $row['unit_price'] === '') {
                continue;
            }
            $dto->items[] = $row;
        }

        return $dto;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isPartiallyPaid(): bool
    {
        return $this->status === self::STATUS_PARTIALLY_PAID;
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    /** A payment can be recorded while something is still owed. */
    public function canPay(): bool
    {
        return $this->isPending() || $this->isPartiallyPaid();
    }

    /** Only a bill with nothing paid on it can be cancelled. */
    public function canCancel(): bool
    {
        return $this->isPending() && $this->paidAmount === '0.00';
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

    /** numeric(12,2) as PDO returns it ("1500" or "1500.00") -> always "1500.00". */
    private static function money(mixed $value): string
    {
        return is_scalar($value) && is_numeric((string) $value) ? number_format((float) $value, 2, '.', '') : '0.00';
    }
}