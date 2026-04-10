<?php

namespace Fleetbase\FleetOps\Support;

use Fleetbase\FleetOps\Integrations\ParcelPath\ParcelPath;
use Fleetbase\FleetOps\Integrations\UPS\UPS;
use Fleetbase\FleetOps\Integrations\USPS\USPS;

/**
 * Pure static normalizer for carrier webhook payloads. Transforms
 * provider-specific webhook bodies into a flat array of normalized
 * tracking events ready for TrackingStatus::firstOrCreate.
 *
 * Each normalized event has:
 *   tracking_number  string   carrier tracking identifier
 *   code             string   Fleetbase TrackingStatus code (uppercase)
 *   status           string   human-readable status description
 *   timestamp        ?string  ISO-ish timestamp or null
 *   location         ?string  city/state or free-text location
 *   details          ?string  additional context
 *
 * The normalizer reuses the same code-mapping functions the poll
 * jobs use (UPS::upsActivityCodeToFleetbaseCode, USPS::uspsEventTypeToFleetbaseCode)
 * so webhook-ingested events and poll-ingested events produce
 * identical TrackingStatus codes.
 *
 * Stateless, no Eloquent — unit-testable under Pest.
 */
class WebhookPayloadNormalizer
{
    /**
     * Supported provider keys.
     */
    public const PROVIDERS = ['parcelpath', 'ups', 'usps'];

    /**
     * Check whether a provider key is supported.
     */
    public static function isValidProvider(string $providerKey): bool
    {
        return in_array(strtolower($providerKey), self::PROVIDERS, true);
    }

    /**
     * Normalize a webhook payload into an array of tracking events.
     *
     * Dispatches to a provider-specific normalizer based on the
     * providerKey. Returns an array of normalized event rows.
     * Empty array if the payload has no recognizable events.
     *
     * @param string $providerKey  one of: parcelpath, ups, usps
     * @param array  $payload      the decoded JSON webhook body
     * @return array<int, array{tracking_number: string, code: string, status: string, timestamp: ?string, location: ?string, details: ?string}>
     */
    public static function normalize(string $providerKey, array $payload): array
    {
        return match (strtolower($providerKey)) {
            'parcelpath' => self::normalizeParcelPath($payload),
            'ups'        => self::normalizeUps($payload),
            'usps'       => self::normalizeUsps($payload),
            default      => [],
        };
    }

    /**
     * Build the dedup key for a tracking event. Used by the controller
     * to prevent duplicate TrackingStatus rows from repeated webhook
     * deliveries. The key is a composite of (tracking_number_uuid,
     * code, timestamp) — same as what the poll jobs pass to
     * TrackingStatus::firstOrCreate's match array.
     *
     * @return array{tracking_number_uuid: string, code: string, created_at: string}
     */
    public static function dedupKey(string $trackingNumberUuid, array $event): array
    {
        return [
            'tracking_number_uuid' => $trackingNumberUuid,
            'code'                 => (string) ($event['code'] ?? ''),
            'created_at'           => (string) ($event['timestamp'] ?? date('Y-m-d\TH:i:s')),
        ];
    }

    /**
     * ParcelPath webhooks deliver pre-normalized events (the ParcelPath
     * API already maps carrier codes to Fleetbase-compatible codes).
     *
     * Expected shape:
     *   {
     *     tracking_number: '1Z...',
     *     carrier: 'UPS',
     *     status: 'IN_TRANSIT',
     *     events: [
     *       { code: 'IN_TRANSIT', status: 'In Transit', timestamp: '...', location: '...' },
     *     ]
     *   }
     */
    protected static function normalizeParcelPath(array $payload): array
    {
        $trackingNumber = (string) ($payload['tracking_number'] ?? '');
        if ($trackingNumber === '') {
            return [];
        }

        $events = $payload['events'] ?? [];
        if (!is_array($events)) {
            return [];
        }

        $result = [];
        foreach ($events as $event) {
            $code = strtoupper((string) ($event['code'] ?? $event['status'] ?? ''));
            if ($code === '') {
                continue;
            }
            $result[] = [
                'tracking_number' => $trackingNumber,
                'code'            => $code,
                'status'          => (string) ($event['status'] ?? $code),
                'timestamp'       => $event['timestamp'] ?? null,
                'location'        => $event['location'] ?? null,
                'details'         => $event['details'] ?? null,
            ];
        }

        return $result;
    }

