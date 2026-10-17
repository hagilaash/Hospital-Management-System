<?php

namespace App\Tests\Service;

use App\DTO\PatientDTO;
use App\Repository\PatientRepository;
use App\Service\PatientService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PatientService. The repository is mocked, so nothing here
 * touches PostgreSQL.
 */
class PatientServiceTest extends TestCase
{
    private PatientRepository&MockObject $repository;
    private PatientService $service;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(PatientRepository::class);
        $this->service = new PatientService($this->repository);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function validPatient(): PatientDTO
    {
        $patient = new PatientDTO();
        $patient->firstName = 'Anita';
        $patient->lastName = 'Raman';
        $patient->gender = 'Female';
        $patient->dateOfBirth = '1990-04-12';
        $patient->phone = '9841012345';

        return $patient;
    }

    private function registerSuccess(): array
    {
        return [
            'success' => true,
            'message' => 'Patient registered successfully',
            'patient_id' => 7,
            'patient_code' => 'PAT-2026-000007',
            'duplicate_warning' => false,
        ];
    }

    private function duplicateWarning(): array
    {
        return [
            'success' => false,
            'message' => 'Possible duplicate patient found',
            'patient_id' => null,
            'patient_code' => null,
            'duplicate_warning' => true,
        ];
    }

    // ------------------------------------------------------------------
    // registerPatient: normalisation
    // ------------------------------------------------------------------

    public function testRegisterPassesNormalisedCopyToRepository(): void
    {
        $input = $this->validPatient();
        $input->firstName = '  Anita  ';
        $input->lastName = " Raman\t";
        $input->phone = ' (984) 101-2345 ';
        $input->email = '  Anita.Raman@Example.COM ';
        $input->address = '  12 Gandhi   Road ';
        $input->bloodGroup = ' b+ ';
        $input->emergencyContactName = ' Karthik   Raman ';
        $input->emergencyContactPhone = '98410 98765';

        $captured = null;
        $this->repository->expects($this->once())
            ->method('registerPatient')
            ->with($this->isInstanceOf(PatientDTO::class), 'admin', false)
            ->willReturnCallback(function (PatientDTO $patient) use (&$captured): array {
                $captured = $patient;

                return $this->registerSuccess();
            });

        $result = $this->service->registerPatient($input, ' admin ');

        $this->assertInstanceOf(PatientDTO::class, $captured);
        $this->assertSame('Anita', $captured->firstName);
        $this->assertSame('Raman', $captured->lastName);
        $this->assertSame('9841012345', $captured->phone);
        $this->assertSame('anita.raman@example.com', $captured->email);
        $this->assertSame('12 Gandhi Road', $captured->address);
        $this->assertSame('B+', $captured->bloodGroup);
        $this->assertSame('Karthik Raman', $captured->emergencyContactName);
        $this->assertSame('9841098765', $captured->emergencyContactPhone);

        // What the user typed is left untouched so the form can be redisplayed.
        $this->assertSame('  Anita  ', $input->firstName);
        $this->assertSame(' (984) 101-2345 ', $input->phone);

        $this->assertTrue($result['success']);
        $this->assertSame(7, $result['patient_id']);
        $this->assertSame('PAT-2026-000007', $result['patient_code']);
        $this->assertFalse($result['duplicate_warning']);
        $this->assertSame([], $result['errors']);
        $this->assertSame([], $result['duplicates']);
    }

    public function testRegisterTurnsEmptyOptionalFieldsIntoNull(): void
    {
        $input = $this->validPatient();
        $input->email = '   ';
        $input->address = '';
        $input->bloodGroup = ' ';
        $input->emergencyContactName = null;
        $input->emergencyContactPhone = '  ';

        $captured = null;
        $this->repository->expects($this->once())
            ->method('registerPatient')
            ->willReturnCallback(function (PatientDTO $patient) use (&$captured): array {
                $captured = $patient;

                return $this->registerSuccess();
            });

        $result = $this->service->registerPatient($input, 'admin');

        $this->assertTrue($result['success']);
        $this->assertNull($captured->email);
        $this->assertNull($captured->address);
        $this->assertNull($captured->bloodGroup);
        $this->assertNull($captured->emergencyContactName);
        $this->assertNull($captured->emergencyContactPhone);
    }

