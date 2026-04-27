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
 * Two theme parks with full week of opening hours, two per-date hour
 * overrides each (one closed, one shortened-hours), and four park
 * activities per park (mix of all-day and timed). Timed activities get
 * six upcoming schedules within the park's effective hours.
 *
 * Schedule windows are written directly without invoking the validating
 * controller, so they intentionally stay inside the 09:00–17:00 baseline
 * to avoid cross-park-hours surprises.
 */
class ParkCatalogueSeeder extends Seeder
{
    public function run(): void
    {
        $parks = [
            [
                'name'          => 'Isla Nublar Theme Park',
                'description'   => 'The flagship park on Isla Nublar — wide-open paddocks and signature dinosaur encounters.',
                'capacity'      => 8000,
                'price'         => 95.00,
                'contact_email' => 'nublar@mesozoic.test',
                'contact_phone' => '+44 20 7000 0001',
            ],
            [
                'name'          => 'Sorna Wilds Park',
                'description'   => 'Sister park on Isla Sorna with an emphasis on guided walking trails through native habitat.',
                'capacity'      => 4000,
                'price'         => 70.00,
                'contact_email' => 'sorna@mesozoic.test',
                'contact_phone' => '+44 20 7000 0002',
            ],
        ];

        $activityBlueprints = [
            ['name' => 'T-Rex Paddock Tour',      'price' => 35.00, 'duration' => 60,  'max_capacity' => 30, 'is_all_day' => false],
            ['name' => 'Velociraptor Encounter',  'price' => 55.00, 'duration' => 45,  'max_capacity' => 12, 'is_all_day' => false],
            ['name' => 'Brachiosaurus Lookout',   'price' => 25.00, 'duration' => 30,  'max_capacity' => 40, 'is_all_day' => false],
            ['name' => 'Open-Range Day Pass',     'price' => 0.00,  'duration' => null, 'max_capacity' => 200, 'is_all_day' => true],
        ];

        // Six upcoming schedules per timed activity; all windows fit inside the
        // 09:00–17:00 baseline so they don't trip park-hours validation if any
        // downstream code re-checks them.
        $timedWindows = [
            ['offset' => 1,  'start' => '10:00:00', 'end' => '11:00:00'],
            ['offset' => 3,  'start' => '12:00:00', 'end' => '13:00:00'],
            ['offset' => 6,  'start' => '14:00:00', 'end' => '15:00:00'],
            ['offset' => 10, 'start' => '10:30:00', 'end' => '11:30:00'],
            ['offset' => 17, 'start' => '13:30:00', 'end' => '14:30:00'],
            ['offset' => 24, 'start' => '15:30:00', 'end' => '16:30:00'],
        ];

        $today = CarbonImmutable::today();

        foreach ($parks as $attrs) {
            $park = ThemePark::firstOrCreate(['name' => $attrs['name']], $attrs);

            // Baseline hours: open every day 09:00–17:00.
            foreach (ParkOpeningHour::DAYS as $day) {
                ParkOpeningHour::firstOrCreate(
                    ['park_id' => $park->id, 'day' => $day],
                    ['open_time' => '09:00:00', 'close_time' => '17:00:00'],
                );
            }

            // One closed-day override and one shortened-hours override.
            ParkHourOverride::updateOrCreate(
                ['park_id' => $park->id, 'date' => $today->addDays(7)->toDateString()],
                ['open_time' => null, 'close_time' => null, 'note' => 'Maintenance day — park closed.'],
            );
            ParkHourOverride::updateOrCreate(
                ['park_id' => $park->id, 'date' => $today->addDays(15)->toDateString()],
                ['open_time' => '11:00:00', 'close_time' => '15:00:00', 'note' => 'Shortened hours for staff training.'],
            );

            foreach ($activityBlueprints as $blueprint) {
                $activity = ParkActivity::firstOrCreate(
                    ['park_id' => $park->id, 'name' => $blueprint['name']],
                    [
                        'description'  => "{$blueprint['name']} at {$park->name}.",
                        'price'        => $blueprint['price'],
                        'duration'     => $blueprint['duration'],
                        'max_capacity' => $blueprint['max_capacity'],
                        'is_all_day'   => $blueprint['is_all_day'],
                    ],
                );

                if ($activity->is_all_day) {
                    // All-day activities materialise per-date schedules at booking
                    // time; manual schedules are rejected by the controller.
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
}
