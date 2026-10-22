<?php

namespace App\DTO;

/**
 * One OP consultation, in both directions:
 *  - fromInput(): the controller fills the editable clinical fields from the form;
 *  - fromRow():   the repository fills it from a database row (detail or list).
 *
 * Data only: cleaning and validation belong to ConsultationService. List rows
 * carry fewer columns than a detail row; the columns not selected stay null.
 */
class ConsultationDTO
{
    public const STATUS_IN_PROGRESS = 'In Progress';
    public const STATUS_READY_FOR_BILLING = 'Ready for Billing';
    public const STATUS_COMPLETED = 'Completed';

    public ?int $consultationId = null;
    public ?string $consultationCode = null;

    public ?int $patientId = null;
    public ?string $patientCode = null;
    public ?string $patientName = null;
    public ?string $patientPhone = null;
    public ?string $patientGender = null;
    public ?string $patientDob = null;

    public ?int $doctorId = null;
    public ?string $doctorName = null;
    public ?string $specialization = null;

    public ?int $appointmentId = null;
    public ?string $appointmentCode = null;
    public ?string $appointmentDate = null;
    public ?string $appointmentTime = null;

    public ?int $queueId = null;
    public ?int $tokenNumber = null;
    public ?string $queueStatus = null;

    public string $status = self::STATUS_IN_PROGRESS;
    public ?string $chiefComplaint = null;
    public ?string $diagnosis = null;
    public ?string $advice = null;
    public ?string $notes = null;

    public ?string $startedAt = null;
    public ?string $readyForBillingAt = null;
    public ?string $completedAt = null;
    public ?string $updatedAt = null;
    public ?string $createdBy = null;
    public ?string $updatedBy = null;

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $dto = new self();

        $dto->consultationId = self::nullableInt($row['consultation_id'] ?? null);
        $dto->consultationCode = self::nullableString($row['consultation_code'] ?? null);

        $dto->patientId = self::nullableInt($row['patient_id'] ?? null);
        $dto->patientCode = self::nullableString($row['patient_code'] ?? null);
        $dto->patientName = self::nullableString($row['patient_name'] ?? null);
        $dto->patientPhone = self::nullableString($row['patient_phone'] ?? null);
        $dto->patientGender = self::nullableString($row['patient_gender'] ?? null);
        $dto->patientDob = self::nullableString($row['patient_dob'] ?? null);

        $dto->doctorId = self::nullableInt($row['doctor_id'] ?? null);
        $dto->doctorName = self::nullableString($row['doctor_name'] ?? null);
        $dto->specialization = self::nullableString($row['specialization'] ?? null);

        $dto->appointmentId = self::nullableInt($row['appointment_id'] ?? null);
        $dto->appointmentCode = self::nullableString($row['appointment_code'] ?? null);
        $dto->appointmentDate = self::nullableString($row['appointment_date'] ?? null);
        $time = $row['appointment_time'] ?? null;
        $dto->appointmentTime = is_scalar($time) && (string) $time !== '' ? substr((string) $time, 0, 5) : null;

        $dto->queueId = self::nullableInt($row['queue_id'] ?? null);
        $dto->tokenNumber = self::nullableInt($row['token_number'] ?? null);
        $dto->queueStatus = self::nullableString($row['queue_status'] ?? null);

        $dto->status = (string) ($row['status'] ?? self::STATUS_IN_PROGRESS);
        $dto->chiefComplaint = self::nullableString($row['chief_complaint'] ?? null);
        $dto->diagnosis = self::nullableString($row['diagnosis'] ?? null);
        $dto->advice = self::nullableString($row['advice'] ?? null);
        $dto->notes = self::nullableString($row['notes'] ?? null);

        $dto->startedAt = self::nullableString($row['started_at'] ?? null);
        $dto->readyForBillingAt = self::nullableString($row['ready_for_billing_at'] ?? null);
        $dto->completedAt = self::nullableString($row['completed_at'] ?? null);
        $dto->updatedAt = self::nullableString($row['updated_at'] ?? null);
        $dto->createdBy = self::nullableString($row['created_by'] ?? null);
        $dto->updatedBy = self::nullableString($row['updated_by'] ?? null);

        return $dto;
    }

    /**
     * Reads ONLY the four editable clinical fields, so a crafted request cannot
     * change the patient, the doctor, the status or any identifier.
     * Non-scalar values (for example diagnosis[]=x) are ignored.
     *
     * @param array<string, mixed> $input
     */
    public static function fromInput(array $input): self
    {
        $dto = new self();

        $dto->chiefComplaint = self::nullableString($input['chief_complaint'] ?? null);
        $dto->diagnosis = self::nullableString($input['diagnosis'] ?? null);
        $dto->advice = self::nullableString($input['advice'] ?? null);
        $dto->notes = self::nullableString($input['notes'] ?? null);

        return $dto;
    }

    /** Only a consultation in progress can be edited. */
    public function isEditable(): bool
    {
        return $this->status === self::STATUS_IN_PROGRESS;
    }

    public function isReadyForBilling(): bool
    {
        return $this->status === self::STATUS_READY_FOR_BILLING;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    private static function nullableInt(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }
}