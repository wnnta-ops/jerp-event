<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Master\Customer\Enums\Citizenship;
use App\Domain\Master\Customer\Enums\CustomerStatus;
use App\Domain\Master\Customer\Enums\EmergencyRelationship;
use App\Domain\Master\Customer\Enums\IdType;
use App\Domain\Master\Customer\Enums\MaritalStatus;
use App\Domain\Master\Customer\Enums\Religion;
use App\Domain\Master\Customer\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        $idType = $this->faker->randomElement(IdType::cases());

        return [
            // 1. Identitas
            'tenant_id'      => 1,
            'code'           => 'CUST-'.$this->faker->unique()->numerify('######'),
            'full_name'      => $this->faker->name(),
            'nationality'    => Citizenship::WNI,

            // 2. Kartu Identitas
            'id_type'        => $idType,
            'id_number'      => $this->faker->numerify(str_repeat('#', $idType->minLength())),
            'id_expires_at'  => $idType === IdType::KTP ? null : $this->faker->dateTimeBetween('+1 year', '+5 years'),
            'id_lifetime'    => $idType === IdType::KTP,
            'address_line'   => $this->faker->streetAddress(),
            'address_rt_rw'  => $this->faker->numerify('0##/0##'),
            'kelurahan'      => $this->faker->city(),
            'kecamatan'      => $this->faker->city(),
            'city'           => $this->faker->city(),
            'province'       => $this->faker->state(),
            'postal_code'    => $this->faker->postcode(),
            'country'        => 'ID',

            // 3. Data Pribadi
            'dob'            => $this->faker->dateTimeBetween('-60 years', '-21 years'),
            'birth_place'    => $this->faker->city(),
            'gender'         => $this->faker->randomElement(['M', 'F']),
            'religion'       => $this->faker->randomElement(Religion::cases()),
            'marital_status' => $this->faker->randomElement(MaritalStatus::cases()),

            // 4. NPWP
            'npwp'              => $this->faker->numerify('##.###.###.#-###.###'),
            'npwp_name'         => $this->faker->name(),
            'npwp_same_as_ktp'  => true,

            // 5. Kontak
            'phone'          => '+62'.$this->faker->numerify('8##########'),
            'phone_alt'      => '+62'.$this->faker->numerify('8##########'),
            'home_phone'     => null,
            'email'          => $this->faker->safeEmail(),

            // 6. Surat Menyurat
            'mailing_same_as_ktp' => true,

            // 7. Pekerjaan
            'occupation'     => $this->faker->jobTitle(),
            'employer_name'  => $this->faker->company(),
            'job_title'      => $this->faker->jobTitle(),
            'monthly_income' => $this->faker->numberBetween(5_000_000, 50_000_000),

            // 8. Emergency Contact
            'emergency_name'         => $this->faker->name(),
            'emergency_relationship' => $this->faker->randomElement(EmergencyRelationship::cases()),
            'emergency_phone'        => '+62'.$this->faker->numerify('8##########'),

            // Misc
            'channel'        => $this->faker->randomElement(['walk-in', 'website', 'agent', 'event']),
            'status'         => CustomerStatus::Prospect,
        ];
    }

    public function active(): self
    {
        return $this->state(fn () => ['status' => CustomerStatus::Active]);
    }
}
