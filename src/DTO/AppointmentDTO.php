<?php

namespace App\DTO;

/**
 * One appointment, in both directions:
 *  - fromInput(): the controller fills it from the submitted form;
 *  - fromRow():   the repository fills it from a database row (list or detail).
 *
 * Data only. Cleaning and validation belong to FDMService. Dates are 'Y-m-d'
 * strings and times are 'H:i' strings so they go straight into the form's
 * <input type="date"> / <input type="time">. List rows contain fewer columns
 * than a detail row; the columns that were not selected stay null.
 */
class AppointmentDTO
{
    public ?int $appointmentId = null;
    public ?string $appointmentCode = null;

    public ?int $patientId = null;
    public ?string $patientCode = null;
    public ?string $patientName = null;
    public ?string $patientPhone = null;

    public ?int $doctorId = null;
    public ?string $doctorName = null;
    public ?string $specialization = null;
    public ?string $department = null;

    public string $appointmentDate = '';
    public string $startTime = '';
    public ?string $endTime = null;
    public int $durationMinutes = 15;
    /** The duration exactly as typed, so an invalid value can be shown again. */
    public string $durationInput = '15';

    public string $status = 'Scheduled';
    public ?string $reason = null;
    public ?string $cancelReason = null;
    public ?string $cancelledAt = null;
    public ?string $cancelledBy = null;

    public ?int $queueId = null;
    public ?int $tokenNumber = null;
    public ?string $queueStatus = null;
    public ?string $arrivalAt = null;

    public ?string $createdAt = null;
    public ?string $updatedAt = null;
    public ?string $createdBy = null;
    public ?string $updatedBy = null;

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $dto = new self();

        $dto->appointmentId = self::nullableInt($row['appointment_id'] ?? null);
        $dto->appointmentCode = self::nullableString($row['appointment_code'] ?? null);

        $dto->patientId = self::nullableInt($row['patient_id'] ?? null);
        $dto->patientCode = self::nullableString($row['patient_code'] ?? null);
        $dto->patientName = self::nullableString($row['patient_name'] ?? null);
        $dto->patientPhone = self::nullableString($row['patient_phone'] ?? null);

        $dto->doctorId = self::nullableInt($row['doctor_id'] ?? null);
        $dto->doctorName = self::nullableString($row['doctor_name'] ?? null);
        $dto->specialization = self::nullableString($row['specialization'] ?? null);
        $dto->department = self::nullableString($row['department'] ?? null);

        $dto->appointmentDate = (string) ($row['appointment_date'] ?? '');
        $dto->startTime = self::shortTime($row['start_time'] ?? null) ?? '';
        $dto->endTime = self::shortTime($row['end_time'] ?? null);
        $dto->durationMinutes = (int) ($row['duration_minutes'] ?? 15);
        $dto->durationInput = (string) $dto->durationMinutes;

        $dto->status = (string) ($row['status'] ?? 'Scheduled');
        $dto->reason = self::nullableString($row['reason'] ?? null);
        $dto->cancelReason = self::nullableString($row['cancel_reason'] ?? null);
        $dto->cancelledAt = self::nullableString($row['cancelled_at'] ?? null);
        $dto->cancelledBy = self::nullableString($row['cancelled_by'] ?? null);

        $dto->queueId = self::nullableInt($row['queue_id'] ?? null);
        $dto->tokenNumber = self::nullableInt($row['token_number'] ?? null);
        $dto->queueStatus = self::nullableString($row['queue_status'] ?? null);
        $dto->arrivalAt = self::nullableString($row['arrival_at'] ?? null);

        $dto->createdAt = self::nullableString($row['created_at'] ?? null);
        $dto->updatedAt = self::nullableString($row['updated_at'] ?? null);
        $dto->createdBy = self::nullableString($row['created_by'] ?? null);
        $dto->updatedBy = self::nullableString($row['updated_by'] ?? null);

        return $dto;
    }

    /**
     * Builds a DTO from submitted form data. Only the editable fields are read,
     * so a crafted request cannot set the status, the appointment number or
     * audit fields. Non-scalar values (for example doctor_id[]=1) are ignored.
     *
     * @param array<string, mixed> $input
     */
    public static function fromInput(array $input): self
    {
        $dto = new self();

        $dto->patientId = self::nullableInt($input['patient_id'] ?? null);
        $dto->doctorId = self::nullableInt($input['doctor_id'] ?? null);
        $dto->appointmentDate = self::nullableString($input['appointment_date'] ?? null) ?? '';
        $dto->startTime = self::nullableString($input['start_time'] ?? null) ?? '';
        $dto->durationInput = self::nullableString($input['duration_minutes'] ?? null) ?? '';
        $dto->durationMinutes = ctype_digit($dto->durationInput) ? (int) $dto->durationInput : 0;
        $dto->reason = self::nullableString($input['reason'] ?? null);

        return $dto;
    }

    public function isScheduled(): bool
    {
        return 'Scheduled' === $this->status;
    }

    /** Only a Scheduled appointment can be edited or cancelled directly. */
    public function canEdit(): bool
    {
        return $this->isScheduled();
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    /** Digits only (no signs, no decimals); anything else is null. */
    private static function nullableInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value) && strlen($value) <= 9) {
            return (int) $value;
        }

        return null;
    }

    /** '10:00:00' -> '10:00'. */
    private static function shortTime(mixed $value): ?string
    {
        if (!is_scalar($value) || '' === (string) $value) {
            return null;
        }

        return substr((string) $value, 0, 5);
    }
}
