<?php

namespace App\DTO;

/**
 * One RCM case = one bill that is (or was) in the RCM workflow, with the patient,
 * visit and billing information RCM staff review. Used for a queue/completed row
 * (fewer columns) and for the full review page. Data only: no business rules.
 *
 * Money values are STRINGS exactly as PostgreSQL numeric returns them, normalised
 * to 2 decimals; they are never converted to floats for calculation.
 */
class RCMCaseDTO
{
    public const STATUS_PENDING = 'Pending';
    public const STATUS_FINALIZED = 'Finalized';
    public const STATUS_AWAITING_BILLING = 'Awaiting Billing';

    public ?int $billId = null;
    public ?string $billNumber = null;
    public ?string $billDate = null;
    public string $totalAmount = '0.00';
    public string $paidAmount = '0.00';
    public string $balanceAmount = '0.00';
    public ?string $billStatus = null;
    public int $paymentCount = 0;
    public ?string $lastPaymentAt = null;

    public ?int $patientId = null;
    public ?string $patientCode = null;
    public ?string $patientName = null;
    public ?string $patientPhone = null;
    public ?string $patientEmail = null;
    public ?string $patientGender = null;
    public ?string $patientDob = null;
    public ?string $patientAddress = null;

    public ?int $doctorId = null;
    public ?string $doctorName = null;
    public ?string $specialization = null;

    public ?int $appointmentId = null;
    public ?string $appointmentCode = null;
    public ?string $appointmentDate = null;
    public ?string $appointmentTime = null;
    public ?string $appointmentStatus = null;
    public ?int $consultationId = null;
    public ?string $consultationCode = null;

    public string $rcmStatus = self::STATUS_PENDING;
    public bool $canFinalize = false;
    public ?string $blockReason = null;
    public ?int $rcmId = null;
    /** When billing was completed (last payment): how long the case has been waiting. */
    public ?string $readySince = null;
    public ?string $finalizedAt = null;
    public ?string $finalizedBy = null;
    public ?string $remarks = null;

    /** @var list<array<string, mixed>> bill items (review page only) */
    public array $items = [];
    /** @var list<array<string, mixed>> payments (review page only) */
    public array $payments = [];
    /** @var list<array<string, mixed>> audit history (review page only) */
    public array $history = [];

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $dto = new self();

        $dto->billId = self::nullableInt($row['bill_id'] ?? null);
        $dto->billNumber = self::nullableString($row['bill_number'] ?? null);
        $dto->billDate = self::nullableString($row['bill_date'] ?? null);
        $dto->totalAmount = self::money($row['total_amount'] ?? null);
        $dto->paidAmount = self::money($row['paid_amount'] ?? null);
        $dto->balanceAmount = self::money($row['balance_amount'] ?? null);
        $dto->billStatus = self::nullableString($row['bill_status'] ?? null);
        $dto->paymentCount = (int) ($row['payment_count'] ?? 0);
        $dto->lastPaymentAt = self::nullableString($row['last_payment_at'] ?? null);

        $dto->patientId = self::nullableInt($row['patient_id'] ?? null);
        $dto->patientCode = self::nullableString($row['patient_code'] ?? null);
        $dto->patientName = self::nullableString($row['patient_name'] ?? null);
        $dto->patientPhone = self::nullableString($row['patient_phone'] ?? null);
        $dto->patientEmail = self::nullableString($row['patient_email'] ?? null);
        $dto->patientGender = self::nullableString($row['patient_gender'] ?? null);
        $dto->patientDob = self::nullableString($row['patient_dob'] ?? null);
        $dto->patientAddress = self::nullableString($row['patient_address'] ?? null);

        $dto->doctorId = self::nullableInt($row['doctor_id'] ?? null);
        $dto->doctorName = self::nullableString($row['doctor_name'] ?? null);
        $dto->specialization = self::nullableString($row['specialization'] ?? null);

        $dto->appointmentId = self::nullableInt($row['appointment_id'] ?? null);
        $dto->appointmentCode = self::nullableString($row['appointment_code'] ?? null);
        $dto->appointmentDate = self::nullableString($row['appointment_date'] ?? null);
        $time = $row['appointment_time'] ?? null;
        $dto->appointmentTime = is_scalar($time) && (string) $time !== '' ? substr((string) $time, 0, 5) : null;
        $dto->appointmentStatus = self::nullableString($row['appointment_status'] ?? null);
        $dto->consultationId = self::nullableInt($row['consultation_id'] ?? null);
        $dto->consultationCode = self::nullableString($row['consultation_code'] ?? null);

        $dto->rcmStatus = (string) ($row['rcm_status'] ?? self::STATUS_PENDING);
        $flag = $row['can_finalize'] ?? false;
        $dto->canFinalize = $flag === true || $flag === 't' || $flag === 'true' || $flag === 1 || $flag === '1';
        $dto->blockReason = self::nullableString($row['block_reason'] ?? null);
        $dto->rcmId = self::nullableInt($row['rcm_id'] ?? null);
        $dto->readySince = self::nullableString($row['ready_since'] ?? null);
        $dto->finalizedAt = self::nullableString($row['finalized_at'] ?? null);
        $dto->finalizedBy = self::nullableString($row['finalized_by'] ?? null);
        $dto->remarks = self::nullableString($row['remarks'] ?? null);

        return $dto;
    }

    public function isPending(): bool
    {
        return $this->rcmStatus === self::STATUS_PENDING;
    }

    public function isFinalized(): bool
    {
        return $this->rcmStatus === self::STATUS_FINALIZED;
    }

    public function isAwaitingBilling(): bool
    {
        return $this->rcmStatus === self::STATUS_AWAITING_BILLING;
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

    /** numeric as PDO returns it ("500" or "500.00") -> always "500.00". */
    private static function money(mixed $value): string
    {
        return is_scalar($value) && is_numeric((string) $value) ? number_format((float) $value, 2, '.', '') : '0.00';
    }
}