<?php

namespace Fleetbase\FleetOps\Support;

/**
 * Pure insurance premium calculation helpers for the TMS-side
 * Shipsurance integration. Used by direct UPS/USPS bridges (Mode B)
 * when the operator opts into Shipsurance-based insurance via the
 * `insurance_provider = 'shipsurance'` IntegratedVendor option.
 *
 * ParcelPath (Mode A) handles insurance entirely through its own API
 * — this class is NOT used in the ParcelPath flow. It exists only
 * for the direct-carrier path where the TMS operator wants
 * Shipsurance coverage without going through ParcelPath.
 *
 * ## Premium formula
 *
 * 1. Round declared value UP to the next $100 increment.
 * 2. Multiply the number of $100 increments by the per-$100 rate.
 * 3. Domestic and international rates differ (international is
 *    higher due to longer transit and harder claims).
 *
 * All monetary values are in integer cents (USD).
 *
 * ## Rate source
 *
 * The per-$100 rates below are representative Shipsurance pricing
 * as of 2026 for parcel carriers. Actual rates may vary by account
 * and should be confirmed against the Shipsurance API in production.
 * The rates are declared as constants so they can be overridden in
 * a subclass or configuration layer if the operator negotiates a
 * custom rate.
 *
 * Stateless, no Eloquent — unit-testable under Pest.
 */
class InsuranceCalculation
{
    /**
     * Domestic Shipsurance rate per $100 of declared value, in cents.
     * $1.00 per $100 = 100 cents.
     */
    public const DOMESTIC_RATE_PER_100 = 100;

    /**
     * International Shipsurance rate per $100 of declared value, in cents.
     * $1.50 per $100 = 150 cents.
     */
    public const INTERNATIONAL_RATE_PER_100 = 150;

    /**
     * Round a declared value (in cents) UP to the next $100 increment.
     * $0 stays $0. $1–$10000 → $10000. $10001–$20000 → $20000. Etc.
     *
     * @param int $declaredValueCents
     * @return int rounded value in cents
     */
    public static function roundUpDeclaredValue(int $declaredValueCents): int
    {
        if ($declaredValueCents <= 0) {
            return 0;
        }

        $hundredDollarsInCents = 10000;

        return (int) (ceil($declaredValueCents / $hundredDollarsInCents) * $hundredDollarsInCents);
    }

    /**
     * Calculate the insurance premium for a given declared value.
     *
     * @param int    $declaredValueCents  the value to insure, in cents
     * @param string $carrier             'UPS' or 'USPS' (case-insensitive; currently same rate for both)
     * @param bool   $domestic            true for domestic US, false for international
     * @return int premium in cents
     */
    public static function calculatePremium(int $declaredValueCents, string $carrier, bool $domestic = true): int
    {
        if ($declaredValueCents <= 0) {
            return 0;
        }

        $roundedValue = self::roundUpDeclaredValue($declaredValueCents);
        $increments   = $roundedValue / 10000;
        $ratePerIncrement = $domestic ? self::DOMESTIC_RATE_PER_100 : self::INTERNATIONAL_RATE_PER_100;

        return (int) ($increments * $ratePerIncrement);
    }

    /**
     * Check whether insurance should be automatically purchased based
     * on the IntegratedVendor's insurance_default option.
     *
     * - 'auto'   → always insure (returns true)
     * - 'none'   → never insure (returns false)
     * - 'prompt' → ask per shipment (returns false here; the caller
     *              must check if the user explicitly opted in)
     * - null     → no preference (returns false)
     */
    public static function shouldInsure(?string $insuranceDefault): bool
    {
        return strtolower((string) $insuranceDefault) === 'auto';
    }
}
