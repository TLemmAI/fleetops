<?php

namespace Fleetbase\FleetOps\Support;

use Illuminate\Support\Str;

/**
 * Pure stateless helpers for parsing the `facilitator` input on
 * ServiceQuoteController requests. Supports the Phase 3 hybrid
 * multi-facilitator path (comma-separated or array of public IDs)
 * while remaining fully backward compatible with the Phase 1
 * single-facilitator string path.
 *
 * All methods are static and take only scalar / array inputs — no
 * Request or Eloquent dependencies. Unit-testable under Pest
 * without booting Laravel.
 */
class FacilitatorInputParser
{
    /**
     * Normalize facilitator input into a de-duplicated, trimmed,
     * non-empty array of facilitator identifiers. Accepts:
     *   - a single string (e.g., 'integrated_vendor_abc')
     *   - a comma-separated string (e.g., 'integrated_vendor_a,integrated_vendor_b')
     *   - an array of strings
     *   - null or empty string (returns [])
     *
     * Duplicates are removed (preserving first-seen order).
     * Empty segments are filtered out.
     *
     * @param string|array|null $input
     * @return array<int, string>
     */
    public static function parse(string|array|null $input): array
    {
        if ($input === null) {
            return [];
        }

        if (is_array($input)) {
            $ids = $input;
        } else {
            $ids = explode(',', (string) $input);
        }

        $seen = [];
        $result = [];
        foreach ($ids as $id) {
            $trimmed = trim((string) $id);
            if ($trimmed === '') {
                continue;
            }
            if (isset($seen[$trimmed])) {
                continue;
            }
            $seen[$trimmed] = true;
            $result[] = $trimmed;
        }

        return $result;
    }

    /**
     * Check whether a facilitator identifier looks like an
     * IntegratedVendor public ID (starts with 'integrated_vendor_').
     */
    public static function isIntegratedVendorId(string $id): bool
    {
        return Str::startsWith($id, 'integrated_vendor');
    }

    /**
     * Check whether the raw facilitator input contains multiple
     * facilitators (comma in a string, or 2+ elements in an array).
     * Used by the controller to decide between the single-facilitator
     * fast path and the multi-facilitator aggregation path.
     *
     * @param string|array|null $input
     */
    public static function hasMultiple(string|array|null $input): bool
    {
        if ($input === null) {
            return false;
        }

        if (is_array($input)) {
            return count($input) >= 2;
        }

        return str_contains((string) $input, ',');
    }
}
