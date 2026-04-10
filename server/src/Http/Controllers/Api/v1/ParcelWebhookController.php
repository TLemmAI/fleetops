<?php

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1;

use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Fleetbase\FleetOps\Models\TrackingNumber;
use Fleetbase\FleetOps\Models\TrackingStatus;
use Fleetbase\FleetOps\Support\WebhookPayloadNormalizer;
use Illuminate\Http\Request;
use Throwable;

/**
 * Webhook ingestion endpoint for carrier tracking events.
 *
 * POST /webhooks/parcel/{providerKey}
 *
 * Accepts webhook payloads from ParcelPath, UPS, and USPS (or any
 * future provider added to WebhookPayloadNormalizer::PROVIDERS),
 * normalizes the events via the pure normalizer, and creates
 * TrackingStatus rows using the same dedup key as the poll jobs.
 *
 * The existing TrackingStatusObserver (Task 22) handles downstream
 * effects: Order status transitions + SocketCluster broadcast.
 * This controller only creates the rows — it does not touch Orders
 * or fire notifications directly.
 *
 * ## Authentication
 *
 * Shared-secret header validation via X-Webhook-Secret. The secret
 * is looked up from config at services.{providerKey}.webhook_secret.
 * If no secret is configured, the endpoint is effectively open
 * (suitable for development / initial integration testing). The
 * verification logic is isolated in verifyWebhookSecret() so it can
 * be replaced with HMAC or IP allowlist verification per provider
 * without touching the ingestion logic.
 *
 * ## Idempotency
 *
 * TrackingStatus::firstOrCreate with the composite key
 * (tracking_number_uuid, code, created_at) — same key the poll
 * jobs use. Duplicate webhook deliveries are silently de-duped.
 *
 * ## Error isolation
 *
 * Per-event isolation: a malformed event row is skipped and logged
 * but does not abort the webhook request. The response always
 * returns 200 with a count of processed/skipped events, so the
 * webhook sender considers delivery successful and doesn't retry
 * (avoiding an infinite retry loop on permanently malformed events).
 */
class ParcelWebhookController extends FleetOpsController
{
    /**
     * Handle an inbound webhook payload.
     *
     * @param string  $providerKey  one of: parcelpath, ups, usps
     * @param Request $request      the webhook HTTP request
     */
    public function handle(string $providerKey, Request $request)
    {
        // 1. Validate provider key.
        if (!WebhookPayloadNormalizer::isValidProvider($providerKey)) {
            return response()->json(['error' => 'unknown provider'], 404);
        }

        // 2. Verify shared secret (pluggable — see verifyWebhookSecret).
        if (!$this->verifyWebhookSecret($providerKey, $request)) {
            return response()->json(['error' => 'unauthorized'], 401);
        }

        // 3. Normalize the payload into tracking events.
        $payload = $request->all();
        $events  = WebhookPayloadNormalizer::normalize($providerKey, $payload);

        if (empty($events)) {
            return response()->json(['processed' => 0, 'skipped' => 0]);
        }

        // 4. Process each event: resolve TrackingNumber, firstOrCreate TrackingStatus.
        $processed = 0;
        $skipped   = 0;

        foreach ($events as $event) {
            try {
                $trackingNumber = $this->resolveTrackingNumber($event['tracking_number'] ?? '');
                if ($trackingNumber === null) {
                    $skipped++;
                    continue;
                }

                $dedupKey = WebhookPayloadNormalizer::dedupKey(
                    $trackingNumber->uuid,
                    $event
                );

                TrackingStatus::firstOrCreate(
                    $dedupKey,
                    [
                        'company_uuid' => $trackingNumber->company_uuid,
                        'status'       => $event['status'] ?? $event['code'],
                        'details'      => $event['location'] ?? $event['details'] ?? null,
                    ]
                );

                $processed++;
            } catch (Throwable $e) {
                report($e);
                $skipped++;
            }
        }

        // 5. Always return 200 so the sender considers delivery successful.
        return response()->json([
            'processed' => $processed,
            'skipped'   => $skipped,
        ]);
    }

    /**
     * Resolve a TrackingNumber model from a carrier tracking identifier.
     * Checks the carrier_tracking_number column first (indexed,
     * added in Phase 1 Task 7), then falls back to the primary
     * tracking_number column.
     */
    protected function resolveTrackingNumber(string $carrierTrackingNumber): ?TrackingNumber
    {
        if ($carrierTrackingNumber === '') {
            return null;
        }

        return TrackingNumber::where('carrier_tracking_number', $carrierTrackingNumber)
            ->orWhere('tracking_number', $carrierTrackingNumber)
            ->first();
    }

    /**
     * Verify the webhook request's shared secret. Isolated so it can
     * be replaced with HMAC signing or IP allowlist per provider
     * without touching the ingestion logic.
     *
     * Checks config('services.{providerKey}.webhook_secret'). If no
     * secret is configured (null/empty), verification passes — this
     * makes the endpoint effectively open during development.
     */
    protected function verifyWebhookSecret(string $providerKey, Request $request): bool
    {
        $configuredSecret = config('services.' . strtolower($providerKey) . '.webhook_secret');

        // No secret configured = REJECT by default. Operators must
        // explicitly set the webhook secret in config/services.php or
        // via env var (e.g. PARCELPATH_WEBHOOK_SECRET) to enable the
        // endpoint. This prevents unauthenticated access in production
        // deployments that forget to configure the secret.
        if (empty($configuredSecret)) {
            return false;
        }

        $providedSecret = $request->header('X-Webhook-Secret', '');

        return hash_equals((string) $configuredSecret, (string) $providedSecret);
    }
}
