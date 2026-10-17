<?php

namespace App\Service;

use App\DTO\PatientDTO;
use App\DTO\PatientListDTO;
use App\Repository\PatientRepository;

/**
 * Business logic for patients.
 *
 * - Normalises and validates input BEFORE it reaches the database. The rules
 *   are the same as, or stricter than, the CHECK constraints and the
 *   ecs_validate_patient_input() function, so the database is a backstop.
 * - Validates the list filters (the SQL functions return no rows for an
 *   unknown status, so user input must never be passed through unchecked).
 * - Works out pagination.
 * - Orchestrates the duplicate-warning flow.
 *
 * It never touches the session or the database directly. The acting username
 * is passed in by the controller.
 *
 * Write operations return an array shaped like the database function results
 * (LoginService style), with two additions:
 *
 *   [
 *     'success'           => bool,
 *     'message'           => string,                 // safe to show to the user
 *     'errors'            => array<string, string>,  // fieldName => message
 *     'patient_id'        => ?int,
 *     'patient_code'      => ?string,
 *     'duplicate_warning' => bool,                   // true: nothing was saved, ask the user to confirm
 *     'duplicates'        => list<array>,            // rows of the possible duplicates
 *   ]
 *
 * Field names in 'errors' are the PatientDTO property names.
 */
class PatientService
{
    public const GENDERS = ['Male', 'Female', 'Other'];
    public const BLOOD_GROUPS = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

    public const STATUS_ALL = 'all';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';
    public const STATUS_FILTERS = [self::STATUS_ALL, self::STATUS_ACTIVE, self::STATUS_INACTIVE];
    public const DEFAULT_STATUS = self::STATUS_ACTIVE;

    public const PER_PAGE = 10;
    public const MAX_SEARCH_LENGTH = 100;

    private const MAX_NAME_LENGTH = 100;
    private const MAX_EMAIL_LENGTH = 150;
    private const MAX_ADDRESS_LENGTH = 255;
    private const MIN_DATE_OF_BIRTH = '1900-01-01';

    // Letters (any script), combining marks (e.g. Tamil vowel signs), space,
    // apostrophes, full stop and hyphen. Must start with a letter.
    private const NAME_PATTERN = '/^\p{L}[\p{L}\p{M} \'’.\-]*$/u';

    // Same as the CHECK constraints in sql/patients_module.sql.
    private const PHONE_PATTERN = '/^\+?[0-9]{7,15}$/';
    private const EMAIL_PATTERN = '/^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/';

    private const MESSAGE_VALIDATION = 'Please correct the errors below';
    private const MESSAGE_NOT_FOUND = 'Patient not found';
    private const MESSAGE_NOT_SIGNED_IN = 'You must be signed in to perform this action';

    public function __construct(
        private PatientRepository $patientRepository,
    ) {
    }

    /**
     * Registers a patient. If a possible duplicate exists and
     * $confirmDuplicate is false, nothing is saved and 'duplicate_warning' is
     * true; call again with $confirmDuplicate = true to register anyway.
     */
    public function registerPatient(PatientDTO $input, string $performedBy, bool $confirmDuplicate = false): array
    {
        $performedBy = trim($performedBy);
        if ('' === $performedBy) {
            return $this->failure(self::MESSAGE_NOT_SIGNED_IN);
        }

        $patient = $this->normalize($input);
        $errors = $this->validate($patient);
        if ([] !== $errors) {
            return $this->failure(self::MESSAGE_VALIDATION, $errors);
        }

        $dbResult = $this->patientRepository->registerPatient($patient, $performedBy, $confirmDuplicate);

        return $this->buildResult($dbResult, $patient, null);
    }

    /**
     * Updates an existing (active) patient. Same duplicate flow as register;
     * the patient being edited is never reported as its own duplicate.
     */
    public function updatePatient(
        int $patientId,
        PatientDTO $input,
        string $performedBy,
        bool $confirmDuplicate = false,
    ): array {
        if ($patientId < 1) {
            return $this->failure(self::MESSAGE_NOT_FOUND);
        }

        $performedBy = trim($performedBy);
        if ('' === $performedBy) {
            return $this->failure(self::MESSAGE_NOT_SIGNED_IN);
        }

        $patient = $this->normalize($input);
        $errors = $this->validate($patient);
        if ([] !== $errors) {
            return $this->failure(self::MESSAGE_VALIDATION, $errors);
        }

        $dbResult = $this->patientRepository->updatePatient($patientId, $patient, $performedBy, $confirmDuplicate);

        return $this->buildResult($dbResult, $patient, $patientId);
    }

