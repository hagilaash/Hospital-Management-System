<?php

namespace App\Tests\DTO;

use App\DTO\PatientDTO;
use App\DTO\PatientListDTO;
use PHPUnit\Framework\TestCase;

/**
 * The row-to-DTO mapping is where driver type differences (int vs string,
 * bool vs 't'/'f') would show up, so it is tested directly.
 */
class PatientDTOTest extends TestCase
{
    public function testFromRowMapsAFullDetailRow(): void
    {
        $dto = PatientDTO::fromRow([
            'patient_id' => 12,
            'patient_code' => 'PAT-2026-000012',
            'first_name' => 'Anita',
            'last_name' => 'Raman',
            'gender' => 'Female',
            'date_of_birth' => '1990-04-12',
            'phone' => '9841012345',
            'email' => 'anita.raman@example.com',
            'address' => '12 Gandhi Road, Chennai',
            'blood_group' => 'B+',
            'emergency_contact_name' => 'Karthik Raman',
            'emergency_contact_phone' => '9841098765',
            'is_active' => true,
            'created_at' => '2026-10-14 10:15:30.123456+05:30',
            'updated_at' => '2026-10-14 10:15:30.123456+05:30',
            'created_by' => 'admin',
            'updated_by' => 'admin',
        ]);

        $this->assertSame(12, $dto->patientId);
        $this->assertSame('PAT-2026-000012', $dto->patientCode);
        $this->assertSame('Anita', $dto->firstName);
        $this->assertSame('1990-04-12', $dto->dateOfBirth);
        $this->assertSame('B+', $dto->bloodGroup);
        $this->assertSame('9841098765', $dto->emergencyContactPhone);
        $this->assertTrue($dto->isActive);
        $this->assertSame('admin', $dto->createdBy);
        $this->assertSame('Anita Raman', $dto->getFullName());
    }

    public function testFromRowCastsStringTypedValues(): void
    {
        $dto = PatientDTO::fromRow(['patient_id' => '12', 'is_active' => 't']);

        $this->assertSame(12, $dto->patientId);
        $this->assertTrue($dto->isActive);
    }

    public function testFromRowReadsFalseInEveryForm(): void
    {
        $this->assertFalse(PatientDTO::fromRow(['is_active' => false])->isActive);
        $this->assertFalse(PatientDTO::fromRow(['is_active' => 'f'])->isActive);
        $this->assertFalse(PatientDTO::fromRow(['is_active' => '0'])->isActive);
        $this->assertTrue(PatientDTO::fromRow(['is_active' => 'true'])->isActive);
    }

    public function testFromRowLeavesUnselectedListColumnsNull(): void
    {
        // The list function does not return email, address, emergency contact or audit columns.
        $dto = PatientDTO::fromRow([
            'patient_id' => 3,
            'patient_code' => 'PAT-2026-000003',
            'first_name' => 'Meena',
            'last_name' => 'Raman',
            'gender' => 'Female',
            'date_of_birth' => '1992-02-02',
            'phone' => '9841012345',
            'blood_group' => null,
            'is_active' => true,
            'created_at' => '2026-10-14 10:00:00+05:30',
        ]);

        $this->assertNull($dto->email);
        $this->assertNull($dto->address);
        $this->assertNull($dto->bloodGroup);
        $this->assertNull($dto->emergencyContactName);
        $this->assertNull($dto->updatedBy);
    }

    public function testFromInputReadsOnlyTheEditableFields(): void
    {
        $dto = PatientDTO::fromInput([
            'first_name' => 'Anita',
            'last_name' => 'Raman',
            'gender' => 'Female',
            'date_of_birth' => '1990-04-12',
            'phone' => '9841012345',
            'email' => 'a@example.com',
            // A crafted request tries to set fields the form does not offer:
            'patient_id' => '99',
            'patient_code' => 'PAT-1999-000001',
            'is_active' => '0',
            'created_by' => 'someone-else',
        ]);

        $this->assertSame('Anita', $dto->firstName);
        $this->assertSame('a@example.com', $dto->email);
        $this->assertNull($dto->patientId);
        $this->assertNull($dto->patientCode);
        $this->assertTrue($dto->isActive);
        $this->assertNull($dto->createdBy);
    }

    public function testFromInputIgnoresArrayValues(): void
    {
        $dto = PatientDTO::fromInput([
            'first_name' => ['x'],
            'last_name' => 'Raman',
            'email' => ['a@b.c'],
        ]);

        $this->assertSame('', $dto->firstName);
        $this->assertSame('Raman', $dto->lastName);
        $this->assertNull($dto->email);
    }

    public function testFromInputDefaultsMissingKeys(): void
    {
        $dto = PatientDTO::fromInput([]);

        $this->assertSame('', $dto->firstName);
        $this->assertSame('', $dto->phone);
        $this->assertNull($dto->email);
        $this->assertNull($dto->bloodGroup);
    }

    public function testPatientListDtoPagerHelpers(): void
    {
        $middle = new PatientListDTO(array_fill(0, 10, new PatientDTO()), 45, 3, 10, 5, 'raman', 'all');

        $this->assertTrue($middle->hasPreviousPage());
        $this->assertTrue($middle->hasNextPage());
        $this->assertSame(21, $middle->getFirstItemNumber());
        $this->assertSame(30, $middle->getLastItemNumber());

        $last = new PatientListDTO(array_fill(0, 5, new PatientDTO()), 45, 5, 10, 5, null, 'active');

        $this->assertFalse($last->hasNextPage());
        $this->assertSame(41, $last->getFirstItemNumber());
        $this->assertSame(45, $last->getLastItemNumber());
    }
}