    public function testRegisterAcceptsUnicodeNamesAndBoundaryDates(): void
    {
        $this->repository->expects($this->exactly(4))
            ->method('registerPatient')
            ->willReturn($this->registerSuccess());

        $cases = [
            ['firstName' => 'அனிதா', 'dateOfBirth' => '1990-04-12'],          // Tamil name
            ['firstName' => "D'Souza", 'dateOfBirth' => '1900-01-01'],        // earliest allowed date
            ['firstName' => 'Mary-Ann', 'dateOfBirth' => (new \DateTimeImmutable('today'))->format('Y-m-d')], // born today
            ['firstName' => 'A. R.', 'dateOfBirth' => '2000-02-29'],          // leap day
        ];

        foreach ($cases as $case) {
            $input = $this->validPatient();
            $input->firstName = $case['firstName'];
            $input->dateOfBirth = $case['dateOfBirth'];

            $result = $this->service->registerPatient($input, 'admin');

            $this->assertTrue($result['success'], 'Rejected valid input: '.json_encode($case, JSON_UNESCAPED_UNICODE));
        }
    }

    // ------------------------------------------------------------------
    // registerPatient: validation
    // ------------------------------------------------------------------

    /**
     * @param array<string, string|null> $overrides
     */
    #[DataProvider('invalidRegistrationProvider')]
    public function testRegisterRejectsInvalidInputWithoutCallingRepository(array $overrides, string $expectedField): void
    {
        $input = $this->validPatient();
        foreach ($overrides as $property => $value) {
            $input->$property = $value;
        }

        $this->repository->expects($this->never())->method('registerPatient');

        $result = $this->service->registerPatient($input, 'admin');

        $this->assertFalse($result['success']);
        $this->assertSame('Please correct the errors below', $result['message']);
        $this->assertArrayHasKey($expectedField, $result['errors']);
        $this->assertNotSame('', $result['errors'][$expectedField]);
        $this->assertNull($result['patient_id']);
        $this->assertFalse($result['duplicate_warning']);
    }

    public static function invalidRegistrationProvider(): array
    {
        $tomorrow = (new \DateTimeImmutable('tomorrow'))->format('Y-m-d');

        return [
            'first name blank' => [['firstName' => '   '], 'firstName'],
            'first name has digits' => [['firstName' => 'Anita2'], 'firstName'],
            'first name too long' => [['firstName' => str_repeat('a', 101)], 'firstName'],
            'last name blank' => [['lastName' => ''], 'lastName'],
            'last name has symbols' => [['lastName' => 'Raman<script>'], 'lastName'],
            'gender not selected' => [['gender' => ''], 'gender'],
            'gender not allowed' => [['gender' => 'Robot'], 'gender'],
            'gender wrong case' => [['gender' => 'female'], 'gender'],
            'dob blank' => [['dateOfBirth' => ''], 'dateOfBirth'],
            'dob not a date' => [['dateOfBirth' => 'not-a-date'], 'dateOfBirth'],
            'dob wrong format' => [['dateOfBirth' => '12/04/1990'], 'dateOfBirth'],
            'dob impossible date' => [['dateOfBirth' => '2026-02-31'], 'dateOfBirth'],
            'dob not a leap day' => [['dateOfBirth' => '2023-02-29'], 'dateOfBirth'],
            'dob before 1900' => [['dateOfBirth' => '1899-12-31'], 'dateOfBirth'],
            'dob tomorrow' => [['dateOfBirth' => $tomorrow], 'dateOfBirth'],
            'dob far future' => [['dateOfBirth' => '2999-01-01'], 'dateOfBirth'],
            'phone blank' => [['phone' => ''], 'phone'],
            'phone too short' => [['phone' => '12345'], 'phone'],
            'phone too long' => [['phone' => '1234567890123456'], 'phone'],
            'phone has letters' => [['phone' => 'abc1234567'], 'phone'],
            'phone only a plus sign' => [['phone' => '+'], 'phone'],
            'phone plus in the middle' => [['phone' => '98410+12345'], 'phone'],
            'email not an address' => [['email' => 'not-an-email'], 'email'],
            'email without a domain dot' => [['email' => 'a@b'], 'email'],
            'email too long' => [['email' => str_repeat('b', 150).'@example.com'], 'email'],
            'blood group not valid' => [['bloodGroup' => 'Z+'], 'bloodGroup'],
            'address too long' => [['address' => str_repeat('x', 256)], 'address'],
            'emergency name has digits' => [['emergencyContactName' => 'R2D2'], 'emergencyContactName'],
            'emergency phone too short' => [['emergencyContactPhone' => '123'], 'emergencyContactPhone'],
            'invalid UTF-8 in first name' => [['firstName' => "\xB1\x31"], 'firstName'],
        ];
    }

