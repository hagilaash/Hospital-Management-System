<?php

namespace App\DTO;

/**
 * One page of any list (consultations, bills, services, RCM records ...).
 * Immutable: a Service builds it once and the controller hands it to Twig.
 *
 * $filters holds the filters that produced the page (already normalised), so a
 * template can redisplay them and build pager links. $today is the hospital's
 * current day, which templates need for date-dependent buttons.
 */
class PagedListDTO
{
    /**
     * @param list<object>         $items
     * @param array<string, mixed> $filters
     */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
        public readonly int $totalPages,
        public readonly array $filters = [],
        public readonly string $today = ''
    ) {
    }

    /** An empty first page, used when the database cannot be reached. */
    public static function empty(int $perPage = 10, array $filters = []): self
    {
        return new self([], 0, 1, $perPage, 1, $filters);
    }

    public function hasPreviousPage(): bool
    {
        return $this->page > 1;
    }

    public function hasNextPage(): bool
    {
        return $this->page < $this->totalPages;
    }

    public function getFirstItemNumber(): int
    {
        return $this->total === 0 ? 0 : ($this->page - 1) * $this->perPage + 1;
    }

    public function getLastItemNumber(): int
    {
        if ($this->total === 0) {
            return 0;
        }

        return min($this->total, ($this->page - 1) * $this->perPage + count($this->items));
    }
}