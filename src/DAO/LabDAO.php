<?php

namespace App\DAO;

use App\DB\LabDatabaseFunction;
use App\DTO\LabTestDTO;

/**
 * Executes the Laboratory database operations through LabDatabaseFunction. It only unpacks DTOs into
 * scalar arguments and returns the raw database results; it contains no business rules.
 */
class LabDAO
{
    public function __construct(
        private LabDatabaseFunction $labDatabaseFunction
    ) {
    }

    public function saveTest(?int $testId, LabTestDTO $test, string $by): array
    {
        return $this->labDatabaseFunction->saveTest(
            $testId,
            $test->testName,
            $test->category,
            $test->sampleType,
            $test->resultType,
            $test->unit,
            $test->refLow,
            $test->refHigh,
            $test->referenceText,
            $test->isActive,
            $by
        );
    }

    public function getTestById(int $testId): ?array
    {
        return $this->labDatabaseFunction->getTestById($testId);
    }

    public function getTestsList(?string $search, ?string $category, ?string $status, int $limit, int $offset): array
    {
        return $this->labDatabaseFunction->getTestsList($search, $category, $status, $limit, $offset);
    }

    public function countTests(?string $search, ?string $category, ?string $status): int
    {
        return $this->labDatabaseFunction->countTests($search, $category, $status);
    }

    public function searchOrderableTests(?string $search, int $limit): array
    {
        return $this->labDatabaseFunction->searchOrderableTests($search, $limit);
    }

    public function getOrderContext(?int $consultationId, ?int $orderId): ?array
    {
        return $this->labDatabaseFunction->getOrderContext($consultationId, $orderId);
    }

    public function getOrderItems(int $orderId): array
    {
        return $this->labDatabaseFunction->getOrderItems($orderId);
    }

    public function getOrderSamples(int $orderId): array
    {
        return $this->labDatabaseFunction->getOrderSamples($orderId);
    }

    public function addOrderItem(int $consultationId, int $testId, string $priority, ?string $notes, string $by): array
    {
        return $this->labDatabaseFunction->addOrderItem($consultationId, $testId, $priority, $notes, $by);
    }

    public function removeOrderItem(int $itemId, string $by): array
    {
        return $this->labDatabaseFunction->removeOrderItem($itemId, $by);
    }

    public function getWorklist(string $view, ?string $search, int $limit, int $offset): array
    {
        return $this->labDatabaseFunction->getWorklist($view, $search, $limit, $offset);
    }

    public function countWorklist(string $view, ?string $search): int
    {
        return $this->labDatabaseFunction->countWorklist($view, $search);
    }

    public function collectSample(int $orderId, string $sampleType, ?string $notes, string $by): array
    {
        return $this->labDatabaseFunction->collectSample($orderId, $sampleType, $notes, $by);
    }

    public function enterResult(int $itemId, string $value, ?string $flag, ?string $comment, string $by): array
    {
        return $this->labDatabaseFunction->enterResult($itemId, $value, $flag, $comment, $by);
    }

    public function cancelItem(int $itemId, string $reason, string $by): array
    {
        return $this->labDatabaseFunction->cancelItem($itemId, $reason, $by);
    }

    public function getSummary(): array
    {
        return $this->labDatabaseFunction->getSummary();
    }
}