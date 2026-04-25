<?php

use Illuminate\Support\Carbon;

test('app timezone is Indian/Maldives', function () {
    expect(config('app.timezone'))->toBe('Indian/Maldives');
});

test('now() runs in Maldives time and crosses the local-day boundary correctly', function () {
    // 18:30 UTC = 23:30 Maldives same day → today is unchanged
    Carbon::setTestNow(Carbon::parse('2026-06-01 18:30:00', 'UTC'));
    expect(now()->toDateString())->toBe('2026-06-01');
    expect(today()->toDateString())->toBe('2026-06-01');

    // 19:30 UTC = 00:30 Maldives next day → today rolls over
    Carbon::setTestNow(Carbon::parse('2026-06-01 19:30:00', 'UTC'));
    expect(now()->toDateString())->toBe('2026-06-02');
    expect(today()->toDateString())->toBe('2026-06-02');

    Carbon::setTestNow();
});
