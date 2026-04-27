<?php

namespace Database\Seeders;

use App\Models\Ferry;
use App\Models\FerrySchedule;
use App\Models\FerryType;
use Illuminate\Database\Seeder;

/**
 * Two ferry types (Standard / Express), two vessels per type, and a handful
 * of fixed time-slot schedules per vessel covering common port pairs and
 * one overnight crossing.
 *
 * FerrySchedules are date-less (slots applied per booking via travel_date),
 * so seeding a small number per ferry is enough to exercise the booking
 * flow on any future date.
 */
class FerryCatalogueSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            [
                'attrs' => [
                    'name'        => 'Standard Ferry',
                    'description' => 'Two-deck passenger ferry with standard seating.',
                    'capacity'    => 120,
                    'price'       => 45.00,
                ],
                'ferries' => ['Mesozoic Express 001', 'Mesozoic Express 002'],
            ],
            [
                'attrs' => [
                    'name'        => 'Express Catamaran',
                    'description' => 'High-speed catamaran with reserved seating and onboard cafe.',
                    'capacity'    => 80,
                    'price'       => 85.00,
                ],
                'ferries' => ['Mesozoic Express 003', 'Mesozoic Express 004'],
            ],
        ];

        // Five fixed slots per vessel. The last is an overnight crossing
        // (arrival_time < departure_time wraps to next day at read).
        $slots = [
            ['departure' => '07:00:00', 'arrival' => '09:30:00', 'from' => 'Mainland Harbour', 'to' => 'Mesozoic Isle'],
            ['departure' => '11:00:00', 'arrival' => '13:30:00', 'from' => 'Mesozoic Isle',    'to' => 'Mainland Harbour'],
            ['departure' => '14:00:00', 'arrival' => '16:30:00', 'from' => 'Mainland Harbour', 'to' => 'Mesozoic Isle'],
            ['departure' => '17:00:00', 'arrival' => '19:30:00', 'from' => 'Mesozoic Isle',    'to' => 'Mainland Harbour'],
            ['departure' => '22:00:00', 'arrival' => '02:30:00', 'from' => 'Mainland Harbour', 'to' => 'Mesozoic Isle'], // overnight
        ];

        foreach ($types as $type) {
            $ferryType = FerryType::firstOrCreate(['name' => $type['attrs']['name']], $type['attrs']);

            foreach ($type['ferries'] as $vesselName) {
                $ferry = Ferry::firstOrCreate(
                    ['name' => $vesselName],
                    ['ferry_type_id' => $ferryType->id],
                );

                foreach ($slots as $slot) {
                    FerrySchedule::updateOrCreate(
                        [
                            'ferry_id'       => $ferry->id,
                            'departure_time' => $slot['departure'],
                        ],
                        [
                            'arrival_time'   => $slot['arrival'],
                            'departure_port' => $slot['from'],
                            'arrival_port'   => $slot['to'],
                        ],
                    );
                }
            }
        }
    }
}
