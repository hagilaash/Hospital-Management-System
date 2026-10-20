<?php

namespace App\DTO;

/**
 * One row of the consultation queue (ecs_get_queue_list).
 */
class QueueEntryDTO
{
    public ?int $queueId = null;
    public ?int $tokenNumber = null;

    public ?int $patientId = null;
    public ?string $patientCode = null;
    public ?string $patientName = null;
    public ?string $patientPhone = null;

    public ?int $doctorId = null;
    public ?string $doctorName = null;
    public ?string $specialization = null;

    public ?int $appointmentId = null;
    public ?string $appointmentCode = null;
    public ?string $appointmentTime = null;
    public bool $isWalkIn = false;

    public ?string $visitDate = null;
    public ?string $arrivalAt = null;
    public string $status = 'Waiting';
    public ?string $consultationStartedAt = null;
    public ?string $completedAt = null;
    /** Minutes spent waiting so far; only set for Waiting entries. */
    public ?int $waitingMinutes = null;

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $dto = new self();

        $dto->queueId = self::nullableInt($row['queue_id'] ?? null);
        $dto->tokenNumber = self::nullableInt($row['token_number'] ?? null);

        $dto->patientId = self::nullableInt($row['patient_id'] ?? null);
        $dto->patientCode = self::nullableString($row['patient_code'] ?? null);
        $dto->patientName = self::nullableString($row['patient_name'] ?? null);
        $dto->patientPhone = self::nullableString($row['patient_phone'] ?? null);

        $dto->doctorId = self::nullableInt($row['doctor_id'] ?? null);
        $dto->doctorName = self::nullableString($row['doctor_name'] ?? null);
        $dto->specialization = self::nullableString($row['specialization'] ?? null);

        $dto->appointmentId = self::nullableInt($row['appointment_id'] ?? null);
        $dto->appointmentCode = self::nullableString($row['appointment_code'] ?? null);
        $time = $row['appointment_time'] ?? null;
        $dto->appointmentTime = is_scalar($time) && '' !== (string) $time ? substr((string) $time, 0, 5) : null;
        $dto->isWalkIn = self::toBool($row['is_walk_in'] ?? false);

        $dto->visitDate = self::nullableString($row['visit_date'] ?? null);
        $dto->arrivalAt = self::nullableString($row['arrival_at'] ?? null);
        $dto->status = (string) ($row['status'] ?? 'Waiting');
        $dto->consultationStartedAt = self::nullableString($row['consultation_started_at'] ?? null);
        $dto->completedAt = self::nullableString($row['completed_at'] ?? null);
        $dto->waitingMinutes = self::nullableInt($row['waiting_minutes'] ?? null);

        return $dto;
    }

    public function isOpen(): bool
    {
        return 'Waiting' === $this->status || 'In Consultation' === $this->status;
    }

    public function canStart(): bool
    {
        return 'Waiting' === $this->status;
    }

    public function canComplete(): bool
    {
        return 'In Consultation' === $this->status;
    }

    public function canCancel(): bool
    {
        return $this->isOpen();
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    private static function nullableInt(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['t', 'true', '1', 'y', 'yes', 'on'], true);
    }
}
