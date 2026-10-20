<?php

namespace App\DTO;

/**
 * One page of the consultation queue plus its filters and the hospital's today.
 * Immutable: FDMService builds it once.
 */
class QueueListDTO
{
    /**
     * @param list<QueueEntryDTO> $entries
     */
    public function __construct(
        public readonly array $entries,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
        public readonly int $totalPages,
        public readonly ?string $visitDate,
        public readonly ?int $doctorId,
        public readonly string $status,
        public readonly ?string $search,
        public readonly string $today,
    ) {
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
        return 0 === $this->total ? 0 : ($this->page - 1) * $this->perPage + 1;
    }

    public function getLastItemNumber(): int
    {
        if (0 === $this->total) {
            return 0;
        }

        return min($this->total, ($this->page - 1) * $this->perPage + count($this->entries));
    }
}
