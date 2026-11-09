<?php

namespace App\DTO;

/**
 * A consultation's prescription: header (consultation, patient, doctor, prescription)
 * plus its medicine lines. Also used for one row of the dispensing queue (the
 * item_count / total_quantity / dispensed_quantity columns). Data only.
 *
 * prescriptionId is null until the doctor adds the first medicine.
 */
class PrescriptionDTO
{
    public const STATUS_PENDING = 'Pending';
    public const STATUS_PARTIAL = 'Partially Dispensed';
    public const STATUS_DISPENSED = 'Dispensed';

    public ?int $consultationId = null;
    public ?string $consultationCode = null;
    public ?string $consultationStatus = null;

    public ?int $patientId = null;
    public ?string $patientCode = null;
    public ?string $patientName = null;
    public ?string $patientPhone = null;
    public ?int $doctorId = null;
    public ?string $doctorName = null;

    public ?int $prescriptionId = null;
    public ?string $prescriptionCode = null;
    public ?string $status = null;
    public ?string $createdAt = null;

    public int $itemCount = 0;
    public int $totalQuantity = 0;
    public int $dispensedQuantity = 0;

    /** @var list<array<string, mixed>> */
    public array $items = [];

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
        $dto->doctorId = self::nullableInt($row['doctor_id'] ?? null);
        $dto->doctorName = self::nullableString($row['doctor_name'] ?? null);

        $dto->prescriptionId = self::nullableInt($row['prescription_id'] ?? null);
        $dto->prescriptionCode = self::nullableString($row['prescription_code'] ?? null);
        // the context function says prescription_status, the queue function says status
        $dto->status = self::nullableString($row['prescription_status'] ?? $row['status'] ?? null);
        $dto->createdAt = self::nullableString($row['prescription_created_at'] ?? $row['created_at'] ?? null);

        $dto->itemCount = (int) ($row['item_count'] ?? 0);
        $dto->totalQuantity = (int) ($row['total_quantity'] ?? 0);
        $dto->dispensedQuantity = (int) ($row['dispensed_quantity'] ?? 0);

        return $dto;
    }

    public function hasPrescription(): bool
    {
        return null !== $this->prescriptionId;
    }

    /** The doctor can add or remove medicines only while the consultation is in progress. */
    public function canEdit(): bool
    {
        return 'In Progress' === $this->consultationStatus;
    }

    public function isDispensed(): bool
    {
        return self::STATUS_DISPENSED === $this->status;
    }

    /** Pharmacy can dispense once the doctor has finished and something is still left. */
    public function canDispense(): bool
    {
        return $this->hasPrescription() && !$this->canEdit() && !$this->isDispensed() && [] !== $this->items;
    }
}
