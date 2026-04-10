<?php

use Fleetbase\FleetOps\Support\WebhookPayloadNormalizer;

// ── isValidProvider ──────────────────────────────────────────────────────

test('isValidProvider returns true for parcelpath, ups, usps', function () {
    expect(WebhookPayloadNormalizer::isValidProvider('parcelpath'))->toBeTrue();
    expect(WebhookPayloadNormalizer::isValidProvider('ups'))->toBeTrue();
    expect(WebhookPayloadNormalizer::isValidProvider('usps'))->toBeTrue();
});

test('isValidProvider is case-insensitive (lowercases before check)', function () {
    expect(WebhookPayloadNormalizer::isValidProvider('ParcelPath'))->toBeTrue();
    expect(WebhookPayloadNormalizer::isValidProvider('UPS'))->toBeTrue();
});

test('isValidProvider returns false for unknown providers', function () {
    expect(WebhookPayloadNormalizer::isValidProvider('fedex'))->toBeFalse();
    expect(WebhookPayloadNormalizer::isValidProvider(''))->toBeFalse();
});

// ── ParcelPath normalization ─────────────────────────────────────────────

test('normalizeParcelPath returns events with tracking_number and uppercased codes', function () {
    $events = WebhookPayloadNormalizer::normalize('parcelpath', [
        'tracking_number' => '1Z999',
        'carrier' => 'UPS',
        'events' => [
            ['code' => 'IN_TRANSIT', 'status' => 'In Transit', 'timestamp' => '2026-04-07T10:00:00Z', 'location' => 'Chicago'],
            ['code' => 'DELIVERED', 'status' => 'Delivered', 'timestamp' => '2026-04-09T14:22:00Z'],
        ],
    ]);

    expect($events)->toHaveCount(2);
    expect($events[0]['tracking_number'])->toBe('1Z999');
    expect($events[0]['code'])->toBe('IN_TRANSIT');
    expect($events[0]['location'])->toBe('Chicago');
    expect($events[1]['code'])->toBe('DELIVERED');
});

test('normalizeParcelPath returns empty for missing tracking_number', function () {
    expect(WebhookPayloadNormalizer::normalize('parcelpath', ['events' => [['code' => 'X']]]))->toBe([]);
});

test('normalizeParcelPath skips events with no code', function () {
    $events = WebhookPayloadNormalizer::normalize('parcelpath', [
        'tracking_number' => '1Z',
        'events' => [
            ['code' => 'DELIVERED'],
            ['location' => 'somewhere'], // no code
        ],
    ]);
    expect($events)->toHaveCount(1);
});

// ── UPS normalization ────────────────────────────────────────────────────

test('normalizeUps single-event payload maps activity code via upsActivityCodeToFleetbaseCode', function () {
    $events = WebhookPayloadNormalizer::normalize('ups', [
        'trackingNumber' => '1Z999',
        'eventType' => 'D',
        'eventDescription' => 'Delivered',
        'eventTimestamp' => '2026-04-09T14:22:00',
        'eventCity' => 'New York',
        'eventState' => 'NY',
    ]);

    expect($events)->toHaveCount(1);
    expect($events[0]['tracking_number'])->toBe('1Z999');
    expect($events[0]['code'])->toBe('DELIVERED');
    expect($events[0]['status'])->toBe('Delivered');
    expect($events[0]['location'])->toBe('New York, NY');
});

test('normalizeUps maps RS to RETURN_TO_SENDER', function () {
    $events = WebhookPayloadNormalizer::normalize('ups', [
        'trackingNumber' => '1Z',
        'eventType' => 'RS',
        'eventDescription' => 'Returned',
    ]);
    expect($events[0]['code'])->toBe('RETURN_TO_SENDER');
});

test('normalizeUps multi-event payload processes all events', function () {
    $events = WebhookPayloadNormalizer::normalize('ups', [
        'trackingNumber' => '1Z999',
        'events' => [
            ['eventType' => 'I', 'eventTimestamp' => 't1'],
            ['eventType' => 'O', 'eventTimestamp' => 't2'],
            ['eventType' => 'D', 'eventTimestamp' => 't3'],
        ],
    ]);
    expect($events)->toHaveCount(3);
    expect($events[0]['code'])->toBe('IN_TRANSIT');
    expect($events[2]['code'])->toBe('DELIVERED');
});

test('normalizeUps returns empty for missing trackingNumber', function () {
    expect(WebhookPayloadNormalizer::normalize('ups', ['eventType' => 'D']))->toBe([]);
});

// ── USPS normalization ───────────────────────────────────────────────────

test('normalizeUsps single-event maps ALERT to EXCEPTION', function () {
    $events = WebhookPayloadNormalizer::normalize('usps', [
        'trackingNumber' => '9400111',
        'eventType' => 'ALERT',
        'eventTimestamp' => '2026-04-09T10:00:00',
        'eventCity' => 'Boise',
    ]);

    expect($events)->toHaveCount(1);
    expect($events[0]['code'])->toBe('EXCEPTION');
    expect($events[0]['location'])->toBe('Boise');
});

test('normalizeUsps DELIVERED passes through verbatim', function () {
    $events = WebhookPayloadNormalizer::normalize('usps', [
        'trackingNumber' => '9400111',
        'eventType' => 'DELIVERED',
    ]);
    expect($events[0]['code'])->toBe('DELIVERED');
});

test('normalizeUsps returns empty for missing trackingNumber', function () {
    expect(WebhookPayloadNormalizer::normalize('usps', ['eventType' => 'DELIVERED']))->toBe([]);
});

// ── dedupKey ─────────────────────────────────────────────────────────────

test('dedupKey returns composite of tracking_number_uuid + code + timestamp', function () {
    $key = WebhookPayloadNormalizer::dedupKey('tn-uuid-123', [
        'code' => 'DELIVERED',
        'timestamp' => '2026-04-09T14:22:00',
    ]);

    expect($key)->toBe([
        'tracking_number_uuid' => 'tn-uuid-123',
        'code' => 'DELIVERED',
        'created_at' => '2026-04-09T14:22:00',
    ]);
});

test('dedupKey uses now() placeholder when timestamp is missing', function () {
    $key = WebhookPayloadNormalizer::dedupKey('tn-uuid', ['code' => 'X']);
    expect($key['tracking_number_uuid'])->toBe('tn-uuid');
    expect($key['code'])->toBe('X');
    // created_at is set to now() — just verify it's a non-empty string
    expect($key['created_at'])->not->toBe('');
});

// ── unknown provider ─────────────────────────────────────────────────────

test('normalize returns empty for unknown provider', function () {
    expect(WebhookPayloadNormalizer::normalize('fedex', ['tracking_number' => '123']))->toBe([]);
});
