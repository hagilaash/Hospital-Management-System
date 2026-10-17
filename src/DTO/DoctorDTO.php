<?php

namespace App\DTO;

class DoctorDTO
{
    public function __construct(
        private int $doctorId,
        private string $doctorName,
        private string $specialization,
        private ?string $department,
        private ?string $phoneNo,
        private ?string $email,
        private ?string $qualification,
        private ?string $gender,
        private ?string $dateOfBirth,
        private ?string $createdAt,
        private ?string $updatedAt,
        private ?string $isActive,
    ) {
    }

    public function getDoctorId(): int
    {
        return $this->doctorId;
    }

    public function getDoctorName(): string
    {
        return $this->doctorName;
    }

    public function getSpecialization(): string
    {
        return $this->specialization;
    }

    public function getDepartment(): ?string
    {
        return $this->department;
    }

    public function getPhoneNo(): ?string
    {
        return $this->phoneNo;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getQualification(): ?string
    {
        return $this->qualification;
    }

    public function getGender(): ?string
    {
        return $this->gender;
    }

    public function getDateOfBirth(): ?string
    {
        return $this->dateOfBirth;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }

    public function getIsActive(): ?string
    {
        return $this->isActive;
    }
}
