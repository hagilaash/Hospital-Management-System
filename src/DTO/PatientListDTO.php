<?php

namespace App\DTO;

/**
 * One page of the patient list plus everything the template needs to draw the
 * search box, the status filter and the pager. Immutable: PatientService
 * builds it once and the controller only passes it to Twig.
 */
class PatientListDTO
{
    /**
     * @param list<PatientDTO> $patients
     */
    public function __construct(
        public readonly array $patients,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
        public readonly int $totalPages,
        public readonly ?string $search,
        public readonly string $status,
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

    /** Number of the first patient on this page ("Showing 11-20 of 45"), 0 when empty. */
    public function getFirstItemNumber(): int
    {
        return 0 === $this->total ? 0 : ($this->page - 1) * $this->perPage + 1;
    }

    /** Number of the last patient on this page, 0 when empty. */
    public function getLastItemNumber(): int
    {
        if (0 === $this->total) {
            return 0;
        }

        return min($this->total, ($this->page - 1) * $this->perPage + count($this->patients));
    }
}
