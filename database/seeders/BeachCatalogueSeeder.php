<?php

namespace Database\Seeders;

use App\Models\BeachActivity;
use App\Models\BeachActivitySchedule;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Three beach activities and a rolling 30-day grid of upcoming schedules
 * (six per activity, including one overnight crossing) so the booking flow
 * has plenty of upcoming sessions to choose from.
 *
 * Idempotent via updateOrCreate keyed on the (activity, date, start_time)
 * partial-unique slot.
 */
class BeachCatalogueSeeder extends Seeder
{
    public function run(): void
    {
        $activities = [
            [
                'name'        => 'Snorkelling Adventure',
                'description' => 'Guided reef snorkelling along the eastern lagoon.',
                'price'       => 45.00,
                'capacity'    => 12,
                'duration'    => 90,
            ],
            [
                'name'        => 'Sea Kayaking',
                'description' => 'Two-person kayaks for a paddle around the bay islets.',
                'price'       => 35.00,
                'capacity'    => 16,
                'duration'    => 120,
            ],
            [
                'name'        => 'Sunset Cruise',
                'description' => 'Sunset cruise around the coastline with light refreshments. Includes a late-night return.',
                'price'       => 80.00,
                'capacity'    => 24,
                'duration'    => 150,
            ],
        ];

        // Five same-day windows + one overnight window (22:00 → 02:00 next day).
        // Indexed parallel to the activities array so the seeder is deterministic.
        $windows = [
            ['offset' => 2,  'start' => '09:00:00', 'end' => '10:30:00'],
            ['offset' => 5,  'start' => '11:00:00', 'end' => '13:00:00'],
            ['offset' => 9,  'start' => '14:00:00', 'end' => '15:30:00'],
            ['offset' => 14, 'start' => '10:30:00', 'end' => '12:30:00'],
            ['offset' => 21, 'start' => '15:00:00', 'end' => '17:30:00'],
            ['offset' => 28, 'start' => '22:00:00', 'end' => '02:00:00'], // overnight
        ];

        $today = CarbonImmutable::today();

        foreach ($activities as $attrs) {
            $activity = BeachActivity::firstOrCreate(['name' => $attrs['name']], $attrs);

            foreach ($windows as $w) {
                $date = $today->addDays($w['offset'])->toDateString();
                BeachActivitySchedule::updateOrCreate(
                    [
                        'beach_activity_id' => $activity->id,
                        'activity_date'     => $date,
                        'start_time'        => $w['start'],
                    ],
                    [
                        'end_time' => $w['end'],
                        'status'   => BeachActivitySchedule::STATUS_CONFIRMED,
                    ],
                );
            }
        }
    }
}
