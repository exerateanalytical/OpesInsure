<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reference data, not demo data — runs in every environment including
 * production, same as CanonicalEventSchemaSeeder/NotificationTemplateSeeder.
 *
 * Seeds a regulatory_reference_sets row (jurisdiction=CM, code=VEHICLE_MAKES)
 * directly as ACTIVE, bypassing RegulatoryConfigurationController's normal
 * DRAFT->approve() maker-checker flow: that flow is for staff-submitted
 * regulatory content that needs a second approver, not for a static list of
 * manufacturer names. A comprehensive list of the vehicle makes actually on
 * the road in Cameroon/CIMA markets and globally common ones — not a claim
 * that every manufacturer that has ever existed is included. Staff can add
 * a missing one later through the existing store()/approve() endpoints,
 * which will supersede this seeded version (max(version)+1, same as any
 * other regulatory reference set).
 */
final class VehicleMakeReferenceSeeder extends Seeder
{
    public function run(): void
    {
        $exists = DB::table('regulatory_reference_sets')
            ->where(['jurisdiction' => 'CM', 'code' => 'VEHICLE_MAKES'])
            ->exists();

        if ($exists) {
            return; // never overwrite a version staff may have already submitted/approved for this code
        }

        $entries = $this->makes();
        $canonical = json_encode($entries, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        DB::table('regulatory_reference_sets')->insert([
            'id' => (string) Str::uuid(),
            'jurisdiction' => 'CM',
            'code' => 'VEHICLE_MAKES',
            'version' => 1,
            'status' => 'ACTIVE',
            'effective_from' => now()->toDateString(),
            'effective_until' => null,
            'entries' => $canonical,
            'content_hash' => hash('sha256', $canonical),
            'approved_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array<int, array{code: string, name: string}> */
    private function makes(): array
    {
        $names = [
            // Japanese
            'Toyota', 'Honda', 'Nissan', 'Mazda', 'Mitsubishi', 'Suzuki', 'Subaru', 'Lexus', 'Infiniti', 'Acura',
            'Isuzu', 'Daihatsu', 'Datsun', 'Hino', 'Mitsubishi Fuso',
            // Korean
            'Hyundai', 'Kia', 'Genesis', 'SsangYong',
            // German
            'Volkswagen', 'BMW', 'Mercedes-Benz', 'Audi', 'Porsche', 'Opel', 'Mini', 'Smart', 'MAN',
            // French
            'Peugeot', 'Renault', 'Citroën', 'DS Automobiles', 'Dacia', 'Renault Trucks',
            // Italian
            'Fiat', 'Alfa Romeo', 'Lancia', 'Ferrari', 'Lamborghini', 'Maserati', 'Iveco',
            // Swedish
            'Volvo', 'Saab',
            // American
            'Ford', 'Chevrolet', 'GMC', 'Cadillac', 'Jeep', 'Chrysler', 'Dodge', 'RAM', 'Lincoln', 'Tesla', 'Buick', 'Hummer',
            // British
            'Land Rover', 'Jaguar', 'Bentley', 'Rolls-Royce', 'Aston Martin', 'MG', 'Vauxhall', 'Rover', 'McLaren',
            // Czech / Spanish
            'Škoda', 'Seat',
            // Chinese
            'BYD', 'Geely', 'Great Wall', 'Haval', 'Changan', 'Chery', 'JAC', 'Foton', 'Dongfeng', 'GAC', 'BAIC', 'FAW', 'Wuling', 'JMC',
            // Indian
            'Tata', 'Mahindra',
            // Other / commercial-relevant in this market
            'Lada', 'UAZ', 'Proton', 'Scania', 'DAF',
        ];

        return array_map(fn (string $name) => [
            'code' => Str::of($name)->ascii()->upper()->replace(['-', ' '], '_')->toString(),
            'name' => $name,
        ], $names);
    }
}
