<?php

use Fleetbase\FleetOps\Support\FacilitatorInputParser;

// ── parseFacilitatorInput ────────────────────────────────────────────────

test('single facilitator string returns one-element array', function () {
    expect(FacilitatorInputParser::parse('integrated_vendor_abc'))
        ->toBe(['integrated_vendor_abc']);
});

test('comma-separated facilitators are split into an array', function () {
    expect(FacilitatorInputParser::parse('integrated_vendor_a,integrated_vendor_b'))
        ->toBe(['integrated_vendor_a', 'integrated_vendor_b']);
});

test('comma-separated with spaces are trimmed', function () {
    expect(FacilitatorInputParser::parse('integrated_vendor_a , integrated_vendor_b , integrated_vendor_c'))
        ->toBe(['integrated_vendor_a', 'integrated_vendor_b', 'integrated_vendor_c']);
});

test('array input passes through', function () {
    expect(FacilitatorInputParser::parse(['integrated_vendor_a', 'integrated_vendor_b']))
        ->toBe(['integrated_vendor_a', 'integrated_vendor_b']);
});

test('duplicates are removed', function () {
    expect(FacilitatorInputParser::parse('integrated_vendor_a,integrated_vendor_b,integrated_vendor_a'))
        ->toBe(['integrated_vendor_a', 'integrated_vendor_b']);
});

test('duplicates in array input are removed', function () {
    expect(FacilitatorInputParser::parse(['integrated_vendor_x', 'integrated_vendor_x', 'integrated_vendor_y']))
        ->toBe(['integrated_vendor_x', 'integrated_vendor_y']);
});

test('empty strings are filtered out', function () {
    expect(FacilitatorInputParser::parse('integrated_vendor_a,,integrated_vendor_b,'))
        ->toBe(['integrated_vendor_a', 'integrated_vendor_b']);
});

test('null returns empty array', function () {
    expect(FacilitatorInputParser::parse(null))->toBe([]);
});

test('empty string returns empty array', function () {
    expect(FacilitatorInputParser::parse(''))->toBe([]);
});

test('empty array returns empty array', function () {
    expect(FacilitatorInputParser::parse([]))->toBe([]);
});

// ── isIntegratedVendorId ─────────────────────────────────────────────────

test('isIntegratedVendorId returns true for integrated_vendor_ prefix', function () {
    expect(FacilitatorInputParser::isIntegratedVendorId('integrated_vendor_abc'))->toBeTrue();
});

test('isIntegratedVendorId returns false for other strings', function () {
    expect(FacilitatorInputParser::isIntegratedVendorId('service_rate_abc'))->toBeFalse();
    expect(FacilitatorInputParser::isIntegratedVendorId('vendor_abc'))->toBeFalse();
    expect(FacilitatorInputParser::isIntegratedVendorId(''))->toBeFalse();
});

// ── hasMultipleFacilitators ──────────────────────────────────────────────

test('hasMultiple returns true for comma-separated string', function () {
    expect(FacilitatorInputParser::hasMultiple('integrated_vendor_a,integrated_vendor_b'))->toBeTrue();
});

test('hasMultiple returns true for array with 2+ elements', function () {
    expect(FacilitatorInputParser::hasMultiple(['a', 'b']))->toBeTrue();
});

test('hasMultiple returns false for single string', function () {
    expect(FacilitatorInputParser::hasMultiple('integrated_vendor_a'))->toBeFalse();
});

test('hasMultiple returns false for single-element array', function () {
    expect(FacilitatorInputParser::hasMultiple(['a']))->toBeFalse();
});

test('hasMultiple returns false for null', function () {
    expect(FacilitatorInputParser::hasMultiple(null))->toBeFalse();
});

test('hasMultiple returns false for empty string', function () {
    expect(FacilitatorInputParser::hasMultiple(''))->toBeFalse();
});
