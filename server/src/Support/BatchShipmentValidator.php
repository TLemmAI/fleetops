<?php

namespace Fleetbase\FleetOps\Support;

/**
 * Pure validation + normalization helpers for the batch shipping
 * endpoints. Stateless, no Eloquent — unit-testable under Pest.
 */
class BatchShipmentValidator
{
    /**
     * Normalize and validate a batch rates request body.
     *
     * Each row must carry at minimum:
     *   - pickup (place public_id or UUID)
     *   - dropoff (place public_id or UUID)
     *   - parcels (array of {length, width, height, weight})
     *
     * Optional per row:
     *   - row_id (caller-chosen identifier; auto-generated if absent)
     *   - facilitator (IntegratedVendor public_id or comma-separated)
     *   - service_type
     *
     * Returns [validRows[], errors[]] where errors carry
     * {row_id, error} and validRows carry the normalized input.
     *
     * @param array $shipments raw request input
     * @return array{0: array, 1: array} [validRows, errors]
     */
    public static function validateRatesInput(array $shipments): array
    {
        $valid  = [];
        $errors = [];

        foreach ($shipments as $index => $row) {
            $rowId = self::resolveRowId($row, $index);

            if (empty($row['pickup'])) {
                $errors[] = ['row_id' => $rowId, 'error' => 'pickup is required'];
                continue;
            }
            if (empty($row['dropoff'])) {
                $errors[] = ['row_id' => $rowId, 'error' => 'dropoff is required'];
                continue;
            }
            if (empty($row['parcels']) || !is_array($row['parcels'])) {
                $errors[] = ['row_id' => $rowId, 'error' => 'parcels array is required'];
                continue;
            }

            $valid[] = [
                'row_id'       => $rowId,
                'pickup'       => (string) $row['pickup'],
                'dropoff'      => (string) $row['dropoff'],
                'parcels'      => $row['parcels'],
                'facilitator'  => $row['facilitator'] ?? null,
                'service_type' => $row['service_type'] ?? null,
            ];
        }

        return [$valid, $errors];
    }

    /**
     * Normalize and validate a batch purchase request body.
     *
     * Each row must carry:
     *   - service_quote_uuid
     *
     * Optional:
     *   - row_id
     *   - order_uuid (attach to existing order; null = create new)
     *
     * @param array $purchases raw request input
     * @return array{0: array, 1: array} [validRows, errors]
     */
    public static function validatePurchaseInput(array $purchases): array
    {
        $valid  = [];
        $errors = [];

        foreach ($purchases as $index => $row) {
            $rowId = self::resolveRowId($row, $index);

            if (empty($row['service_quote_uuid'])) {
                $errors[] = ['row_id' => $rowId, 'error' => 'service_quote_uuid is required'];
                continue;
            }

            $valid[] = [
                'row_id'             => $rowId,
                'service_quote_uuid' => (string) $row['service_quote_uuid'],
                'order_uuid'         => $row['order_uuid'] ?? null,
            ];
        }

        return [$valid, $errors];
    }

    /**
     * Resolve the row_id for a shipment row. Uses the caller-provided
     * row_id if present, otherwise generates a stable identifier from
     * the array index.
     */
    public static function resolveRowId(array $row, int $index): string
    {
        if (isset($row['row_id']) && is_string($row['row_id']) && $row['row_id'] !== '') {
            return $row['row_id'];
        }

        return 'row_' . $index;
    }
}