    /**
     * UPS webhook payloads follow the UPS Tracking API shape.
     * Reuses UPS::upsActivityCodeToFleetbaseCode for code mapping.
     *
     * Expected shape:
     *   {
     *     trackingNumber: '1Z...',
     *     eventType: 'D',
     *     eventDescription: 'Delivered',
     *     eventTimestamp: '2026-04-09T14:22:00',
     *     eventCity: 'New York',
     *     eventState: 'NY'
     *   }
     *
     * OR an array of events under an `events` key.
     */
    protected static function normalizeUps(array $payload): array
    {
        // Single-event shape (most common for push webhooks)
        if (isset($payload['trackingNumber']) && isset($payload['eventType'])) {
            $code = UPS::upsActivityCodeToFleetbaseCode((string) $payload['eventType']);
            $location = null;
            if (isset($payload['eventCity'])) {
                $location = $payload['eventCity'];
                if (isset($payload['eventState'])) {
                    $location .= ', ' . $payload['eventState'];
                }
            }

            return [[
                'tracking_number' => (string) $payload['trackingNumber'],
                'code'            => $code,
                'status'          => (string) ($payload['eventDescription'] ?? $code),
                'timestamp'       => $payload['eventTimestamp'] ?? null,
                'location'        => $location,
                'details'         => null,
            ]];
        }

        // Multi-event shape
        $trackingNumber = (string) ($payload['trackingNumber'] ?? $payload['tracking_number'] ?? '');
        $events = $payload['events'] ?? [];
        if ($trackingNumber === '' || !is_array($events)) {
            return [];
        }

        $result = [];
        foreach ($events as $event) {
            $rawCode = (string) ($event['eventType'] ?? $event['type'] ?? '');
            if ($rawCode === '') {
                continue;
            }
            $code = UPS::upsActivityCodeToFleetbaseCode($rawCode);
            $location = null;
            if (isset($event['eventCity'])) {
                $location = $event['eventCity'];
                if (isset($event['eventState'])) {
                    $location .= ', ' . $event['eventState'];
                }
            }
            $result[] = [
                'tracking_number' => $trackingNumber,
                'code'            => $code,
                'status'          => (string) ($event['eventDescription'] ?? $code),
                'timestamp'       => $event['eventTimestamp'] ?? $event['timestamp'] ?? null,
                'location'        => $location,
                'details'         => null,
            ];
        }

        return $result;
    }

    /**
     * USPS webhook payloads use USPS v3 eventType codes.
     * Reuses USPS::uspsEventTypeToFleetbaseCode for code mapping
     * (ALERT → EXCEPTION; everything else verbatim).
     *
     * Expected shape:
     *   {
     *     trackingNumber: '9400...',
     *     eventType: 'DELIVERED',
     *     eventTimestamp: '2026-04-09T14:22:00',
     *     eventCity: 'New York'
     *   }
     */
    protected static function normalizeUsps(array $payload): array
    {
        if (isset($payload['trackingNumber']) && isset($payload['eventType'])) {
            $code = USPS::uspsEventTypeToFleetbaseCode((string) $payload['eventType']);
            return [[
                'tracking_number' => (string) $payload['trackingNumber'],
                'code'            => $code,
                'status'          => $code,
                'timestamp'       => $payload['eventTimestamp'] ?? null,
                'location'        => $payload['eventCity'] ?? null,
                'details'         => null,
            ]];
        }

        $trackingNumber = (string) ($payload['trackingNumber'] ?? $payload['tracking_number'] ?? '');
        $events = $payload['events'] ?? [];
        if ($trackingNumber === '' || !is_array($events)) {
            return [];
        }

        $result = [];
        foreach ($events as $event) {
            $rawType = (string) ($event['eventType'] ?? '');
            if ($rawType === '') {
                continue;
            }
            $code = USPS::uspsEventTypeToFleetbaseCode($rawType);
            $result[] = [
                'tracking_number' => $trackingNumber,
                'code'            => $code,
                'status'          => $code,
                'timestamp'       => $event['eventTimestamp'] ?? null,
                'location'        => $event['eventCity'] ?? null,
                'details'         => null,
            ];
        }

        return $result;
    }
}
