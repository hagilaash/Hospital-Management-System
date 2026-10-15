<?php

namespace App\DTO;

/**
 * One page of the doctor list plus everything the template needs to draw the
 * search box, the status filter and the pager. Immutable: DoctorService
 * builds it once and the controller only passes it to Twig.
 */
class DoctorDTO
{
    /**
     * @param list<DoctorDTO> $doctors
     */
    public function __construct(
        public readonly array $doctors,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
        public readonly int $totalPages,
        public readonly ?string $search,
        public readonly string $status
    ) {
    }

    
}