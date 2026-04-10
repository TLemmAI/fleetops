<?php

namespace Fleetbase\FleetOps\Observers;

use Fleetbase\FleetOps\Integrations\ParcelPath\ParcelPath;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\TrackingNumber;
use Fleetbase\FleetOps\Models\TrackingStatus;

/**
 * Observes TrackingStatus model lifecycle events and propagates
 * carrier tracking updates to the parent Order.
 *
 * This observer is the single source of truth for the
 * "tracking status created → Order status updated" side effect,
 * regardless of how the TrackingStatus was created:
 *   - PollParcelPathTrackingJob (Phase 1)
 *   - PollUPSTrackingJob / PollUSPSTrackingJob (Phase 2)
 *   - ParcelWebhookController (Phase 3 Task 26)
 *   - Direct API inserts via external callers
 *
 * The poll jobs from Phase 1 + Phase 2 also do their own terminal
 * status transitions inline; this observer is idempotent on top of
 * that — if the Order is already in the target state, the save is
 * a no-op. The primary value of centralizing the logic here is that
 * non-poll creation paths (webhooks, manual inserts) get the same
 * transition behavior for free.
 *
 * ## What this observer does on `created`:
 *
 * 1. Resolves the parent TrackingNumber from tracking_number_uuid.
 * 2. Resolves the parent Order from the TrackingNumber's owner
 *    (only if the owner is an Order).
 * 3. Checks if the TrackingStatus code is terminal via the shared
 *    ParcelPath::terminalOrderStatus() helper.
 * 4. If terminal: sets Order.status to the mapped value
 *    ('completed' / 'returned') and saves.
 * 5. If non-terminal: calls TrackingNumber::updateOwnerStatus()
 *    which sets Order.status to the lowercase code (preserving
 *    existing FleetOps behavior for intermediate statuses like
 *    'in_transit', 'out_for_delivery', etc).
 * 6. The Order save triggers the existing ResourceLifecycleEvent
 *    → SocketClusterBroadcaster pipeline, which pushes the update
 *    to the console and Navigator app in real time.
 *
 * ## What this observer does NOT do:
 *
 * - Fire additional email/push notifications. That's a separate
 *   concern for a NotificationObserver or a queued notification
 *   job. This observer focuses on the status transition only.
 * - Touch the TrackingStatus itself. Read-only access.
 */
class TrackingStatusObserver
{
    /**
     * Handle the TrackingStatus "created" event.
     */
    public function created(TrackingStatus $trackingStatus): void
    {
        $trackingNumber = $this->resolveTrackingNumber($trackingStatus);
        if ($trackingNumber === null) {
            return;
        }

        $order = $this->resolveOrder($trackingNumber);
        if ($order === null) {
            return;
        }

        $code = (string) ($trackingStatus->code ?? '');
        if ($code === '') {
            return;
        }

        // Check for terminal status via the shared helper.
        $terminalStatus = ParcelPath::terminalOrderStatus($code);

        if ($terminalStatus !== null) {
            // Terminal event: map to the FleetOps order lifecycle value
            // (e.g., DELIVERED → 'completed', RETURN_TO_SENDER → 'returned').
            if ($order->status !== $terminalStatus) {
                $order->status = $terminalStatus;
                $order->save();
            }
        } else {
            // Non-terminal event: delegate to the existing
            // updateOwnerStatus which sets the order status to
            // the lowercase tracking code (e.g., IN_TRANSIT → 'in_transit').
            $trackingNumber->updateOwnerStatus($trackingStatus);
        }
    }

    /**
     * Resolve the TrackingNumber parent from the TrackingStatus.
     */
    protected function resolveTrackingNumber(TrackingStatus $trackingStatus): ?TrackingNumber
    {
        $uuid = $trackingStatus->tracking_number_uuid;
        if ($uuid === null || $uuid === '') {
            return null;
        }

        return TrackingNumber::find($uuid);
    }

    /**
     * Resolve the Order owner from the TrackingNumber, if the owner is
     * an Order. Returns null for non-Order owners (e.g., Entity).
     */
    protected function resolveOrder(TrackingNumber $trackingNumber): ?Order
    {
        $ownerType = $trackingNumber->owner_type;
        if ($ownerType === null) {
            return null;
        }

        // The owner_type is stored as a polymorphic type — it may be
        // a full class name or a short alias depending on the
        // PolymorphicType cast. Check for 'Order' in the string.
        $isOrder = str_contains((string) $ownerType, 'Order')
            || str_contains((string) $ownerType, 'order');

        if (!$isOrder) {
            return null;
        }

        return Order::find($trackingNumber->owner_uuid);
    }
}
