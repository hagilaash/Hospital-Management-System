<?php

namespace App\Tests\DTO;

use App\DTO\LabOrderDTO;
use App\DTO\LabSummaryDTO;
use App\DTO\LabTestDTO;
use PHPUnit\Framework\TestCase;

class LabDTOTest extends TestCase
{
    public function testATestRowDropsTrailingZerosOfTheRange(): void
    {
        $dto = LabTestDTO::fromRow([
            'test_id' => '4', 'test_code' => 'TST-2026-000004', 'test_name' => 'Hemoglobin', 'category' => 'Hematology',
            'sample_type' => 'Blood', 'result_type' => 'Numeric', 'unit' => 'g/dL', 'ref_low' => '12.0000', 'ref_high' => '16.5000', 'is_active' => 't',
        ]);

        $this->assertSame(4, $dto->testId);
        $this->assertSame('12', $dto->refLow);
        $this->assertSame('16.5', $dto->refHigh);
        $this->assertTrue($dto->isActive);
        $this->assertTrue($dto->isNumeric());
        $this->assertSame('12 – 16.5 g/dL', $dto->rangeLabel());
    }

    public function testTheRangeLabelFallsBackInTheRightOrder(): void
    {
        $text = LabTestDTO::fromRow(['test_name' => 'Urine', 'result_type' => 'Text', 'reference_text' => 'No abnormality']);
        $this->assertSame('No abnormality', $text->rangeLabel());
        $this->assertFalse($text->isNumeric());

        $onlyHigh = LabTestDTO::fromRow(['test_name' => 'LDL', 'ref_high' => '100.0000', 'unit' => 'mg/dL']);
        $this->assertSame('≤ 100 mg/dL', $onlyHigh->rangeLabel());

        $onlyLow = LabTestDTO::fromRow(['test_name' => 'HDL', 'ref_low' => '40.0000']);
        $this->assertSame('≥ 40', $onlyLow->rangeLabel());

        $none = LabTestDTO::fromRow(['test_name' => 'X']);
        $this->assertSame('', $none->rangeLabel());
    }

    public function testTestInputReadsOnlyTheEditableFields(): void
    {
        $dto = LabTestDTO::fromInput([
            'test_name' => ' Hemoglobin ', 'category' => 'Hematology', 'sample_type' => 'Blood', 'result_type' => 'Numeric', 'is_active' => '1',
            'test_id' => '99', 'test_code' => 'TST-HACK',
        ]);

        $this->assertSame('Hemoglobin', $dto->testName);
        $this->assertTrue($dto->isActive);
        $this->assertNull($dto->testId);
        $this->assertNull($dto->testCode);
        $this->assertFalse(LabTestDTO::fromInput(['test_name' => 'X'])->isActive, 'an unticked checkbox is absent');
    }

    public function testAnOrderContextRowAndItsRules(): void
    {
        $dto = LabOrderDTO::fromRow([
            'consultation_id' => 12, 'consultation_code' => 'CON-2026-000012', 'consultation_status' => 'In Progress',
            'patient_id' => 5, 'patient_name' => 'Anita Raman', 'doctor_name' => 'Dr. Adams',
            'order_id' => null, 'order_status' => null,
        ]);

        $this->assertFalse($dto->hasOrder());
        $this->assertTrue($dto->canEdit());
        $this->assertFalse($dto->isOpen());
        $this->assertFalse($dto->hasResults());
        $this->assertSame([], $dto->sampleTypesWaiting());
    }

    public function testTheWaitingSampleTypesAreDistinct(): void
    {
        $dto = LabOrderDTO::fromRow(['order_id' => 3, 'order_status' => 'In Progress', 'consultation_status' => 'Ready for Billing', 'priority' => 'Urgent']);
        $dto->items = [
            ['status' => 'Ordered', 'sample_type' => 'Blood'],
            ['status' => 'Ordered', 'sample_type' => 'Blood'],
            ['status' => 'Ordered', 'sample_type' => 'Urine'],
            ['status' => 'Sample Collected', 'sample_type' => 'Swab'],
            ['status' => 'Resulted', 'sample_type' => 'Stool'],
        ];

        $this->assertSame(['Blood', 'Urine'], $dto->sampleTypesWaiting());
        $this->assertTrue($dto->hasResults());
        $this->assertTrue($dto->isOpen());
        $this->assertTrue($dto->isUrgent());
        $this->assertFalse($dto->canEdit());
        $this->assertFalse($dto->isCompleted());
    }

    public function testAWorklistRowUsesTheCreatedAtColumn(): void
    {
        $dto = LabOrderDTO::fromRow([
            'order_id' => 3, 'order_code' => 'LAB-2026-000003', 'priority' => 'Routine', 'order_status' => 'Completed',
            'created_at' => '2026-10-22 10:00:00+05:30', 'item_count' => '3', 'awaiting_sample' => 0, 'awaiting_result' => '0', 'resulted_count' => '3', 'abnormal_count' => '2',
        ]);

        $this->assertTrue($dto->isCompleted());
        $this->assertSame(3, $dto->itemCount);
        $this->assertSame(2, $dto->abnormalCount);
        $this->assertNotNull($dto->createdAt);
    }

    public function testTheSummaryRow(): void
    {
        $dto = LabSummaryDTO::fromRow([
            'active_tests' => '20', 'pending_orders' => 4, 'urgent_orders' => '1', 'awaiting_sample' => '5', 'awaiting_result' => '3',
            'resulted_today' => '12', 'abnormal_today' => '2', 'completed_today' => '4',
        ]);

        $this->assertSame(20, $dto->activeTests);
        $this->assertSame(1, $dto->urgentOrders);
        $this->assertSame(12, $dto->resultedToday);
        $this->assertSame(0, LabSummaryDTO::empty()->activeTests);
    }
}
