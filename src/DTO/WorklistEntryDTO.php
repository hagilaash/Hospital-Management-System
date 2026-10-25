<?php

namespace App\DTO;

/**
 * One row of today's OP Consultation worklist: an open queue entry plus the
 * consultation that belongs to it, if one has been started.
 */
class WorklistEntryDTO
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

    public string $queueStatus = 'Waiting';
    public ?string $arrivalAt = null;

    public ?int $consultationId = null;
    public ?string $consultationCode = null;
    public ?string $consultationStatus = null;

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
        $walkIn = $row['is_walk_in'] ?? false;
        $dto->isWalkIn = is_bool($walkIn) ? $walkIn : in_array(strtolower((string) $walkIn), ['t', 'true', '1', 'y', 'yes', 'on'], true);

        $dto->queueStatus = (string) ($row['queue_status'] ?? 'Waiting');
        $dto->arrivalAt = self::nullableString($row['arrival_at'] ?? null);

        $dto->consultationId = self::nullableInt($row['consultation_id'] ?? null);
        $dto->consultationCode = self::nullableString($row['consultation_code'] ?? null);
        $dto->consultationStatus = self::nullableString($row['consultation_status'] ?? null);

        return $dto;
    }

    public function hasConsultation(): bool
    {
        return null !== $this->consultationId;
        print_r($this->consultationId);
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