    public function testRegisterReportsEveryInvalidFieldAtOnce(): void
    {
        $input = new PatientDTO();

        $this->repository->expects($this->never())->method('registerPatient');

        $result = $this->service->registerPatient($input, 'admin');

        $this->assertFalse($result['success']);
        foreach (['firstName', 'lastName', 'gender', 'dateOfBirth', 'phone'] as $field) {
            $this->assertArrayHasKey($field, $result['errors']);
        }
        $this->assertArrayNotHasKey('email', $result['errors']);
    }

    public function testRegisterRequiresASignedInUser(): void
    {
        $this->repository->expects($this->never())->method('registerPatient');

        $result = $this->service->registerPatient($this->validPatient(), '   ');

        $this->assertFalse($result['success']);
        $this->assertSame('You must be signed in to perform this action', $result['message']);
    }

    // ------------------------------------------------------------------
    // registerPatient: duplicate flow
    // ------------------------------------------------------------------

    public function testRegisterReturnsDuplicatesWhenDatabaseWarns(): void
    {
        $this->repository->expects($this->once())
            ->method('registerPatient')
            ->with($this->isInstanceOf(PatientDTO::class), 'admin', false)
            ->willReturn($this->duplicateWarning());

        $match = [
            'patient_id' => 1,
            'patient_code' => 'PAT-2026-000001',
            'first_name' => 'Anita',
            'last_name' => 'Raman',
            'date_of_birth' => '1990-04-12',
            'phone' => '9841012345',
            'is_active' => true,
            'match_reason' => 'Same name, date of birth and phone',
        ];
        $this->repository->expects($this->once())
            ->method('findPossibleDuplicates')
            ->with($this->isInstanceOf(PatientDTO::class), null)
            ->willReturn([$match]);

        $result = $this->service->registerPatient($this->validPatient(), 'admin');

        $this->assertFalse($result['success']);
        $this->assertTrue($result['duplicate_warning']);
        $this->assertSame('Possible duplicate patient found', $result['message']);
        $this->assertNull($result['patient_id']);
        $this->assertSame([$match], $result['duplicates']);
    }

