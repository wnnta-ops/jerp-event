<?php

declare(strict_types=1);

namespace App\Domain\Master\Customer\DTOs;

use App\Domain\Master\Customer\Enums\IdType;

final readonly class CustomerDto
{
    public function __construct(
        public string $fullName,
        public IdType $idType,
        public string $idNumber,
        public ?string $dob = null,
        public ?string $birthPlace = null,
        public ?string $gender = null,
        public ?string $maritalStatus = null,
        public ?string $phone = null,
        public ?string $phoneAlt = null,
        public ?string $email = null,
        public ?string $addressLine = null,
        public ?string $city = null,
        public ?string $province = null,
        public ?string $postalCode = null,
        public ?string $npwp = null,
        public ?string $occupation = null,
        public ?string $employerName = null,
        public ?float $monthlyIncome = null,
        public ?string $channel = null,
        public ?int $referrerAgentId = null,
    ) {}

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            fullName:        $payload['full_name'],
            idType:          IdType::from($payload['id_type']),
            idNumber:        $payload['id_number'],
            dob:             $payload['dob']             ?? null,
            birthPlace:      $payload['birth_place']     ?? null,
            gender:          $payload['gender']          ?? null,
            maritalStatus:   $payload['marital_status'] ?? null,
            phone:           $payload['phone']           ?? null,
            phoneAlt:        $payload['phone_alt']       ?? null,
            email:           $payload['email']           ?? null,
            addressLine:     $payload['address_line']    ?? $payload['address'] ?? null,
            city:            $payload['city']            ?? null,
            province:        $payload['province']        ?? null,
            postalCode:      $payload['postal_code']     ?? null,
            npwp:            $payload['npwp']            ?? null,
            occupation:      $payload['occupation']      ?? null,
            employerName:    $payload['employer_name']   ?? null,
            monthlyIncome:   isset($payload['monthly_income']) ? (float) $payload['monthly_income'] : null,
            channel:         $payload['channel']         ?? null,
            referrerAgentId: isset($payload['referrer_agent_id']) ? (int) $payload['referrer_agent_id'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'full_name'         => $this->fullName,
            'id_type'           => $this->idType->value,
            'id_number'         => $this->idNumber,
            'dob'               => $this->dob,
            'birth_place'       => $this->birthPlace,
            'gender'            => $this->gender,
            'marital_status'    => $this->maritalStatus,
            'phone'             => $this->phone,
            'phone_alt'         => $this->phoneAlt,
            'email'             => $this->email,
            'address_line'      => $this->addressLine,
            'city'              => $this->city,
            'province'          => $this->province,
            'postal_code'       => $this->postalCode,
            'npwp'              => $this->npwp,
            'occupation'        => $this->occupation,
            'employer_name'     => $this->employerName,
            'monthly_income'    => $this->monthlyIncome,
            'channel'           => $this->channel,
            'referrer_agent_id' => $this->referrerAgentId,
        ];
    }
}
