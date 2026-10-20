<?php

namespace App\DTO;

/**
 * One page of the appointment list plus the filters that produced it and the
 * hospital's "today", which the template needs for the check-in and no-show
 * buttons. Immutable: FDMService builds it once.
 */
class AppointmentListDTO
{
    /**
     * @param list<AppointmentDTO> $appointments
     */
    public function __construct(
        public readonly array $appointments,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
        public readonly int $totalPages,
        public readonly ?string $dateFrom,
        public readonly ?string $dateTo,
        public readonly ?int $doctorId,
        public readonly ?int $patientId,
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

        return min($this->total, ($this->page - 1) * $this->perPage + count($this->appointments));
    }
}
