<?php

namespace App\Repository;

use App\DAO\LabDAO;
use App\DTO\LabOrderDTO;
use App\DTO\LabSummaryDTO;
use App\DTO\LabTestDTO;

/**
 * Coordinates Laboratory data access through LabDAO and turns rows into DTOs. An order is assembled
 * here from its database reads (context, tests with results, samples). Write results are returned as
 * the arrays the database produced. No business rules live here.
 */
class LabRepository
{
    public const VIEW_PENDING = 'pending';
    public const VIEW_DONE = 'done';

    public function __construct(
        private LabDAO $labDAO
    ) {
    }

    // ------------------------------------------------------------------ catalogue

    /**
     * @return array{success: bool, message: string, test_id: ?int, test_code: ?string}
     */
    public function saveTest(?int $testId, LabTestDTO $test, string $by): array
    {
        return $this->labDAO->saveTest($testId, $test, $by);
    }

    public function getTest(int $testId): ?LabTestDTO
    {
        $row = $this->labDAO->getTestById($testId);

        return $row === null ? null : LabTestDTO::fromRow($row);
    }

    /**
     * @return list<LabTestDTO>
     */
    public function getTests(?string $search, ?string $category, ?string $status, int $limit, int $offset): array
    {
        $rows = $this->labDAO->getTestsList($search, $category, $status, $limit, $offset);

        return array_map(static fn (array $row): LabTestDTO => LabTestDTO::fromRow($row), $rows);
    }

    public function countTests(?string $search, ?string $category, ?string $status): int
    {
        return $this->labDAO->countTests($search, $category, $status);
    }

    /**
     * Active tests only.
     *
     * @return list<LabTestDTO>
     */
    public function searchOrderable(?string $search, int $limit): array
    {
        $rows = $this->labDAO->searchOrderableTests($search, $limit);

        return array_map(static fn (array $row): LabTestDTO => LabTestDTO::fromRow($row), $rows);
    }

    // ------------------------------------------------------------------ orders

    /** The lab order of a consultation or by order id, with tests and samples; null when not found. */
    public function getOrder(?int $consultationId, ?int $orderId): ?LabOrderDTO
    {
        $row = $this->labDAO->getOrderContext($consultationId, $orderId);
        if ($row === null) {
            return null;
        }

        $order = LabOrderDTO::fromRow($row);
        if ($order->orderId !== null) {
            $order->items = $this->labDAO->getOrderItems($order->orderId);
            $order->samples = $this->labDAO->getOrderSamples($order->orderId);
            $order->itemCount = count($order->items);
        }

        return $order;
    }

    /**
     * @return array{success: bool, message: string, item_id: ?int, order_id: ?int, order_code: ?string}
     */
    public function addOrderItem(int $consultationId, int $testId, string $priority, ?string $notes, string $by): array
    {
        return $this->labDAO->addOrderItem($consultationId, $testId, $priority, $notes, $by);
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function removeOrderItem(int $itemId, string $by): array
    {
        return $this->labDAO->removeOrderItem($itemId, $by);
    }

    // ------------------------------------------------------------------ the lab

    /**
     * @return list<LabOrderDTO>
     */
    public function getWorklist(string $view, ?string $search, int $limit, int $offset): array
    {
        $rows = $this->labDAO->getWorklist($view, $search, $limit, $offset);

        return array_map(static fn (array $row): LabOrderDTO => LabOrderDTO::fromRow($row), $rows);
    }

    public function countWorklist(string $view, ?string $search): int
    {
        return $this->labDAO->countWorklist($view, $search);
    }

    /**
     * @return array{success: bool, message: string, sample_id: ?int, sample_code: ?string, test_count: int}
     */
    public function collectSample(int $orderId, string $sampleType, ?string $notes, string $by): array
    {
        return $this->labDAO->collectSample($orderId, $sampleType, $notes, $by);
    }

    /**
     * @return array{success: bool, message: string, result_flag: ?string}
     */
    public function enterResult(int $itemId, string $value, ?string $flag, ?string $comment, string $by): array
    {
        return $this->labDAO->enterResult($itemId, $value, $flag, $comment, $by);
    }

    /**
     * @return array{success: bool, message: string}
     */
    public function cancelItem(int $itemId, string $reason, string $by): array
    {
        return $this->labDAO->cancelItem($itemId, $reason, $by);
    }

    // ------------------------------------------------------------------ dashboard

    public function getSummary(): LabSummaryDTO
    {
        return LabSummaryDTO::fromRow($this->labDAO->getSummary());
    }
}