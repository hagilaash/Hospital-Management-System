<?php

namespace App\Tests\Service;

use App\DTO\LabTestDTO;
use App\Repository\LabRepository;
use App\Service\LabService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for LabService. The repository is mocked, so nothing here touches PostgreSQL.
 * (The rules that need locking - samples, derived order status, locked results - are proven by
 * sql/lab/03_verify.sql.).
 */
class LabServiceTest extends TestCase
{
    private LabRepository&MockObject $repository;
    private LabService $service;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(LabRepository::class);
        $this->service = new LabService($this->repository);
    }

    private function test(array $over = []): LabTestDTO
    {
        return LabTestDTO::fromInput($over + [
            'test_name' => 'Hemoglobin', 'category' => 'Hematology', 'sample_type' => 'Blood', 'result_type' => 'Numeric',
            'unit' => 'g/dL', 'ref_low' => '12', 'ref_high' => '16', 'reference_text' => '', 'is_active' => '1',
        ]);
    }

    private function ok(array $extra = []): array
    {
        return $extra + ['success' => true, 'message' => 'ok'];
    }

    // ------------------------------------------------------------------ lists

    public function testTheCatalogueWhitelistsItsFilters(): void
    {
        $this->repository->expects($this->once())->method('countTests')->with('hemo', 'Hematology', 'active')->willReturn(0);

        $list = $this->service->getTests('  hemo ', 'hematology', 'ACTIVE');

        $this->assertSame(['search' => 'hemo', 'category' => 'Hematology', 'status' => 'active'], $list->filters);
    }

    public function testUnknownCatalogueFiltersFallBackToDefaults(): void
    {
        $this->repository->expects($this->once())->method('countTests')->with(null, null, 'all')->willReturn(0);

        $list = $this->service->getTests(null, "Hematology' OR 1=1 --", 'deleted');

        $this->assertNull($list->filters['category']);
        $this->assertSame('all', $list->filters['status']);
    }

    public function testWorklistViewDefaultsToPendingAndAcceptsDone(): void
    {
        $seen = [];
        $this->repository->method('countWorklist')->willReturnCallback(function (string $view) use (&$seen): int {
            $seen[] = $view;

            return 0;
        });

        $this->assertSame('pending', $this->service->getWorklist(null, null)->filters['view']);
        $this->assertSame('done', $this->service->getWorklist('DONE', null)->filters['view']);
        $this->assertSame('pending', $this->service->getWorklist('whatever', null)->filters['view']);
        $this->assertSame(['pending', 'done', 'pending'], $seen);
    }

    public function testPagingUsesTenPerPageAndClampsThePage(): void
    {
        $this->repository->method('countWorklist')->willReturn(25);
        $this->repository->expects($this->once())->method('getWorklist')->with('pending', null, 10, 20)->willReturn([]);

        $this->assertSame(3, $this->service->getWorklist(null, null, 99)->page);
    }

    // ------------------------------------------------------------------ catalogue

    public function testATestIsCleanedAndSaved(): void
    {
        $captured = null;
        $this->repository->expects($this->once())->method('saveTest')->willReturnCallback(
            function (?int $id, LabTestDTO $t, string $by) use (&$captured): array {
                $captured = [$id, $t, $by];

                return ['success' => true, 'message' => 'Test added successfully', 'test_id' => 7, 'test_code' => 'TST-2026-000007'];
            }
        );

        $result = $this->service->saveTest(null, $this->test(['test_name' => '  Hemo   globin ', 'unit' => '  ', 'ref_low' => ' 12.50 ']), ' admin ');

        $this->assertTrue($result['success']);
        $this->assertSame(7, $result['test_id']);
        $this->assertSame('Hemo globin', $captured[1]->testName);
        $this->assertNull($captured[1]->unit, 'blank becomes NULL');
        $this->assertSame('12.50', $captured[1]->refLow);
        $this->assertSame('16', $captured[1]->refHigh);
        $this->assertSame('admin', $captured[2]);
    }

    #[DataProvider('badTestProvider')]
    public function testBadTestInputNeverReachesTheDatabase(array $input, string $field): void
    {
        $this->repository->expects($this->never())->method('saveTest');

        $result = $this->service->saveTest(null, $this->test($input), 'admin');

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey($field, $result['errors']);
    }

    public static function badTestProvider(): array
    {
        return [
            'no name' => [['test_name' => ''], 'testName'],
            'long name' => [['test_name' => str_repeat('x', 151)], 'testName'],
            'bad category' => [['category' => 'Magic'], 'category'],
            'bad sample' => [['sample_type' => 'Hair'], 'sampleType'],
            'bad result type' => [['result_type' => 'Picture'], 'resultType'],
            'text low' => [['ref_low' => 'abc'], 'refLow'],
            'five decimals' => [['ref_high' => '1.23456'], 'refHigh'],
            'low above high' => [['ref_low' => '20', 'ref_high' => '10'], 'refLow'],
            'text with range' => [['result_type' => 'Text', 'ref_low' => '1', 'ref_high' => '2'], 'refLow'],
        ];
    }

    public function testADuplicateNameIsAttachedToTheNameField(): void
    {
        $message = 'A test with this name already exists';
        $this->repository->method('saveTest')->willReturn(['success' => false, 'message' => $message, 'test_id' => null, 'test_code' => null]);

        $result = $this->service->saveTest(null, $this->test(), 'admin');

        $this->assertSame($message, $result['errors']['testName']);
    }

    // ------------------------------------------------------------------ ordering

    public function testATestIsOrdered(): void
    {
        $this->repository->expects($this->once())->method('addOrderItem')
            ->with(12, 4, 'Urgent', 'Pallor and fatigue', 'dr.adams')
            ->willReturn($this->ok(['item_id' => 3, 'order_id' => 2, 'order_code' => 'LAB-2026-000002']));

        $result = $this->service->addOrderItem(12, '4', 'urgent', ' Pallor   and fatigue ', ' dr.adams ');

        $this->assertTrue($result['success']);
        $this->assertSame(3, $result['item_id']);
        $this->assertSame(2, $result['order_id']);
    }

    #[DataProvider('badOrderProvider')]
    public function testBadOrderInputNeverReachesTheDatabase(array $args, string $field): void
    {
        $this->repository->expects($this->never())->method('addOrderItem');

        $result = $this->service->addOrderItem(12, ...$args);

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey($field, $result['errors']);
    }

    public static function badOrderProvider(): array
    {
        return [
            'no test' => [['', 'Routine', null, 'dr'], 'testId'],
            'text test' => [['abc', 'Routine', null, 'dr'], 'testId'],
            'bad priority' => [['4', 'ASAP', null, 'dr'], 'priority'],
            'long notes' => [['4', 'Routine', str_repeat('x', 501), 'dr'], 'clinicalNotes'],
        ];
    }

    public function testADuplicateTestIsAttachedToTheTestField(): void
    {
        $message = 'This test is already on the order';
        $this->repository->method('addOrderItem')->willReturn(['success' => false, 'message' => $message, 'item_id' => null, 'order_id' => null, 'order_code' => null]);

        $this->assertSame($message, $this->service->addOrderItem(12, '4', 'Routine', null, 'dr')['errors']['testId']);
    }

    public function testALockedConsultationMessageIsPassedThrough(): void
    {
        $message = 'Tests can be ordered only while the consultation is in progress (status: Ready for Billing)';
        $this->repository->method('addOrderItem')->willReturn(['success' => false, 'message' => $message, 'item_id' => null, 'order_id' => null, 'order_code' => null]);

        $result = $this->service->addOrderItem(12, '4', 'Routine', null, 'dr');

        $this->assertSame($message, $result['message']);
        $this->assertSame([], $result['errors']);
    }

    // ------------------------------------------------------------------ samples

    public function testASampleIsCollectedWithACanonicalType(): void
    {
        $this->repository->expects($this->once())->method('collectSample')->with(5, 'Blood', 'left arm', 'tech')
            ->willReturn($this->ok(['sample_id' => 9, 'sample_code' => 'SMP-2026-000009', 'test_count' => 2]));

        $result = $this->service->collectSample(5, ' blood ', ' left   arm ', 'tech');

        $this->assertTrue($result['success']);
        $this->assertSame(9, $result['sample_id']);
        $this->assertSame(5, $result['order_id']);
    }

    public function testBadSampleInputNeverReachesTheDatabase(): void
    {
        $this->repository->expects($this->never())->method('collectSample');

        $this->assertArrayHasKey('sampleType', $this->service->collectSample(5, 'Hair', null, 'tech')['errors']);
        $this->assertArrayHasKey('notes', $this->service->collectSample(5, 'Blood', str_repeat('x', 256), 'tech')['errors']);
        $this->assertSame('Lab order not found', $this->service->collectSample(0, 'Blood', null, 'tech')['message']);
        $this->assertSame('You must be signed in to perform this action', $this->service->collectSample(5, 'Blood', null, '  ')['message']);
    }

    public function testNothingWaitingForThatSampleIsPassedThrough(): void
    {
        $message = 'No test on this order is waiting for a Blood sample';
        $this->repository->method('collectSample')->willReturn(['success' => false, 'message' => $message, 'sample_id' => null, 'sample_code' => null, 'test_count' => 0]);

        $result = $this->service->collectSample(5, 'Blood', null, 'tech');

        $this->assertFalse($result['success']);
        $this->assertSame($message, $result['message']);
    }

    // ------------------------------------------------------------------ results

    public function testANumericResultIsPassedWithoutAFlagAndTheCalculatedFlagComesBack(): void
    {
        $this->repository->expects($this->once())->method('enterResult')->with(8, '10.5', null, 'repeat', 'tech')
            ->willReturn(['success' => true, 'message' => 'Result of Hemoglobin saved (Low)', 'result_flag' => 'Low']);

        $result = $this->service->enterResult(8, ' 10.5 ', '', ' repeat ', 'tech');

        $this->assertTrue($result['success']);
        $this->assertSame('Low', $result['result_flag']);
        $this->assertSame(8, $result['item_id']);
    }

    public function testATextResultPassesItsFlag(): void
    {
        $this->repository->expects($this->once())->method('enterResult')->with(9, 'Protein trace', 'Abnormal', null, 'tech')
            ->willReturn(['success' => true, 'message' => 'saved', 'result_flag' => 'Abnormal']);

        $this->assertSame('Abnormal', $this->service->enterResult(9, 'Protein trace', 'abnormal', '', 'tech')['result_flag']);
    }

    public function testAnUnknownFlagIsDroppedAndTheDatabaseDecides(): void
    {
        $this->repository->expects($this->once())->method('enterResult')->with(9, 'x', null, null, 'tech')
            ->willReturn(['success' => false, 'message' => 'Select Normal or Abnormal', 'result_flag' => null]);

        $result = $this->service->enterResult(9, 'x', 'High', null, 'tech');

        $this->assertFalse($result['success']);
        $this->assertSame('Select Normal or Abnormal', $result['errors']['flag']);
    }

    public function testBadResultInputNeverReachesTheDatabase(): void
    {
        $this->repository->expects($this->never())->method('enterResult');

        $this->assertArrayHasKey('value', $this->service->enterResult(8, '   ', null, null, 'tech')['errors']);
        $this->assertArrayHasKey('value', $this->service->enterResult(8, str_repeat('x', 501), null, null, 'tech')['errors']);
        $this->assertArrayHasKey('comment', $this->service->enterResult(8, '5', null, str_repeat('x', 501), 'tech')['errors']);
        $this->assertSame('Test not found', $this->service->enterResult(0, '5', null, null, 'tech')['message']);
    }

    public function testAResultThatCannotBeChangedIsPassedThrough(): void
    {
        $message = 'Hemoglobin already has a result, and it cannot be changed';
        $this->repository->method('enterResult')->willReturn(['success' => false, 'message' => $message, 'result_flag' => null]);

        $result = $this->service->enterResult(8, '14', null, null, 'tech');

        $this->assertSame($message, $result['message']);
        $this->assertSame([], $result['errors']);
    }

    // ------------------------------------------------------------------ cancellation and removal

    public function testCancellingNeedsAReason(): void
    {
        $this->repository->expects($this->once())->method('cancelItem')->with(8, 'patient refused', 'tech')->willReturn($this->ok());

        $this->assertArrayHasKey('reason', $this->service->cancelItem(8, '   ', 'tech')['errors']);
        $this->assertArrayHasKey('reason', $this->service->cancelItem(8, str_repeat('x', 256), 'tech')['errors']);
        $this->assertTrue($this->service->cancelItem(8, ' patient   refused ', 'tech')['success']);
    }

    public function testBadIdsAndAMissingUserNeverReachTheRepository(): void
    {
        $this->repository->expects($this->never())->method('cancelItem');
        $this->repository->expects($this->never())->method('removeOrderItem');
        $this->repository->expects($this->never())->method('addOrderItem');

        $this->assertSame('Test not found', $this->service->cancelItem(0, 'x', 'tech')['message']);
        $this->assertSame('You must be signed in to perform this action', $this->service->cancelItem(8, 'x', '')['message']);
        $this->assertSame('Test not found on the order', $this->service->removeOrderItem(0, 'dr')['message']);
        $this->assertSame('Consultation not found', $this->service->addOrderItem(0, '1', 'Routine', null, 'dr')['message']);
    }

    public function testDatabaseBooleansInAnyDriverFormAreUnderstood(): void
    {
        $this->repository->method('removeOrderItem')->willReturn(['success' => 't', 'message' => 'removed']);

        $this->assertTrue($this->service->removeOrderItem(4, 'dr')['success']);
    }
}
