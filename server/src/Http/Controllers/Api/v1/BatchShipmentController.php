<?php

namespace Fleetbase\FleetOps\Http\Controllers\Api\v1;

use Fleetbase\FleetOps\Http\Controllers\FleetOpsController;
use Fleetbase\FleetOps\Models\IntegratedVendor;
use Fleetbase\FleetOps\Models\Order;
use Fleetbase\FleetOps\Models\Payload;
use Fleetbase\FleetOps\Models\Place;
use Fleetbase\FleetOps\Models\Entity;
use Fleetbase\FleetOps\Models\PurchaseRate;
use Fleetbase\FleetOps\Models\ServiceQuote;
use Fleetbase\FleetOps\Support\BatchShipmentValidator;
use Fleetbase\FleetOps\Support\FacilitatorInputParser;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

/**
 * Batch shipping endpoints for rating and purchasing labels in bulk.
 *
 * ## Endpoints
 *   POST /v1/batch-shipments/rates    — rate multiple shipments
 *   POST /v1/batch-shipments/purchase — purchase labels for selected quotes
 *
 * ## Design principles
 *   - Per-row error isolation: one row failing never aborts the batch
 *   - row_id: caller-provided or auto-generated, returned in every response row
 *   - Idempotent purchases: checks PurchaseRate + Order.meta before buying
 *   - Reuses existing bridge methods (getQuoteFromPayload, createOrderFromServiceQuote)
 *   - Synchronous API, sequential processing in chunks of CHUNK_SIZE
 *   - Stateless: no Batch entity
 */
class BatchShipmentController extends FleetOpsController
{
    /**
     * Max concurrent-ish rows per processing chunk. Within a chunk
     * rows are processed sequentially (PHP is single-threaded); the
     * chunking provides logical grouping for error isolation and can
     * be extended to async (Guzzle Pool) in a future phase.
     */
    private const CHUNK_SIZE = 5;

    /**
     * POST /v1/batch-shipments/rates
     *
     * Rate multiple shipments in a single request. Each row resolves
     * its own facilitator, builds a Payload, and calls the bridge's
     * getQuoteFromPayload. Results are returned per-row with row_id.
     */
    public function rates(Request $request)
    {
        $input = $request->input('shipments', []);
        if (!is_array($input) || empty($input)) {
            return response()->json(['errors' => ['shipments array is required']], 422);
        }

        [$validRows, $validationErrors] = BatchShipmentValidator::validateRatesInput($input);

        $results = [];

        // Carry forward validation errors as per-row error results.
        foreach ($validationErrors as $err) {
            $results[] = [
                'row_id' => $err['row_id'],
                'status' => 'error',
                'error'  => $err['error'],
                'rates'  => [],
            ];
        }

        // Process valid rows in chunks.
        foreach (array_chunk($validRows, self::CHUNK_SIZE) as $chunk) {
            foreach ($chunk as $row) {
                try {
                    $rates = $this->rateRow($row, $request);
                    $results[] = [
                        'row_id' => $row['row_id'],
                        'status' => 'ok',
                        'error'  => null,
                        'rates'  => $rates,
                    ];
                } catch (Throwable $e) {
                    report($e);
                    $results[] = [
                        'row_id' => $row['row_id'],
                        'status' => 'error',
                        'error'  => $e->getMessage(),
                        'rates'  => [],
                    ];
                }
            }
        }

        return response()->json(['results' => $results]);
    }

    /**
     * POST /v1/batch-shipments/purchase
     *
     * Purchase labels for previously rated ServiceQuotes. Each row
     * resolves the ServiceQuote, finds or creates an Order, and calls
     * the bridge's createOrderFromServiceQuote. Idempotent: if the
     * ServiceQuote already has a PurchaseRate OR the Order already
     * carries a tracking_number in meta, returns the existing label.
     */
    public function purchase(Request $request)
    {
        $input = $request->input('purchases', []);
        if (!is_array($input) || empty($input)) {
            return response()->json(['errors' => ['purchases array is required']], 422);
        }

        [$validRows, $validationErrors] = BatchShipmentValidator::validatePurchaseInput($input);

        $results = [];

        foreach ($validationErrors as $err) {
            $results[] = [
                'row_id'          => $err['row_id'],
                'status'          => 'error',
                'error'           => $err['error'],
                'tracking_number' => null,
                'label_file_uuid' => null,
            ];
        }

        foreach (array_chunk($validRows, self::CHUNK_SIZE) as $chunk) {
            foreach ($chunk as $row) {
                try {
                    $result = $this->purchaseRow($row, $request);
                    $results[] = array_merge(['row_id' => $row['row_id'], 'status' => 'ok', 'error' => null], $result);
                } catch (Throwable $e) {
                    report($e);
                    $results[] = [
                        'row_id'          => $row['row_id'],
                        'status'          => 'error',
                        'error'           => $e->getMessage(),
                        'tracking_number' => null,
                        'label_file_uuid' => null,
                    ];
                }
            }
        }

        return response()->json(['results' => $results]);
    }

