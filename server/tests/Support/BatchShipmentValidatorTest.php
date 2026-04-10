<?php

use Fleetbase\FleetOps\Support\BatchShipmentValidator;

// ── resolveRowId ─────────────────────────────────────────────────────────

test('resolveRowId returns caller row_id when present', function () {
    expect(BatchShipmentValidator::resolveRowId(['row_id' => 'my-row'], 0))->toBe('my-row');
});

test('resolveRowId generates row_N when row_id is absent', function () {
    expect(BatchShipmentValidator::resolveRowId([], 3))->toBe('row_3');
});

test('resolveRowId generates row_N when row_id is empty string', function () {
    expect(BatchShipmentValidator::resolveRowId(['row_id' => ''], 5))->toBe('row_5');
});

// ── validateRatesInput ───────────────────────────────────────────────────

test('valid row passes through with all fields normalized', function () {
    [$valid, $errors] = BatchShipmentValidator::validateRatesInput([
        [
            'row_id'       => 'r1',
            'pickup'       => 'place_abc',
            'dropoff'      => 'place_xyz',
            'parcels'      => [['length' => 10, 'width' => 10, 'height' => 10, 'weight' => 5]],
            'facilitator'  => 'integrated_vendor_pp',
            'service_type' => 'parcel',
        ],
    ]);

    expect($errors)->toBe([]);
    expect($valid)->toHaveCount(1);
    expect($valid[0]['row_id'])->toBe('r1');
    expect($valid[0]['pickup'])->toBe('place_abc');
    expect($valid[0]['facilitator'])->toBe('integrated_vendor_pp');
});

test('missing pickup produces a per-row error', function () {
    [$valid, $errors] = BatchShipmentValidator::validateRatesInput([
        ['row_id' => 'bad1', 'dropoff' => 'place_xyz', 'parcels' => [['weight' => 1]]],
    ]);
    expect($valid)->toBe([]);
    expect($errors)->toHaveCount(1);
    expect($errors[0]['row_id'])->toBe('bad1');
    expect($errors[0]['error'])->toContain('pickup');
});

test('missing dropoff produces a per-row error', function () {
    [$valid, $errors] = BatchShipmentValidator::validateRatesInput([
        ['row_id' => 'bad2', 'pickup' => 'place_abc', 'parcels' => [['weight' => 1]]],
    ]);
    expect($errors[0]['error'])->toContain('dropoff');
});

test('missing parcels produces a per-row error', function () {
    [$valid, $errors] = BatchShipmentValidator::validateRatesInput([
        ['pickup' => 'a', 'dropoff' => 'b'],
    ]);
    expect($errors[0]['error'])->toContain('parcels');
});

test('empty parcels array produces a per-row error', function () {
    [$valid, $errors] = BatchShipmentValidator::validateRatesInput([
        ['pickup' => 'a', 'dropoff' => 'b', 'parcels' => []],
    ]);
    expect($errors[0]['error'])->toContain('parcels');
});

test('mixed valid and invalid rows are separated correctly', function () {
    [$valid, $errors] = BatchShipmentValidator::validateRatesInput([
        ['row_id' => 'good', 'pickup' => 'a', 'dropoff' => 'b', 'parcels' => [['weight' => 1]]],
        ['row_id' => 'bad',  'pickup' => 'a'],
    ]);
    expect($valid)->toHaveCount(1);
    expect($valid[0]['row_id'])->toBe('good');
    expect($errors)->toHaveCount(1);
    expect($errors[0]['row_id'])->toBe('bad');
});

test('auto-generates row_id when not provided', function () {
    [$valid, $errors] = BatchShipmentValidator::validateRatesInput([
        ['pickup' => 'a', 'dropoff' => 'b', 'parcels' => [['weight' => 1]]],
        ['pickup' => 'c', 'dropoff' => 'd', 'parcels' => [['weight' => 2]]],
    ]);
    expect($valid[0]['row_id'])->toBe('row_0');
    expect($valid[1]['row_id'])->toBe('row_1');
});

test('optional fields default to null when absent', function () {
    [$valid, $errors] = BatchShipmentValidator::validateRatesInput([
        ['pickup' => 'a', 'dropoff' => 'b', 'parcels' => [['weight' => 1]]],
    ]);
    expect($valid[0]['facilitator'])->toBeNull();
    expect($valid[0]['service_type'])->toBeNull();
});

// ── validatePurchaseInput ────────────────────────────────────────────────

test('valid purchase row passes through', function () {
    [$valid, $errors] = BatchShipmentValidator::validatePurchaseInput([
        ['row_id' => 'p1', 'service_quote_uuid' => 'sq_abc', 'order_uuid' => 'order_xyz'],
    ]);
    expect($errors)->toBe([]);
    expect($valid)->toHaveCount(1);
    expect($valid[0]['service_quote_uuid'])->toBe('sq_abc');
    expect($valid[0]['order_uuid'])->toBe('order_xyz');
});

test('missing service_quote_uuid produces a per-row error', function () {
    [$valid, $errors] = BatchShipmentValidator::validatePurchaseInput([
        ['row_id' => 'bad'],
    ]);
    expect($errors[0]['error'])->toContain('service_quote_uuid');
});

test('order_uuid defaults to null when absent', function () {
    [$valid, $errors] = BatchShipmentValidator::validatePurchaseInput([
        ['service_quote_uuid' => 'sq_abc'],
    ]);
    expect($valid[0]['order_uuid'])->toBeNull();
});

test('auto-generates row_id for purchase rows', function () {
    [$valid, $errors] = BatchShipmentValidator::validatePurchaseInput([
        ['service_quote_uuid' => 'sq_1'],
        ['service_quote_uuid' => 'sq_2'],
    ]);
    expect($valid[0]['row_id'])->toBe('row_0');
    expect($valid[1]['row_id'])->toBe('row_1');
});
