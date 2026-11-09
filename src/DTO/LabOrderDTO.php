<?php

namespace App\DTO;

/**
 * A consultation's lab order: header (consultation, patient, doctor, order) plus its tests
 * (with results) and samples. Also used for one row of the lab worklist (the item_count ...
 * abnormal_count columns). Data only.
 *
 * orderId is null until the doctor orders the first test.
 */
class LabOrderDTO
{
    public const STATUS_ORDERED = 'Ordered';
    public const STATUS_IN_PROGRESS = 'In Progress';
    public const STATUS_COMPLETED = 'Completed';
    public const STATUS_CANCELLED = 'Cancelled';

    public ?int $consultationId = null;
    public ?string $consultationCode = null;
    public ?string $consultationStatus = null;

    public ?int $patientId = null;
    public ?string $patientCode = null;
    public ?string $patientName = null;
    public ?string $patientPhone = null;
    public ?string $patientGender = null;
    public ?string $patientDob = null;
    public ?int $doctorId = null;
    public ?string $doctorName = null;

    public ?int $orderId = null;
    public ?string $orderCode = null;
    public ?string $orderStatus = null;
    public string $priority = 'Routine';
    public ?string $clinicalNotes = null;
    public ?string $createdAt = null;

    public int $itemCount = 0;
    public int $awaitingSample = 0;
    public int $awaitingResult = 0;
    public int $resultedCount = 0;
    public int $abnormalCount = 0;

    /** @var list<array<string, mixed>> tests with their results */
    public array $items = [];
    /** @var list<array<string, mixed>> */
    public array $samples = [];

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $dto = new self();

        $dto->consultationId = self::nullableInt($row['consultation_id'] ?? null);
        $dto->consultationCode = self::nullableString($row['consultation_code'] ?? null);
        $dto->consultationStatus = self::nullableString($row['consultation_status'] ?? null);

        $dto->patientId = self::nullableInt($row['patient_id'] ?? null);
        $dto->patientCode = self::nullableString($row['patient_code'] ?? null);
        $dto->patientName = self::nullableString($row['patient_name'] ?? null);
        $dto->patientPhone = self::nullableString($row['patient_phone'] ?? null);
        $dto->patientGender = self::nullableString($row['patient_gender'] ?? null);
        $dto->patientDob = self::nullableString($row['patient_dob'] ?? null);
        $dto->doctorId = self::nullableInt($row['doctor_id'] ?? null);
        $dto->doctorName = self::nullableString($row['doctor_name'] ?? null);

        $dto->orderId = self::nullableInt($row['order_id'] ?? null);
        $dto->orderCode = self::nullableString($row['order_code'] ?? null);
        $dto->orderStatus = self::nullableString($row['order_status'] ?? null);
        $dto->priority = (string) ($row['priority'] ?? 'Routine');
        $dto->clinicalNotes = self::nullableString($row['clinical_notes'] ?? null);
        $dto->createdAt = self::nullableString($row['order_created_at'] ?? $row['created_at'] ?? null);

        $dto->itemCount = (int) ($row['item_count'] ?? 0);
        $dto->awaitingSample = (int) ($row['awaiting_sample'] ?? 0);
        $dto->awaitingResult = (int) ($row['awaiting_result'] ?? 0);
        $dto->resultedCount = (int) ($row['resulted_count'] ?? 0);
        $dto->abnormalCount = (int) ($row['abnormal_count'] ?? 0);

        return $dto;
    }

    public function hasOrder(): bool
    {
        return null !== $this->orderId;
    }

    /** The doctor can add or remove tests only while the consultation is in progress. */
    public function canEdit(): bool
    {
        return 'In Progress' === $this->consultationStatus;
    }

    public function isUrgent(): bool
    {
        return 'Urgent' === $this->priority;
    }

    /** Work is still to be done in the lab. */
    public function isOpen(): bool
    {
        return self::STATUS_ORDERED === $this->orderStatus || self::STATUS_IN_PROGRESS === $this->orderStatus;
    }

    public function isCompleted(): bool
    {
        return self::STATUS_COMPLETED === $this->orderStatus;
    }

    /**
     * The sample types still to be collected for tests that are waiting, e.g. ['Blood', 'Urine'].
     *
     * @return list<string>
     */
    public function sampleTypesWaiting(): array
    {
        $types = [];
        foreach ($this->items as $item) {
            if (($item['status'] ?? '') === 'Ordered' && isset($item['sample_type'])) {
                $types[(string) $item['sample_type']] = true;
            }
        }

        return array_keys($types);
    }

    /** True when at least one test has been resulted. */
    public function hasResults(): bool
    {
        foreach ($this->items as $item) {
            if (($item['status'] ?? '') === 'Resulted') {
                return true;
            }
        }

        return false;
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