    /**
     * Rate a single row: resolve places → build Payload → resolve
     * facilitator → bridge getQuoteFromPayload.
     */
    protected function rateRow(array $row, Request $request): array
    {
        $pickup  = Place::where('public_id', $row['pickup'])->orWhere('uuid', $row['pickup'])->firstOrFail();
        $dropoff = Place::where('public_id', $row['dropoff'])->orWhere('uuid', $row['dropoff'])->firstOrFail();

        // Build a temporary Payload with entities for the bridge.
        $payload = Payload::create([
            'company_uuid' => $request->session()->get('company'),
            'pickup_uuid'  => $pickup->uuid,
            'dropoff_uuid' => $dropoff->uuid,
        ]);
        $payload->setRelation('pickup', $pickup);
        $payload->setRelation('dropoff', $dropoff);

        // Create parcel entities on the payload.
        $entities = [];
        foreach ($row['parcels'] as $parcel) {
            $entity = Entity::create([
                'company_uuid'  => $payload->company_uuid,
                'payload_uuid'  => $payload->uuid,
                'type'          => 'parcel',
                'length'        => $parcel['length'] ?? 0,
                'width'         => $parcel['width'] ?? 0,
                'height'        => $parcel['height'] ?? 0,
                'weight'        => $parcel['weight'] ?? 0,
            ]);
            $entities[] = $entity;
        }
        $payload->setRelation('entities', collect($entities));

        // Resolve facilitator(s) and get quotes.
        $facilitatorIds = FacilitatorInputParser::parse($row['facilitator']);
        $allQuotes = [];

        if (count($facilitatorIds) > 0) {
            foreach ($facilitatorIds as $facId) {
                if (!FacilitatorInputParser::isIntegratedVendorId($facId)) {
                    continue;
                }
                $vendor = IntegratedVendor::where('public_id', $facId)->first();
                if (!$vendor) {
                    continue;
                }
                $quotes = $vendor->api()
                    ->setRequestId(ServiceQuote::generatePublicId('request'))
                    ->getQuoteFromPayload($payload, $row['service_type']);
                if (!is_array($quotes)) {
                    $quotes = [$quotes];
                }
                $allQuotes = array_merge($allQuotes, $quotes);
            }
        }

        return $allQuotes;
    }

    /**
     * Purchase a single row: resolve ServiceQuote → check idempotency
     * → resolve or create Order → bridge createOrderFromServiceQuote.
     */
    protected function purchaseRow(array $row, Request $request): array
    {
        $sq = ServiceQuote::where('uuid', $row['service_quote_uuid'])
            ->orWhere('public_id', $row['service_quote_uuid'])
            ->firstOrFail();

        // Idempotency check 1: PurchaseRate already exists.
        $existingPurchase = PurchaseRate::where('service_quote_uuid', $sq->uuid)->first();
        if ($existingPurchase) {
            return $this->existingLabelResult($existingPurchase, $sq);
        }

        // Resolve or create Order.
        $order = null;
        if ($row['order_uuid']) {
            $order = Order::where('public_id', $row['order_uuid'])
                ->orWhere('uuid', $row['order_uuid'])
                ->firstOrFail();
        } else {
            $order = Order::create([
                'company_uuid'  => $request->session()->get('company'),
                'payload_uuid'  => $sq->payload_uuid,
                'type'          => 'parcel',
                'status'        => 'created',
            ]);
        }

        // Idempotency check 2: Order already has a tracking number from a prior label purchase.
        $existingTracking = $order->getMeta('integrated_vendor_order.tracking_number');
        if ($existingTracking) {
            $labelFile = \Fleetbase\Models\File::where('subject_uuid', $order->uuid)
                ->where('folder', 'carrier-labels')
                ->latest()
                ->first();

            return [
                'tracking_number' => $existingTracking,
                'label_file_uuid' => $labelFile?->uuid,
                'order_uuid'      => $order->public_id,
            ];
        }

        // Resolve the IntegratedVendor from the ServiceQuote's meta.
        $facilitatorId = $sq->meta['facilitator_public_id'] ?? null;
        $vendor = null;
        if ($facilitatorId) {
            $vendor = IntegratedVendor::where('public_id', $facilitatorId)->first();
        }
        if (!$vendor && $order->facilitator_uuid) {
            $vendor = IntegratedVendor::find($order->facilitator_uuid);
        }
        if (!$vendor) {
            throw new \RuntimeException('Cannot resolve IntegratedVendor for label purchase');
        }

        // Purchase the label.
        $bridgeResult = $vendor->api()->createOrderFromServiceQuote($sq, $order);

        // Create PurchaseRate to mark this quote as purchased.
        PurchaseRate::create([
            'company_uuid'      => $order->company_uuid,
            'service_quote_uuid' => $sq->uuid,
            'order_uuid'        => $order->uuid,
        ]);

        $labelFile = \Fleetbase\Models\File::where('subject_uuid', $order->uuid)
            ->where('folder', 'carrier-labels')
            ->latest()
            ->first();

        return [
            'tracking_number' => $bridgeResult['tracking_number'] ?? $order->getMeta('integrated_vendor_order.tracking_number'),
            'label_file_uuid' => $labelFile?->uuid,
            'order_uuid'      => $order->public_id,
        ];
    }

    /**
     * Build the response for an already-purchased quote (idempotency path).
     */
    protected function existingLabelResult(PurchaseRate $pr, ServiceQuote $sq): array
    {
        $order = Order::find($pr->order_uuid);
        $trackingNumber = $order?->getMeta('integrated_vendor_order.tracking_number');
        $labelFile = $order
            ? \Fleetbase\Models\File::where('subject_uuid', $order->uuid)
                ->where('folder', 'carrier-labels')
                ->latest()
                ->first()
            : null;

        return [
            'tracking_number' => $trackingNumber,
            'label_file_uuid' => $labelFile?->uuid,
            'order_uuid'      => $order?->public_id,
        ];
    }
}
