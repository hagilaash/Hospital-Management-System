<?php

namespace App\Service;

use App\Common\Input;
use App\DTO\LabOrderDTO;
use App\DTO\LabSummaryDTO;
use App\DTO\LabTestDTO;
use App\DTO\PagedListDTO;
use App\Repository\LabRepository;

/**
 * Business logic for the Laboratory (LIS): test catalogue, orders from OP Consultation, sample
 * collection and results.
 *
 * - Cleans and validates every field before it reaches the database. The database then enforces the
 *   same limits plus the rules that need locking: tests can be ordered or removed only while the
 *   consultation is In Progress, a result needs a collected sample, a numeric result is flagged
 *   Low / High / Normal by the test's reference range, a result can never be changed, and an order's
 *   status is always derived from its tests.
 * - Prices and billing of lab tests are deliberately NOT part of this module.
 *
 * Every write returns one array shape:
 *
 *   [
 *     'success'     => bool,
 *     'message'     => string,                  // safe to show to the user
 *     'errors'      => array<string, string>,   // fieldName => message
 *     'test_id'     => ?int,
 *     'item_id'     => ?int,
 *     'order_id'    => ?int,
 *     'sample_id'   => ?int,
 *     'result_flag' => ?string,                 // Normal / Low / High / Abnormal after a result
 *   ]
 */
class LabService
{
    public const CATEGORIES = ['Hematology', 'Biochemistry', 'Microbiology', 'Serology', 'Urine', 'Radiology support', 'Other'];
    public const SAMPLE_TYPES = ['Blood', 'Urine', 'Stool', 'Swab', 'Sputum', 'Other'];
    public const RESULT_TYPES = ['Numeric', 'Text'];
    public const PRIORITIES = ['Routine', 'Urgent'];
    public const STATUS_FILTERS = ['all', 'active', 'inactive'];

    public const PER_PAGE = 10;
    public const SEARCH_RESULTS = 30;

    private const MESSAGE_VALIDATION = 'Please correct the errors below';
    private const MESSAGE_NOT_SIGNED_IN = 'You must be signed in to perform this action';
    private const NUMBER_PATTERN = '/^-?\d{1,10}(\.\d{1,4})?$/';

    public function __construct(
        private LabRepository $labRepository
    ) {
    }

    // ------------------------------------------------------------------
    // Reads
    // ------------------------------------------------------------------

    public function getSummary(): LabSummaryDTO
    {
        return $this->labRepository->getSummary();
    }

