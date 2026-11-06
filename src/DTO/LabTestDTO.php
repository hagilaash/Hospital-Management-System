<?php

namespace App\DTO;

/**
 * One test of the laboratory catalogue.
 *  - fromInput(): the controller fills the editable fields from the test form;
 *  - fromRow():   the repository fills it from a database row.
 * Data only: cleaning and validation belong to LabService. The reference range is kept as
 * strings exactly as PostgreSQL returns it (trailing zeros removed), never as floats.
 */
class LabTestDTO
{
    public ?int $testId = null;
    public ?string $testCode = null;
    public string $testName = '';
    public string $category = 'Hematology';
    public string $sampleType = 'Blood';
    public string $resultType = 'Numeric';
    public ?string $unit = null;
    public ?string $refLow = null;
    public ?string $refHigh = null;
    public ?string $referenceText = null;
    public bool $isActive = true;

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $dto = new self();

        $dto->testId = isset($row['test_id']) && is_numeric($row['test_id']) ? (int) $row['test_id'] : null;
        $dto->testCode = self::nullableString($row['test_code'] ?? null);
        $dto->testName = (string) ($row['test_name'] ?? '');
        $dto->category = (string) ($row['category'] ?? 'Hematology');
        $dto->sampleType = (string) ($row['sample_type'] ?? 'Blood');
        $dto->resultType = (string) ($row['result_type'] ?? 'Numeric');
        $dto->unit = self::nullableString($row['unit'] ?? null);
        $dto->refLow = self::trimNumber($row['ref_low'] ?? null);
        $dto->refHigh = self::trimNumber($row['ref_high'] ?? null);
        $dto->referenceText = self::nullableString($row['reference_text'] ?? null);
        $flag = $row['is_active'] ?? true;
        $dto->isActive = $flag === true || $flag === 't' || $flag === 'true' || $flag === 1 || $flag === '1';

        return $dto;
    }

    /**
     * Reads ONLY the editable fields (never an id or a code). The checkbox "is_active" is present
     * when ticked and absent when not.
     *
     * @param array<string, mixed> $input
     */
    public static function fromInput(array $input): self
    {
        $dto = new self();

        $dto->testName = trim((string) self::nullableString($input['test_name'] ?? null));
        $dto->category = trim((string) self::nullableString($input['category'] ?? null));
        $dto->sampleType = trim((string) self::nullableString($input['sample_type'] ?? null));
        $dto->resultType = trim((string) self::nullableString($input['result_type'] ?? null));
        $dto->unit = self::nullableString($input['unit'] ?? null);
        $dto->refLow = self::nullableString($input['ref_low'] ?? null);
        $dto->refHigh = self::nullableString($input['ref_high'] ?? null);
        $dto->referenceText = self::nullableString($input['reference_text'] ?? null);
        $dto->isActive = isset($input['is_active']) && is_scalar($input['is_active']) && (string) $input['is_active'] !== '' && (string) $input['is_active'] !== '0';

        return $dto;
    }

    public function isNumeric(): bool
    {
        return $this->resultType === 'Numeric';
    }

    /** "12 – 16 g/dL", "No abnormality", or an empty string. */
    public function rangeLabel(): string
    {
        if ($this->referenceText !== null && $this->referenceText !== '') {
            return $this->referenceText;
        }
        if ($this->refLow !== null && $this->refHigh !== null) {
            return $this->refLow . ' – ' . $this->refHigh . ($this->unit ? ' ' . $this->unit : '');
        }
        if ($this->refLow !== null) {
            return '≥ ' . $this->refLow . ($this->unit ? ' ' . $this->unit : '');
        }
        if ($this->refHigh !== null) {
            return '≤ ' . $this->refHigh . ($this->unit ? ' ' . $this->unit : '');
        }

        return '';
    }

    /** "5.2000" -> "5.2", "12.0000" -> "12", null stays null. */
    private static function trimNumber(mixed $value): ?string
    {
        if (!is_scalar($value) || !is_numeric((string) $value)) {
            return null;
        }
        $text = (string) $value;

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }
}