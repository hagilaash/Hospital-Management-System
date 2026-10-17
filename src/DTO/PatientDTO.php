<?php

namespace App\DTO;

/**
 * Carries one patient between the layers.
 *
 * It is used in both directions:
 *  - fromInput(): the controller fills it from the submitted form;
 *  - fromRow():   the repository fills it from a database row.
 *
 * It holds data only. Trimming and validation belong to PatientService.
 * Dates are kept as 'Y-m-d' strings so they can go straight into an
 * <input type="date">. List rows contain fewer columns than a detail row;
 * the columns that were not selected stay null.
 */
class PatientDTO
{
    public ?int $patientId = null;
    public ?string $patientCode = null;

    public string $firstName = '';
    public string $lastName = '';
    public string $gender = '';
    public string $dateOfBirth = '';
    public string $phone = '';
    public ?string $email = null;
    public ?string $address = null;
    public ?string $bloodGroup = null;
    public ?string $emergencyContactName = null;
    public ?string $emergencyContactPhone = null;

    public bool $isActive = true;

    public ?string $createdAt = null;
    public ?string $updatedAt = null;
    public ?string $createdBy = null;
    public ?string $updatedBy = null;

    /**
     * Builds a DTO from a database row (snake_case column names).
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $dto = new self();

        $dto->patientId = isset($row['patient_id']) ? (int) $row['patient_id'] : null;
        $dto->patientCode = self::nullableString($row['patient_code'] ?? null);

        $dto->firstName = (string) ($row['first_name'] ?? '');
        $dto->lastName = (string) ($row['last_name'] ?? '');
        $dto->gender = (string) ($row['gender'] ?? '');
        $dto->dateOfBirth = (string) ($row['date_of_birth'] ?? '');
        $dto->phone = (string) ($row['phone'] ?? '');
        $dto->email = self::nullableString($row['email'] ?? null);
        $dto->address = self::nullableString($row['address'] ?? null);
        $dto->bloodGroup = self::nullableString($row['blood_group'] ?? null);
        $dto->emergencyContactName = self::nullableString($row['emergency_contact_name'] ?? null);
        $dto->emergencyContactPhone = self::nullableString($row['emergency_contact_phone'] ?? null);

        $dto->isActive = self::toBool($row['is_active'] ?? true);

        $dto->createdAt = self::nullableString($row['created_at'] ?? null);
        $dto->updatedAt = self::nullableString($row['updated_at'] ?? null);
        $dto->createdBy = self::nullableString($row['created_by'] ?? null);
        $dto->updatedBy = self::nullableString($row['updated_by'] ?? null);

        return $dto;
    }

    /**
     * Builds a DTO from submitted form data. Only the editable fields are read,
     * so a crafted request cannot set patientId, isActive or audit fields.
     * Non-scalar values (for example first_name[]=x) are ignored.
     *
     * @param array<string, mixed> $input
     */
    public static function fromInput(array $input): self
    {
        $dto = new self();

        $dto->firstName = self::nullableString($input['first_name'] ?? null) ?? '';
        $dto->lastName = self::nullableString($input['last_name'] ?? null) ?? '';
        $dto->gender = self::nullableString($input['gender'] ?? null) ?? '';
        $dto->dateOfBirth = self::nullableString($input['date_of_birth'] ?? null) ?? '';
        $dto->phone = self::nullableString($input['phone'] ?? null) ?? '';
        $dto->email = self::nullableString($input['email'] ?? null);
        $dto->address = self::nullableString($input['address'] ?? null);
        $dto->bloodGroup = self::nullableString($input['blood_group'] ?? null);
        $dto->emergencyContactName = self::nullableString($input['emergency_contact_name'] ?? null);
        $dto->emergencyContactPhone = self::nullableString($input['emergency_contact_phone'] ?? null);

        return $dto;
    }

    /** Full name for display, e.g. in lists and page titles. */
    public function getFullName(): string
    {
        return trim($this->firstName.' '.$this->lastName);
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['t', 'true', '1', 'y', 'yes', 'on'], true);
    }
}
