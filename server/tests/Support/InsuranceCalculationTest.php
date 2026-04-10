<?php

use Fleetbase\FleetOps\Support\InsuranceCalculation;

// ── roundUpDeclaredValue ─────────────────────────────────────────────────

test('roundUpDeclaredValue rounds $50 up to $100', function () {
    expect(InsuranceCalculation::roundUpDeclaredValue(5000))->toBe(10000);
});

test('roundUpDeclaredValue leaves $100 as $100', function () {
    expect(InsuranceCalculation::roundUpDeclaredValue(10000))->toBe(10000);
});

test('roundUpDeclaredValue rounds $101 up to $200', function () {
    expect(InsuranceCalculation::roundUpDeclaredValue(10100))->toBe(20000);
});

test('roundUpDeclaredValue rounds $250 up to $300', function () {
    expect(InsuranceCalculation::roundUpDeclaredValue(25000))->toBe(30000);
});

test('roundUpDeclaredValue handles zero', function () {
    expect(InsuranceCalculation::roundUpDeclaredValue(0))->toBe(0);
});

test('roundUpDeclaredValue handles $1 as $100', function () {
    expect(InsuranceCalculation::roundUpDeclaredValue(100))->toBe(10000);
});

// ── calculatePremium ─────────────────────────────────────────────────────

test('domestic UPS premium at $100 coverage is the base rate', function () {
    $premium = InsuranceCalculation::calculatePremium(10000, 'UPS', true);
    expect($premium)->toBe(InsuranceCalculation::DOMESTIC_RATE_PER_100);
});

test('domestic UPS premium at $300 coverage is 3x base rate', function () {
    $premium = InsuranceCalculation::calculatePremium(30000, 'UPS', true);
    expect($premium)->toBe(InsuranceCalculation::DOMESTIC_RATE_PER_100 * 3);
});

test('international UPS premium at $100 is the international rate', function () {
    $premium = InsuranceCalculation::calculatePremium(10000, 'UPS', false);
    expect($premium)->toBe(InsuranceCalculation::INTERNATIONAL_RATE_PER_100);
});

test('premium rounds declared value up before calculating', function () {
    // $150 rounds up to $200 → 2 × domestic rate
    $premium = InsuranceCalculation::calculatePremium(15000, 'USPS', true);
    expect($premium)->toBe(InsuranceCalculation::DOMESTIC_RATE_PER_100 * 2);
});

test('zero declared value returns zero premium', function () {
    expect(InsuranceCalculation::calculatePremium(0, 'UPS', true))->toBe(0);
});

test('carrier name is case-insensitive', function () {
    $a = InsuranceCalculation::calculatePremium(10000, 'ups', true);
    $b = InsuranceCalculation::calculatePremium(10000, 'UPS', true);
    expect($a)->toBe($b);
});

// ── shouldInsure ─────────────────────────────────────────────────────────

test('shouldInsure returns true when insurance_default is auto', function () {
    expect(InsuranceCalculation::shouldInsure('auto'))->toBeTrue();
});

test('shouldInsure returns false when insurance_default is none', function () {
    expect(InsuranceCalculation::shouldInsure('none'))->toBeFalse();
});

test('shouldInsure returns false when insurance_default is prompt', function () {
    // prompt = ask per shipment; the batch/single-order flow decides
    expect(InsuranceCalculation::shouldInsure('prompt'))->toBeFalse();
});

test('shouldInsure returns false for null', function () {
    expect(InsuranceCalculation::shouldInsure(null))->toBeFalse();
});
