<?php

use App\Models\BeachActivity;
use App\Models\BeachActivitySchedule;
use App\Services\BeachScheduleReconciler;

function beachReconciler(): BeachScheduleReconciler
{
    return app(BeachScheduleReconciler::class);
}

test('findOverlappingSchedule returns null when there is no overlap', function () {
    $activity = BeachActivity::factory()->create();
    $activity->schedules()->create([
        'activity_date' => '2026-06-01',
        'start_time' => '10:00:00',
        'end_time' => '11:00:00',
    ]);

    $result = beachReconciler()->findOverlappingSchedule($activity, '2026-06-01', '11:00:00', '12:00:00');
    expect($result)->toBeNull();
});

test('findOverlappingSchedule detects partial overlap', function () {
    $activity = BeachActivity::factory()->create();
    $existing = $activity->schedules()->create([
        'activity_date' => '2026-06-01',
        'start_time' => '10:00:00',
        'end_time' => '11:00:00',
    ]);

    $result = beachReconciler()->findOverlappingSchedule($activity, '2026-06-01', '10:30:00', '11:30:00');
    expect($result?->id)->toBe($existing->id);
});

test('findOverlappingSchedule detects full containment', function () {
    $activity = BeachActivity::factory()->create();
    $existing = $activity->schedules()->create([
        'activity_date' => '2026-06-01',
        'start_time' => '09:00:00',
        'end_time' => '12:00:00',
    ]);

    $result = beachReconciler()->findOverlappingSchedule($activity, '2026-06-01', '10:00:00', '11:00:00');
    expect($result?->id)->toBe($existing->id);
});

test('findOverlappingSchedule treats end-edge touch as no overlap (half-open)', function () {
    $activity = BeachActivity::factory()->create();
    $activity->schedules()->create([
        'activity_date' => '2026-06-01',
        'start_time' => '09:00:00',
        'end_time' => '10:00:00',
    ]);

    $result = beachReconciler()->findOverlappingSchedule($activity, '2026-06-01', '10:00:00', '11:00:00');
    expect($result)->toBeNull();
});

test('findOverlappingSchedule ignores cancelled schedules', function () {
    $activity = BeachActivity::factory()->create();
    $activity->schedules()->create([
        'activity_date' => '2026-06-01',
        'start_time' => '10:00:00',
        'end_time' => '11:00:00',
        'status' => BeachActivitySchedule::STATUS_CANCELLED,
    ]);

    $result = beachReconciler()->findOverlappingSchedule($activity, '2026-06-01', '10:30:00', '11:30:00');
    expect($result)->toBeNull();
});

test('findOverlappingSchedule respects ignoreId', function () {
    $activity = BeachActivity::factory()->create();
    $existing = $activity->schedules()->create([
        'activity_date' => '2026-06-01',
        'start_time' => '10:00:00',
        'end_time' => '11:00:00',
    ]);

    $result = beachReconciler()->findOverlappingSchedule(
        $activity,
        '2026-06-01',
        '10:30:00',
        '11:30:00',
        $existing->id,
    );
    expect($result)->toBeNull();
});

test('findOverlappingSchedule handles overnight windows on the same date', function () {
    $activity = BeachActivity::factory()->create();
    $existing = $activity->schedules()->create([
        'activity_date' => '2026-06-01',
        'start_time' => '22:00:00',
        'end_time' => '02:00:00',
    ]);

    // Proposed 23:00–01:00 lives entirely inside the wrapped 22:00–02:00 window.
    $result = beachReconciler()->findOverlappingSchedule($activity, '2026-06-01', '23:00:00', '01:00:00');
    expect($result?->id)->toBe($existing->id);
});

test('findOverlappingSchedule detects D-1 overnight overlap with a D early-morning schedule', function () {
    $activity = BeachActivity::factory()->create();
    $overnight = $activity->schedules()->create([
        'activity_date' => '2026-06-01',
        'start_time' => '22:00:00',
        'end_time' => '02:00:00',
    ]);

    // Proposed 01:00–03:00 on D=2026-06-02 starts inside the overnight wrap
    // (which ends at 2026-06-02 02:00). Should be detected.
    $result = beachReconciler()->findOverlappingSchedule($activity, '2026-06-02', '01:00:00', '03:00:00');
    expect($result?->id)->toBe($overnight->id);
});

test('findOverlappingSchedule scoped to activity (other activities ignored)', function () {
    $activityA = BeachActivity::factory()->create();
    $activityB = BeachActivity::factory()->create();
    $activityB->schedules()->create([
        'activity_date' => '2026-06-01',
        'start_time' => '10:00:00',
        'end_time' => '11:00:00',
    ]);

    $result = beachReconciler()->findOverlappingSchedule($activityA, '2026-06-01', '10:30:00', '11:30:00');
    expect($result)->toBeNull();
});