    /**
     * The test catalogue, 10 per page. Filters: search (name or code), category, status (all / active / inactive).
     */
    public function getTests(?string $search, ?string $category, ?string $status, int $page = 1): PagedListDTO
    {
        $search = Input::search($search);
        $category = Input::whitelist($category, self::CATEGORIES, '');
        $category = $category === '' ? null : $category;
        $status = Input::whitelist($status, self::STATUS_FILTERS, 'all');

        $total = $this->labRepository->countTests($search, $category, $status);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $page), $totalPages);

        $items = $total > 0
            ? $this->labRepository->getTests($search, $category, $status, self::PER_PAGE, ($page - 1) * self::PER_PAGE)
            : [];

        return new PagedListDTO($items, $total, $page, self::PER_PAGE, $totalPages, ['search' => $search, 'category' => $category, 'status' => $status]);
    }

    public function getTest(int $testId): ?LabTestDTO
    {
        return $testId < 1 ? null : $this->labRepository->getTest($testId);
    }

    /**
     * The tests a doctor may order: active tests only.
     *
     * @return list<LabTestDTO>
     */
    public function searchOrderable(?string $search): array
    {
        return $this->labRepository->searchOrderable(Input::search($search), self::SEARCH_RESULTS);
    }

    /** A consultation's lab order (empty header until the first test is ordered), or null when the consultation does not exist. */
    public function getOrderForConsultation(int $consultationId): ?LabOrderDTO
    {
        return $consultationId < 1 ? null : $this->labRepository->getOrder($consultationId, null);
    }

    public function getOrder(int $orderId): ?LabOrderDTO
    {
        return $orderId < 1 ? null : $this->labRepository->getOrder(null, $orderId);
    }

    /**
     * The lab worklist, 10 per page. $view: 'pending' (urgent first, then oldest) or 'done' (newest first).
     */
    public function getWorklist(?string $view, ?string $search, int $page = 1): PagedListDTO
    {
        $view = Input::whitelist($view, [LabRepository::VIEW_PENDING, LabRepository::VIEW_DONE], LabRepository::VIEW_PENDING);
        $search = Input::search($search);

        $total = $this->labRepository->countWorklist($view, $search);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $page), $totalPages);

        $items = $total > 0
            ? $this->labRepository->getWorklist($view, $search, self::PER_PAGE, ($page - 1) * self::PER_PAGE)
            : [];

        return new PagedListDTO($items, $total, $page, self::PER_PAGE, $totalPages, ['view' => $view, 'search' => $search]);
    }

    // ------------------------------------------------------------------
    // Test catalogue
    // ------------------------------------------------------------------

    /** $testId null adds a test, otherwise edits it. */
    public function saveTest(?int $testId, LabTestDTO $input, string $performedBy): array
    {
        $performedBy = trim($performedBy);
        if ($performedBy === '') {
            return $this->failure(self::MESSAGE_NOT_SIGNED_IN);
        }

        $test = new LabTestDTO();
        $test->testName = Input::clean($input->testName);
        $test->category = Input::whitelist($input->category, self::CATEGORIES, '');
        $test->sampleType = Input::whitelist($input->sampleType, self::SAMPLE_TYPES, '');
        $test->resultType = Input::whitelist($input->resultType, self::RESULT_TYPES, '');
        $test->unit = Input::nullIfEmpty(Input::clean($input->unit));
        $test->referenceText = Input::nullIfEmpty(Input::clean($input->referenceText));
        $test->isActive = $input->isActive;

        $errors = [];
        if ($test->testName === '') {
            $errors['testName'] = 'Test name is required';
        } elseif (mb_strlen($test->testName) > 150) {
            $errors['testName'] = 'Test name must be 150 characters or fewer';
        }
        if ($test->category === '') {
            $errors['category'] = 'Select a category';
        }
        if ($test->sampleType === '') {
            $errors['sampleType'] = 'Select a sample type';
        }
        if ($test->resultType === '') {
            $errors['resultType'] = 'Select a result type';
        }
        if ($test->unit !== null && mb_strlen($test->unit) > 30) {
            $errors['unit'] = 'Unit must be 30 characters or fewer';
        }
        if ($test->referenceText !== null && mb_strlen($test->referenceText) > 100) {
            $errors['referenceText'] = 'Reference text must be 100 characters or fewer';
        }

        $low = $this->number($input->refLow, 'refLow', 'The low end of the range', $errors);
        $high = $this->number($input->refHigh, 'refHigh', 'The high end of the range', $errors);
        if ($test->resultType === 'Text' && ($low !== null || $high !== null)) {
            $errors['refLow'] = 'A text result cannot have a numeric reference range';
        }
        if ($low !== null && $high !== null && (float) $low > (float) $high) {
            $errors['refLow'] = 'The low end of the range cannot be above the high end';
        }
        $test->refLow = $low;
        $test->refHigh = $high;

        if ($errors !== []) {
            return $this->failure(self::MESSAGE_VALIDATION, $errors);
        }

        $db = $this->labRepository->saveTest($testId, $test, $performedBy);

        $result = $this->resultFrom($db, [
            'Test name' => 'testName',
            'A test with this name' => 'testName',
            'Select a valid category' => 'category',
            'Select a valid sample type' => 'sampleType',
            'Select a valid result type' => 'resultType',
            'The reference range' => 'refLow',
            'The low end' => 'refLow',
            'A text result cannot' => 'refLow',
        ]);
        $result['test_id'] = isset($db['test_id']) ? (int) $db['test_id'] : null;

        return $result;
    }

    // ------------------------------------------------------------------
    // The doctor orders tests (OP Consultation)
    // ------------------------------------------------------------------

    public function addOrderItem(int $consultationId, ?string $testId, ?string $priority, ?string $notes, string $performedBy): array
    {
        if ($consultationId < 1) {
            return $this->failure('Consultation not found');
        }
        $performedBy = trim($performedBy);
        if ($performedBy === '') {
            return $this->failure(self::MESSAGE_NOT_SIGNED_IN);
        }

        $errors = [];

        $test = Input::positiveInt($testId);
        if ($test === null) {
            $errors['testId'] = 'Select a test';
        }

        $priorityText = Input::whitelist($priority, self::PRIORITIES, '');
        if ($priorityText === '') {
            $errors['priority'] = 'Select Routine or Urgent';
        }

        $notesText = Input::nullIfEmpty(Input::cleanMultiline($notes));
        if ($notesText !== null && mb_strlen($notesText) > 500) {
            $errors['clinicalNotes'] = 'The clinical notes must be 500 characters or fewer';
        }

        if ($errors !== []) {
            return $this->failure(self::MESSAGE_VALIDATION, $errors);
        }

        $db = $this->labRepository->addOrderItem($consultationId, (int) $test, $priorityText, $notesText, $performedBy);

        $result = $this->resultFrom($db, [
            'Test not found' => 'testId',
            'This test is already' => 'testId',
            'The clinical notes' => 'clinicalNotes',
        ]);
        $result['item_id'] = isset($db['item_id']) ? (int) $db['item_id'] : null;
        $result['order_id'] = isset($db['order_id']) ? (int) $db['order_id'] : null;

        return $result;
    }

    public function removeOrderItem(int $itemId, string $performedBy): array
    {
        if ($itemId < 1) {
            return $this->failure('Test not found on the order');
        }
        $performedBy = trim($performedBy);
        if ($performedBy === '') {
            return $this->failure(self::MESSAGE_NOT_SIGNED_IN);
        }

        return $this->resultFrom($this->labRepository->removeOrderItem($itemId, $performedBy), []);
    }

    // ------------------------------------------------------------------
    // The lab: samples, results, cancellation
    // ------------------------------------------------------------------

    /** Collects one sample of the given type; every waiting test of that type is linked to it. */
    public function collectSample(int $orderId, ?string $sampleType, ?string $notes, string $performedBy): array
    {
        if ($orderId < 1) {
            return $this->failure('Lab order not found');
        }
        $performedBy = trim($performedBy);
        if ($performedBy === '') {
            return $this->failure(self::MESSAGE_NOT_SIGNED_IN);
        }

        $type = Input::whitelist($sampleType, self::SAMPLE_TYPES, '');
        if ($type === '') {
            return $this->failure(self::MESSAGE_VALIDATION, ['sampleType' => 'Select a sample type']);
        }
        $notesText = Input::nullIfEmpty(Input::clean($notes));
        if ($notesText !== null && mb_strlen($notesText) > 255) {
            return $this->failure(self::MESSAGE_VALIDATION, ['notes' => 'The notes must be 255 characters or fewer']);
        }

        $db = $this->labRepository->collectSample($orderId, $type, $notesText, $performedBy);

        $result = $this->resultFrom($db, []);
        $result['order_id'] = $orderId;
        $result['sample_id'] = isset($db['sample_id']) ? (int) $db['sample_id'] : null;

        return $result;
    }

    /**
     * Enters the result of one test. Numeric test: $value is a number and the flag is calculated from the
     * reference range. Text test: $value is the finding and $flag must be Normal or Abnormal.
     */
    public function enterResult(int $itemId, ?string $value, ?string $flag, ?string $comment, string $performedBy): array
    {
        if ($itemId < 1) {
            return $this->failure('Test not found');
        }
        $performedBy = trim($performedBy);
        if ($performedBy === '') {
            return $this->failure(self::MESSAGE_NOT_SIGNED_IN);
        }

        $errors = [];

        $valueText = Input::clean($value);
        if ($valueText === '') {
            $errors['value'] = 'Enter the result';
        } elseif (mb_strlen($valueText) > 500) {
            $errors['value'] = 'The result must be 500 characters or fewer';
        }

        $flagText = Input::whitelist($flag, ['Normal', 'Abnormal'], '');

        $commentText = Input::nullIfEmpty(Input::cleanMultiline($comment));
        if ($commentText !== null && mb_strlen($commentText) > 500) {
            $errors['comment'] = 'The comment must be 500 characters or fewer';
        }

        if ($errors !== []) {
            return $this->failure(self::MESSAGE_VALIDATION, $errors);
        }

        $db = $this->labRepository->enterResult($itemId, $valueText, Input::nullIfEmpty($flagText), $commentText, $performedBy);

        $result = $this->resultFrom($db, [
            'Enter the result' => 'value',
            'Enter the finding' => 'value',
            'Select Normal or Abnormal' => 'flag',
            'The comment' => 'comment',
        ]);
        $result['item_id'] = $itemId;
        $result['result_flag'] = isset($db['result_flag']) ? (string) $db['result_flag'] : null;

        return $result;
    }

    /** The lab cancels a test that has no result yet. A reason is required. */
    public function cancelItem(int $itemId, ?string $reason, string $performedBy): array
    {
        if ($itemId < 1) {
            return $this->failure('Test not found');
        }
        $performedBy = trim($performedBy);
        if ($performedBy === '') {
            return $this->failure(self::MESSAGE_NOT_SIGNED_IN);
        }

        $reasonText = Input::clean($reason);
        if ($reasonText === '') {
            return $this->failure('A reason is required', ['reason' => 'A reason is required']);
        }
        if (mb_strlen($reasonText) > 255) {
            $message = 'The reason must be 255 characters or fewer';

            return $this->failure($message, ['reason' => $message]);
        }

        $result = $this->resultFrom($this->labRepository->cancelItem($itemId, $reasonText, $performedBy), []);
        $result['item_id'] = $itemId;

        return $result;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * A reference-range end typed as a plain number with at most 4 decimals; blank = no limit.
     *
     * @param array<string, string> $errors
     */
    private function number(?string $value, string $field, string $label, array &$errors): ?string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (preg_match(self::NUMBER_PATTERN, $text) !== 1) {
            $errors[$field] = $label . ' must be a number with at most 4 decimals';

            return null;
        }

        return $text;
    }

    /**
     * success + message from a database row; a refusal that belongs to one field is also attached to that
     * field so the form can show it next to the input.
     *
     * @param array<string, string> $fieldMap database message prefix => form field
     */
    private function resultFrom(array $db, array $fieldMap): array
    {
        $result = $this->failure((string) ($db['message'] ?? ''));
        $result['success'] = Input::isTrue($db['success'] ?? false);

        if (!$result['success']) {
            foreach ($fieldMap as $prefix => $field) {
                if (str_starts_with($result['message'], $prefix)) {
                    $result['errors'][$field] = $result['message'];
                    break;
                }
            }
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
            'test_id' => null,
            'item_id' => null,
            'order_id' => null,
            'sample_id' => null,
            'result_flag' => null,
        ];
    }
}