    public function testRegisterWithConfirmationPassesTheFlagAndSkipsTheLookup(): void
    {
        $this->repository->expects($this->once())
            ->method('registerPatient')
            ->with($this->isInstanceOf(PatientDTO::class), 'admin', true)
            ->willReturn($this->registerSuccess());
        $this->repository->expects($this->never())->method('findPossibleDuplicates');

        $result = $this->service->registerPatient($this->validPatient(), 'admin', true);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['duplicate_warning']);
        $this->assertSame([], $result['duplicates']);
    }

    public function testRegisterPassesDatabaseFailureThrough(): void
    {
        $this->repository->method('registerPatient')->willReturn([
            'success' => false,
            'message' => 'Date of birth cannot be in the future',
            'patient_id' => null,
            'patient_code' => null,
            'duplicate_warning' => false,
        ]);
        $this->repository->expects($this->never())->method('findPossibleDuplicates');

        $result = $this->service->registerPatient($this->validPatient(), 'admin');

        $this->assertFalse($result['success']);
        $this->assertSame('Date of birth cannot be in the future', $result['message']);
        $this->assertFalse($result['duplicate_warning']);
    }

    public function testResultToleratesTextBooleansFromTheDriver(): void
    {
        $this->repository->method('registerPatient')->willReturn([
            'success' => 't',
            'message' => 'Patient registered successfully',
            'patient_id' => '12',
            'patient_code' => 'PAT-2026-000012',
            'duplicate_warning' => 'f',
        ]);

        $result = $this->service->registerPatient($this->validPatient(), 'admin');

        $this->assertTrue($result['success']);
        $this->assertSame(12, $result['patient_id']);
        $this->assertFalse($result['duplicate_warning']);
    }

    // ------------------------------------------------------------------
    // updatePatient
    // ------------------------------------------------------------------

    public function testUpdatePassesIdActorAndFlagToRepository(): void
    {
        $this->repository->expects($this->once())
            ->method('updatePatient')
            ->with(5, $this->isInstanceOf(PatientDTO::class), 'admin', true)
            ->willReturn([
                'success' => true,
                'message' => 'Patient updated successfully',
                'patient_id' => 5,
                'patient_code' => 'PAT-2026-000005',
                'duplicate_warning' => false,
            ]);

        $result = $this->service->updatePatient(5, $this->validPatient(), 'admin', true);

        $this->assertTrue($result['success']);
        $this->assertSame(5, $result['patient_id']);
        $this->assertSame('Patient updated successfully', $result['message']);
    }

    public function testUpdateExcludesTheEditedPatientWhenLookingUpDuplicates(): void
    {
        $this->repository->method('updatePatient')->willReturn($this->duplicateWarning());
        $this->repository->expects($this->once())
            ->method('findPossibleDuplicates')
            ->with($this->isInstanceOf(PatientDTO::class), 5)
            ->willReturn([['patient_id' => 9]]);

        $result = $this->service->updatePatient(5, $this->validPatient(), 'admin');

        $this->assertTrue($result['duplicate_warning']);
        $this->assertSame([['patient_id' => 9]], $result['duplicates']);
    }

    public function testUpdateRejectsInvalidInputWithoutCallingRepository(): void
    {
        $input = $this->validPatient();
        $input->phone = '12';

        $this->repository->expects($this->never())->method('updatePatient');

        $result = $this->service->updatePatient(5, $input, 'admin');

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('phone', $result['errors']);
    }

    public function testUpdateWithInvalidIdIsNotFound(): void
    {
        $this->repository->expects($this->never())->method('updatePatient');

        $result = $this->service->updatePatient(0, $this->validPatient(), 'admin');

        $this->assertFalse($result['success']);
        $this->assertSame('Patient not found', $result['message']);
    }

    public function testUpdatePassesInactivePatientMessageThrough(): void
    {
        $message = 'Inactive patients must be reactivated before they can be edited';
        $this->repository->method('updatePatient')->willReturn([
            'success' => false,
            'message' => $message,
            'patient_id' => 5,
            'patient_code' => 'PAT-2026-000005',
            'duplicate_warning' => false,
        ]);

        $result = $this->service->updatePatient(5, $this->validPatient(), 'admin');

        $this->assertFalse($result['success']);
        $this->assertSame($message, $result['message']);
        $this->assertSame(5, $result['patient_id']);
    }

    public function testUpdateRequiresASignedInUser(): void
    {
        $this->repository->expects($this->never())->method('updatePatient');

        $result = $this->service->updatePatient(5, $this->validPatient(), '');

        $this->assertFalse($result['success']);
    }

    // ------------------------------------------------------------------
    // changePatientStatus
    // ------------------------------------------------------------------

    public function testChangeStatusDeactivates(): void
    {
        $this->repository->expects($this->once())
            ->method('setPatientStatus')
            ->with(5, false, 'admin')
            ->willReturn(['success' => true, 'message' => 'Patient deactivated successfully']);

        $result = $this->service->changePatientStatus(5, false, ' admin ');

        $this->assertTrue($result['success']);
        $this->assertSame('Patient deactivated successfully', $result['message']);
        $this->assertSame(5, $result['patient_id']);
        $this->assertSame([], $result['errors']);
    }

    public function testChangeStatusPassesDatabaseFailureThrough(): void
    {
        $this->repository->method('setPatientStatus')
            ->willReturn(['success' => false, 'message' => 'Patient is already active']);

        $result = $this->service->changePatientStatus(5, true, 'admin');

        $this->assertFalse($result['success']);
        $this->assertSame('Patient is already active', $result['message']);
    }

    public function testChangeStatusRejectsInvalidIdAndMissingUser(): void
    {
        $this->repository->expects($this->never())->method('setPatientStatus');

        $badId = $this->service->changePatientStatus(0, true, 'admin');
        $noUser = $this->service->changePatientStatus(5, true, '  ');

        $this->assertFalse($badId['success']);
        $this->assertSame('Patient not found', $badId['message']);
        $this->assertFalse($noUser['success']);
    }

    // ------------------------------------------------------------------
    // getPatient
    // ------------------------------------------------------------------

    public function testGetPatientReturnsNullForInvalidIdsWithoutCallingRepository(): void
    {
        $this->repository->expects($this->never())->method('getPatientById');

        $this->assertNull($this->service->getPatient(0));
        $this->assertNull($this->service->getPatient(-3));
    }

    public function testGetPatientReturnsTheRepositoryResult(): void
    {
        $patient = $this->validPatient();
        $this->repository->expects($this->once())
            ->method('getPatientById')
            ->with(7)
            ->willReturn($patient);

        $this->assertSame($patient, $this->service->getPatient(7));
    }

    public function testGetPatientReturnsNullWhenNotFound(): void
    {
        $this->repository->method('getPatientById')->willReturn(null);

        $this->assertNull($this->service->getPatient(999));
    }

    // ------------------------------------------------------------------
    // getPatientsList: defaults, filters, pagination
    // ------------------------------------------------------------------

    public function testListDefaultsToActivePatientsFirstPageOfTen(): void
    {
        $rows = array_fill(0, 10, new PatientDTO());

        $this->repository->expects($this->once())
            ->method('countPatients')
            ->with(null, 'active')
            ->willReturn(25);
        $this->repository->expects($this->once())
            ->method('getPatientsList')
            ->with(null, 'active', 10, 0)
            ->willReturn($rows);

        $list = $this->service->getPatientsList();

        $this->assertSame(25, $list->total);
        $this->assertSame(1, $list->page);
        $this->assertSame(10, $list->perPage);
        $this->assertSame(3, $list->totalPages);
        $this->assertSame('active', $list->status);
        $this->assertNull($list->search);
        $this->assertCount(10, $list->patients);
        $this->assertFalse($list->hasPreviousPage());
        $this->assertTrue($list->hasNextPage());
        $this->assertSame(1, $list->getFirstItemNumber());
        $this->assertSame(10, $list->getLastItemNumber());
    }

    #[DataProvider('statusProvider')]
    public function testListNormalisesTheStatusFilter(?string $given, string $expected): void
    {
        $this->repository->expects($this->once())
            ->method('countPatients')
            ->with(null, $expected)
            ->willReturn(0);

        $list = $this->service->getPatientsList(null, $given);

        $this->assertSame($expected, $list->status);
    }

    public static function statusProvider(): array
    {
        return [
            'all' => ['all', 'all'],
            'all upper case' => [' ALL ', 'all'],
            'inactive mixed case' => ['Inactive', 'inactive'],
            'active' => ['active', 'active'],
            'null' => [null, 'active'],
            'empty' => ['', 'active'],
            'unknown value' => ['deleted', 'active'],
            'injection attempt' => ["active' OR 1=1 --", 'active'],
        ];
    }

    public function testListMovesAnOutOfRangePageToTheLastPage(): void
    {
        $this->repository->method('countPatients')->willReturn(25);
        $this->repository->expects($this->once())
            ->method('getPatientsList')
            ->with(null, 'active', 10, 20)
            ->willReturn(array_fill(0, 5, new PatientDTO()));

        $list = $this->service->getPatientsList(null, null, 99);

        $this->assertSame(3, $list->page);
        $this->assertTrue($list->hasPreviousPage());
        $this->assertFalse($list->hasNextPage());
        $this->assertSame(21, $list->getFirstItemNumber());
        $this->assertSame(25, $list->getLastItemNumber());
    }

    public function testListMovesAPageBelowOneToTheFirstPage(): void
    {
        $this->repository->method('countPatients')->willReturn(25);
        $this->repository->expects($this->exactly(2))
            ->method('getPatientsList')
            ->with(null, 'active', 10, 0)
            ->willReturn(array_fill(0, 10, new PatientDTO()));

        $this->assertSame(1, $this->service->getPatientsList(null, null, 0)->page);
        $this->assertSame(1, $this->service->getPatientsList(null, null, -5)->page);
    }

    public function testListHasExactlyTheNeededPagesWhenTheTotalIsAMultipleOfTen(): void
    {
        $this->repository->method('countPatients')->willReturn(20);
        $this->repository->method('getPatientsList')->willReturn(array_fill(0, 10, new PatientDTO()));

        $list = $this->service->getPatientsList(null, null, 5);

        $this->assertSame(2, $list->totalPages);
        $this->assertSame(2, $list->page);
    }

    public function testListSkipsTheQueryWhenThereAreNoPatients(): void
    {
        $this->repository->method('countPatients')->willReturn(0);
        $this->repository->expects($this->never())->method('getPatientsList');

        $list = $this->service->getPatientsList('nobody');

        $this->assertSame(0, $list->total);
        $this->assertSame(1, $list->page);
        $this->assertSame(1, $list->totalPages);
        $this->assertSame([], $list->patients);
        $this->assertSame(0, $list->getFirstItemNumber());
        $this->assertSame(0, $list->getLastItemNumber());
        $this->assertFalse($list->hasNextPage());
    }

    #[DataProvider('searchProvider')]
    public function testListNormalisesTheSearchTerm(?string $given, ?string $expected): void
    {
        $this->repository->expects($this->once())
            ->method('countPatients')
            ->with($expected, 'active')
            ->willReturn(0);

        $list = $this->service->getPatientsList($given);

        $this->assertSame($expected, $list->search);
    }

    public static function searchProvider(): array
    {
        return [
            'null' => [null, null],
            'blank' => ['   ', null],
            'trimmed and collapsed' => ['  anita   raman  ', 'anita raman'],
            'patient id kept' => ['PAT-2026-000001', 'PAT-2026-000001'],
            'wildcards kept as text' => ['50%_off', '50%_off'],
            'truncated to 100' => [str_repeat('a', 150), str_repeat('a', 100)],
            'invalid UTF-8 dropped' => ["\xB1\x31", null],
        ];
    }
}
