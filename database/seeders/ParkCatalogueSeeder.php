<?php

namespace Database\Seeders;

use App\Models\ParkActivity;
use App\Models\ParkActivitySchedule;
use App\Models\ParkHourOverride;
use App\Models\ParkOpeningHour;
use App\Models\ThemePark;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Seeds the single "Mesozoic Kingdom" theme park with a full week of
 * baseline opening hours, two per-date hour overrides (one closed, one
 * shortened), a creative roster of dinosaur-themed activities, and six
 * upcoming schedules per timed activity.
 *
 * The all-day pass is "Mesozoic Aquarium Day Pass" — drop-in access to a
 * prehistoric marine reptile aquarium (mosasaurs, plesiosaurs,
 * ichthyosaurs). All-day activities don't carry manual schedules; the
 * booking flow materialises a per-date schedule on first booking.
 *
 * Schedule windows are written directly to the DB (bypassing the
 * controller's ParkScheduleReconciler), so they intentionally stay inside
 * the 09:00–17:00 baseline.
 */
class ParkCatalogueSeeder extends Seeder
{
    public function run(): void
    {
        $park = ThemePark::firstOrCreate(
            ['name' => 'Mesozoic Kingdom'],
            [
                'description'   => 'The flagship Mesozoic Kingdom park — paddocks, walking trails, hatchery labs, and a prehistoric marine aquarium under one gate.',
                'capacity'      => 8000,
                'price'         => 95.00,
                'contact_email' => 'support@mesozoic.test',
                'contact_phone' => '+44 20 7000 0001',
            ],
        );

        // Baseline hours: open every day 09:00–17:00.
        foreach (ParkOpeningHour::DAYS as $day) {
            ParkOpeningHour::firstOrCreate(
                ['park_id' => $park->id, 'day' => $day],
                ['open_time' => '09:00:00', 'close_time' => '17:00:00'],
            );
        }

        $today = CarbonImmutable::today();

        // One closed-day override and one shortened-hours override.
        ParkHourOverride::updateOrCreate(
            ['park_id' => $park->id, 'date' => $today->addDays(7)->toDateString()],
            ['open_time' => null, 'close_time' => null, 'note' => 'Maintenance day — park closed.'],
        );
        ParkHourOverride::updateOrCreate(
            ['park_id' => $park->id, 'date' => $today->addDays(15)->toDateString()],
            ['open_time' => '11:00:00', 'close_time' => '15:00:00', 'note' => 'Shortened hours for staff training.'],
        );

        $activityBlueprints = [
            [
                'name' => 'Tyrannosaur Feeding Show',
                'description' => 'Watch the apex predator feed from the safety of the elevated viewing gallery. Loud — not recommended for under-5s.',
                'price' => 45.00, 'duration' => 45, 'max_capacity' => 80, 'is_all_day' => false,
            ],
            [
                'name' => 'Velociraptor Pack Encounter',
                'description' => 'A small-group, glass-walled encounter with the resident raptor pack and their handlers.',
                'price' => 65.00, 'duration' => 45, 'max_capacity' => 12, 'is_all_day' => false,
            ],
            [
                'name' => 'Pterosaur Aviary Flight Demo',
                'description' => 'Open-air demo of pterosaurs in flight, with a falconry-style commentary from the keepers.',
                'price' => 30.00, 'duration' => 30, 'max_capacity' => 60, 'is_all_day' => false,
            ],
            [
                'name' => 'Triceratops Petting Paddock',
                'description' => 'Hands-on session with juvenile triceratops in the petting paddock. Includes wash station after.',
                'price' => 25.00, 'duration' => 60, 'max_capacity' => 20, 'is_all_day' => false,
            ],
            [
                'name' => 'Hatchery Lab Tour',
                'description' => 'Behind-the-scenes tour of the hatchery — eggs, incubators, and recently-hatched juveniles.',
                'price' => 35.00, 'duration' => 60, 'max_capacity' => 16, 'is_all_day' => false,
            ],
            [
                'name' => 'Mesozoic Aquarium Day Pass',
                'description' => 'All-day drop-in access to the Mesozoic Aquarium — mosasaurs, plesiosaurs, ichthyosaurs, and the deep-tank ammonite gallery.',
                'price' => 40.00, 'duration' => null, 'max_capacity' => 300, 'is_all_day' => true,
            ],
        ];

        // Six upcoming windows per timed activity, all inside the 09:00–17:00 baseline.
        $timedWindows = [
            ['offset' => 1,  'start' => '10:00:00', 'end' => '11:00:00'],
            ['offset' => 3,  'start' => '12:00:00', 'end' => '13:00:00'],
            ['offset' => 6,  'start' => '14:00:00', 'end' => '15:00:00'],
            ['offset' => 10, 'start' => '10:30:00', 'end' => '11:30:00'],
            ['offset' => 17, 'start' => '13:30:00', 'end' => '14:30:00'],
            ['offset' => 24, 'start' => '15:30:00', 'end' => '16:30:00'],
        ];

        foreach ($activityBlueprints as $blueprint) {
            $activity = ParkActivity::firstOrCreate(
                ['park_id' => $park->id, 'name' => $blueprint['name']],
                [
                    'description'  => $blueprint['description'],
                    'price'        => $blueprint['price'],
                    'duration'     => $blueprint['duration'],
                    'max_capacity' => $blueprint['max_capacity'],
                    'is_all_day'   => $blueprint['is_all_day'],
                ],
            );

            if ($activity->is_all_day) {
                continue;
            }

            foreach ($timedWindows as $w) {
                $date = $today->addDays($w['offset'])->toDateString();
                ParkActivitySchedule::updateOrCreate(
                    [
                        'park_activity_id' => $activity->id,
                        'date'             => $date,
                        'start_time'       => $w['start'],
                    ],
                    [
                        'end_time' => $w['end'],
                        'status'   => ParkActivitySchedule::STATUS_SCHEDULED,
                    ],
                );
            }
        }
    }
}
