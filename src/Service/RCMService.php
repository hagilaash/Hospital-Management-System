<?php

namespace App\Service;

use App\Common\Input;
use App\DTO\PagedListDTO;
use App\DTO\RCMCaseDTO;
use App\DTO\RCMSummaryDTO;
use App\Repository\RCMRepository;

/**
 * Business logic for Revenue Cycle Management: Pending queue -> Review -> Finalize
 * -> Completed.
 *
 * - A patient is in the pending queue exactly while their bill is fully paid and
 *   has not been finalized. That rule lives in PostgreSQL (one view and one status
 *   function), so this class can never disagree with the database.
 * - Nothing here creates or changes a patient, bill or payment.
 * - Finalization is validated here (user, remarks) and enforced in the database
 *   (fully paid, not already finalized, advisory lock, UNIQUE bill): pressing the
 *   button twice, or two staff finalizing the same case, produces one record.
 *
 * finalize() returns one array shape:
 *
 *   [
 *     'success'           => bool,
 *     'message'           => string,                  // safe to show to the user
 *     'errors'            => array<string, string>,   // fieldName => message
 *     'rcm_id'            => ?int,
 *     'already_finalized' => bool,                    // true: someone finalized it first
 *     'visit_note'        => ?string,                 // set when the visit could not be closed automatically
 *   ]
 */
class RCMService
{
    public const PER_PAGE = 10;
    public const PREVIEW_CASES = 5;
    public const MAX_REMARKS = 500;

    private const MESSAGE_NOT_FOUND = 'Case not found';
    private const MESSAGE_NOT_SIGNED_IN = 'You must be signed in to finalize';

    public function __construct(
        private RCMRepository $rcmRepository,
    ) {
    }

    public function getSummary(): RCMSummaryDTO
    {
        return $this->rcmRepository->getSummary();
    }

    /**
     * The patients who have waited longest in the queue, for the overview page.
     *
     * @return list<RCMCaseDTO>
     */
    public function getPendingPreview(): array
    {
        return $this->rcmRepository->getCases(RCMRepository::VIEW_PENDING, null, null, null, self::PREVIEW_CASES, 0);
    }

    /**
     * The pending queue, longest-waiting first, 10 per page.
     * Filters: search, date_from, date_to (the day billing was completed); all optional.
     */
    public function getPending(?string $search, ?string $dateFrom, ?string $dateTo, int $page = 1): PagedListDTO
    {
        return $this->page(RCMRepository::VIEW_PENDING, $search, $dateFrom, $dateTo, $page);
    }

    /**
     * Completed (finalized) records, newest first, 10 per page.
     * Filters: search, date_from, date_to (the day it was finalized); all optional.
     */
    public function getCompleted(?string $search, ?string $dateFrom, ?string $dateTo, int $page = 1): PagedListDTO
    {
        return $this->page(RCMRepository::VIEW_FINALIZED, $search, $dateFrom, $dateTo, $page);
    }

    /** One case for review (any existing bill; the DTO says whether it can be finalized). */
    public function getCase(int $billId): ?RCMCaseDTO
    {
        if ($billId < 1) {
            return null;
        }

        return $this->rcmRepository->getCase($billId);
    }

    public function finalize(int $billId, ?string $remarks, string $performedBy): array
    {
        if ($billId < 1) {
            return $this->failure(self::MESSAGE_NOT_FOUND);
        }
        $performedBy = trim($performedBy);
        if ('' === $performedBy) {
            return $this->failure(self::MESSAGE_NOT_SIGNED_IN);
        }

        $remarks = Input::nullIfEmpty(Input::cleanMultiline($remarks));
        if (null !== $remarks && mb_strlen($remarks) > self::MAX_REMARKS) {
            $message = 'The remarks must be '.self::MAX_REMARKS.' characters or fewer';

            return $this->failure($message, ['remarks' => $message]);
        }

        $db = $this->rcmRepository->finalize($billId, $remarks, $performedBy);

        $result = $this->failure((string) ($db['message'] ?? ''));
        $result['success'] = Input::isTrue($db['success'] ?? false);
        $result['rcm_id'] = isset($db['rcm_id']) ? (int) $db['rcm_id'] : null;
        $result['already_finalized'] = Input::isTrue($db['already_finalized'] ?? false);
        $result['visit_note'] = isset($db['visit_note']) && '' !== $db['visit_note'] ? (string) $db['visit_note'] : null;
        if (!$result['success'] && str_starts_with($result['message'], 'The remarks must')) {
            $result['errors']['remarks'] = $result['message'];
        }

        return $result;
    }

    // ------------------------------------------------------------------

    private function page(string $view, ?string $search, ?string $dateFrom, ?string $dateTo, int $page): PagedListDTO
    {
        $search = Input::search($search);
        $from = Input::dateOrNull($dateFrom);
        $to = Input::dateOrNull($dateTo);
        if (null !== $from && null !== $to && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        $total = $this->rcmRepository->countCases($view, $search, $from, $to);
        $totalPages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min(max(1, $page), $totalPages);

        $items = $total > 0
            ? $this->rcmRepository->getCases($view, $search, $from, $to, self::PER_PAGE, ($page - 1) * self::PER_PAGE)
            : [];

        return new PagedListDTO(
            $items,
            $total,
            $page,
            self::PER_PAGE,
            $totalPages,
            ['search' => $search, 'date_from' => $from, 'date_to' => $to]
        );
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
            'rcm_id' => null,
            'already_finalized' => false,
            'visit_note' => null,
        ];
    }
}