    /**
     * Soft deactivate ($isActive = false) or reactivate ($isActive = true).
     */
    public function changePatientStatus(int $patientId, bool $isActive, string $performedBy): array
    {
        if ($patientId < 1) {
            return $this->failure(self::MESSAGE_NOT_FOUND);
        }

        $performedBy = trim($performedBy);
        if ('' === $performedBy) {
            return $this->failure(self::MESSAGE_NOT_SIGNED_IN);
        }

        $dbResult = $this->patientRepository->setPatientStatus($patientId, $isActive, $performedBy);

        $result = $this->failure((string) ($dbResult['message'] ?? ''));
        $result['success'] = $this->isTrue($dbResult['success'] ?? false);
        $result['patient_id'] = $patientId;

        return $result;
    }

    /**
     * One patient, or null when the ID does not exist.
     */
    public function getPatient(int $patientId): ?PatientDTO
    {
        if ($patientId < 1) {
            return null;
        }

        return $this->patientRepository->getPatientById($patientId);
    }

    /**
     * One page of the patient list. Never throws for bad filter input:
     * an unknown status falls back to the default, a page outside the valid
     * range is moved to the nearest valid page.
     */
    public function getPatientsList(?string $search = null, ?string $status = null, int $page = 1): PatientListDTO
    {
        $search = $this->normalizeSearch($search);
        $status = $this->normalizeStatus($status);

        $total = $this->patientRepository->countPatients($search, $status);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $page), $totalPages);

        $patients = [];
        if ($total > 0) {
            $patients = $this->patientRepository->getPatientsList(
                $search,
                $status,
                self::PER_PAGE,
                ($page - 1) * self::PER_PAGE
            );
        }

        return new PatientListDTO($patients, $total, $page, self::PER_PAGE, $totalPages, $search, $status);
    }

    // ------------------------------------------------------------------
    // Normalisation
    // ------------------------------------------------------------------

    /**
     * Returns a cleaned COPY; the caller's DTO (what the user typed) is not
     * modified, so the form can be shown again exactly as submitted.
     * Mirrors what the database functions do (trim, phone strip, lowercase
     * email, uppercase blood group) so the stored value is predictable.
     */
    private function normalize(PatientDTO $input): PatientDTO
    {
        $patient = new PatientDTO();

        $patient->firstName = $this->clean($input->firstName);
        $patient->lastName = $this->clean($input->lastName);
        $patient->gender = $this->clean($input->gender);
        $patient->dateOfBirth = $this->clean($input->dateOfBirth);
        $patient->phone = $this->normalizePhone($input->phone);
        $patient->email = $this->nullIfEmpty(mb_strtolower($this->clean($input->email)));
        $patient->address = $this->nullIfEmpty($this->clean($input->address));
        $patient->bloodGroup = $this->nullIfEmpty(mb_strtoupper($this->clean($input->bloodGroup)));
        $patient->emergencyContactName = $this->nullIfEmpty($this->clean($input->emergencyContactName));
        $patient->emergencyContactPhone = $this->nullIfEmpty($this->normalizePhone($input->emergencyContactPhone));

        return $patient;
    }

    /**
     * Removes control characters, collapses any run of whitespace to a single
     * space and trims. Text that is not valid UTF-8 becomes '' (PostgreSQL
     * would reject it), which then fails the "required" or format rule.
     */
    private function clean(?string $value): string
    {
        if (null === $value || !mb_check_encoding($value, 'UTF-8')) {
            return '';
        }

        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);
        if (null === $value) {
            return '';
        }

        $value = preg_replace('/\s+/u', ' ', $value);

        return null === $value ? '' : trim($value);
    }

    /** Same characters the database strips: space, (, ) and hyphen. */
    private function normalizePhone(?string $value): string
    {
        return preg_replace('/[ ()\-]/', '', $this->clean($value)) ?? '';
    }

    private function nullIfEmpty(string $value): ?string
    {
        return '' === $value ? null : $value;
    }

    private function normalizeSearch(?string $search): ?string
    {
        if (null === $search) {
            return null;
        }

        $clean = trim(mb_substr($this->clean($search), 0, self::MAX_SEARCH_LENGTH));

        return '' === $clean ? null : $clean;
    }

    private function normalizeStatus(?string $status): string
    {
        $status = strtolower(trim((string) $status));

        return in_array($status, self::STATUS_FILTERS, true) ? $status : self::DEFAULT_STATUS;
    }

    // ------------------------------------------------------------------
    // Validation
    // ------------------------------------------------------------------

    /**
     * Expects an already normalised DTO.
     *
     * @return array<string, string> fieldName => message (empty when valid)
     */
    private function validate(PatientDTO $patient): array
    {
        $errors = [];

        foreach (['firstName' => 'First name', 'lastName' => 'Last name'] as $field => $label) {
            $value = $patient->$field;

            if ('' === $value) {
                $errors[$field] = $label.' is required';
            } elseif (mb_strlen($value) > self::MAX_NAME_LENGTH) {
                $errors[$field] = $label.' must be '.self::MAX_NAME_LENGTH.' characters or fewer';
            } elseif (!preg_match(self::NAME_PATTERN, $value)) {
                $errors[$field] = $label.' may contain only letters, spaces, apostrophes, hyphens and full stops';
            }
        }

        if (!in_array($patient->gender, self::GENDERS, true)) {
            $errors['gender'] = 'Select Male, Female or Other';
        }

        $dobError = $this->validateDateOfBirth($patient->dateOfBirth);
        if (null !== $dobError) {
            $errors['dateOfBirth'] = $dobError;
        }

        if ('' === $patient->phone) {
            $errors['phone'] = 'Phone number is required';
        } elseif (!preg_match(self::PHONE_PATTERN, $patient->phone)) {
            $errors['phone'] = 'Phone number must contain 7 to 15 digits (optional leading +)';
        }

        if (null !== $patient->email) {
            if (mb_strlen($patient->email) > self::MAX_EMAIL_LENGTH) {
                $errors['email'] = 'Email address must be '.self::MAX_EMAIL_LENGTH.' characters or fewer';
            } elseif (!preg_match(self::EMAIL_PATTERN, $patient->email)) {
                $errors['email'] = 'Email address is not valid';
            }
        }

        if (null !== $patient->address && mb_strlen($patient->address) > self::MAX_ADDRESS_LENGTH) {
            $errors['address'] = 'Address must be '.self::MAX_ADDRESS_LENGTH.' characters or fewer';
        }

        if (null !== $patient->bloodGroup && !in_array($patient->bloodGroup, self::BLOOD_GROUPS, true)) {
            $errors['bloodGroup'] = 'Blood group is not valid';
        }

        if (null !== $patient->emergencyContactName) {
            if (mb_strlen($patient->emergencyContactName) > self::MAX_NAME_LENGTH) {
                $errors['emergencyContactName'] = 'Emergency contact name must be '
                    .self::MAX_NAME_LENGTH.' characters or fewer';
            } elseif (!preg_match(self::NAME_PATTERN, $patient->emergencyContactName)) {
                $errors['emergencyContactName'] = 'Emergency contact name may contain only letters, spaces, '
                    .'apostrophes, hyphens and full stops';
            }
        }

        if (null !== $patient->emergencyContactPhone
            && !preg_match(self::PHONE_PATTERN, $patient->emergencyContactPhone)
        ) {
            $errors['emergencyContactPhone'] = 'Emergency contact phone must contain 7 to 15 digits (optional leading +)';
        }

        return $errors;
    }

    private function validateDateOfBirth(string $value): ?string
    {
        if ('' === $value) {
            return 'Date of birth is required';
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $problems = \DateTimeImmutable::getLastErrors();

        // The round-trip comparison also catches rolled-over dates such as 2026-02-31.
        if (false === $date
            || (false !== $problems && ($problems['warning_count'] > 0 || $problems['error_count'] > 0))
            || $date->format('Y-m-d') !== $value
        ) {
            return 'Enter a valid date of birth (YYYY-MM-DD)';
        }

        if ($date < new \DateTimeImmutable(self::MIN_DATE_OF_BIRTH)) {
            return 'Date of birth is not valid';
        }

        if ($date > new \DateTimeImmutable('today')) {
            return 'Date of birth cannot be in the future';
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Result building
    // ------------------------------------------------------------------

    /**
     * Turns a register/update database row into the service result and, when
     * the database reported a possible duplicate, attaches the matching
     * patients so the UI can show them.
     */
    private function buildResult(array $dbResult, PatientDTO $patient, ?int $excludePatientId): array
    {
        $duplicateWarning = $this->isTrue($dbResult['duplicate_warning'] ?? false);

        $result = $this->failure((string) ($dbResult['message'] ?? ''));
        $result['success'] = $this->isTrue($dbResult['success'] ?? false);
        $result['patient_id'] = isset($dbResult['patient_id']) ? (int) $dbResult['patient_id'] : null;
        $result['patient_code'] = isset($dbResult['patient_code']) ? (string) $dbResult['patient_code'] : null;
        $result['duplicate_warning'] = $duplicateWarning;

        if ($duplicateWarning) {
            $result['duplicates'] = $this->patientRepository->findPossibleDuplicates($patient, $excludePatientId);
        }

        return $result;
    }

    /**
     * The uniform result shape, pre-filled as a failure.
     *
     * @param array<string, string> $errors
     */
    private function failure(string $message, array $errors = []): array
    {
        return [
            'success' => false,
            'message' => $message,
            'errors' => $errors,
            'patient_id' => null,
            'patient_code' => null,
            'duplicate_warning' => false,
            'duplicates' => [],
        ];
    }

    private function isTrue(mixed $value): bool
    {
        return true === $value || 't' === $value || 'true' === $value || 1 === $value || '1' === $value;
    }
}
