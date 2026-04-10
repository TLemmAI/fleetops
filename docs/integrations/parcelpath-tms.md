# ParcelPath / UPS / USPS — Small Parcel TMS Integration

## Overview

FleetOps supports three coexisting integration modes for UPS and USPS small-parcel shipping:

| Mode | Description | Carrier credentials needed | Best for |
|---|---|---|---|
| **A — ParcelPath** | Single API key → ParcelPath handles UPS + USPS | ParcelPath API key only | New operators, quick start |
| **B — Direct** | TMS calls UPS/USPS APIs with the operator's own credentials | UPS OAuth + account number, USPS OAuth | Enterprise with negotiated rates |
| **C — Hybrid** | Queries ParcelPath + direct accounts simultaneously | Both | Rate comparison, broker optimization |

All three modes share the same ServiceQuote, Order, TrackingNumber, TrackingStatus, and File models. The difference is which bridge class processes the request.

---

## Setup

### Mode A — ParcelPath (recommended default)

1. Navigate to **Fleet-Ops → Management → Integrated Vendors → New**.
2. Select **ParcelPath** in the provider picker.
3. Enter your ParcelPath API key. Toggle **Sandbox** for testing.
4. Configure options:
   - **Carrier filter**: UPS + USPS (default), UPS only, or USPS only
   - **Label format**: PDF or ZPL (thermal)
   - **Insurance default**: No insurance, Auto-insure all, or Ask per shipment
   - **Markup**: optional flat (cents) or percentage markup on top of ParcelPath rates
5. Save. You're ready to rate and ship.

### Mode B — Direct UPS

1. Create a UPS developer account at [developer.ups.com](https://developer.ups.com).
2. Create an app with access to Rating, Shipping, and Tracking APIs.
3. In FleetOps: **Integrated Vendors → New → UPS**.
4. Enter `client_id`, `client_secret`, and `account_number`.
5. Toggle **Sandbox** for the UPS CIE test environment.
6. Optional: configure markup, label format (PDF/ZPL), and Shipsurance insurance.
7. Optional: set **Shipper Client** to scope this credential to a specific vendor (for brokers).

### Mode B — Direct USPS

1. Create a USPS developer account at [developer.usps.com](https://developer.usps.com).
2. Enable the Prices, Labels, and Tracking products.
3. In FleetOps: **Integrated Vendors → New → USPS**.
4. Enter `client_id` and `client_secret`. No account number (USPS rates are zip-scoped).
5. Toggle **Sandbox** for the USPS TEM test environment.
6. Note: USPS labels are **PDF only** — no ZPL option.

### Mode C — Hybrid

1. Set up both a ParcelPath record and one or more direct carrier records.
2. When creating an order, pass multiple facilitator IDs:
   ```
   facilitator=integrated_vendor_pp,integrated_vendor_ups_direct
   ```
3. The controller queries all specified vendors and returns all rates in a single response.
4. The UI renders each rate with a source badge: "via ParcelPath" or "your account".

---

## Broker / Multi-Tenant Configuration

### The shipper_client_uuid workflow

Brokers managing multiple shipper clients (each with their own UPS/USPS accounts) use the **Shipper Client** field on the IntegratedVendor form:

1. Create one IntegratedVendor per carrier per shipper client, setting the **Shipper Client** dropdown to the corresponding Vendor record.
2. Create one catch-all IntegratedVendor per carrier with **Shipper Client** left blank — this handles any order whose customer doesn't have a dedicated credential.
3. When an order's customer is a Vendor, the `IntegratedVendorResolver` automatically resolves the correct credential: exact match first, catch-all fallback if no match, silent skip if neither exists.

**Safety guarantee:** the resolver never routes through a mismatched credential. If an order's customer is Acme Corp and only TechCo has a dedicated UPS record (with no catch-all), UPS is silently dropped from the rate response rather than billing TechCo's account.

### ParcelPath for brokers

ParcelPath eliminates the multi-record problem: one ParcelPath IntegratedVendor covers all shipper clients. ParcelPath handles per-client billing internally. The broker's margin is managed via the markup option on the IntegratedVendor or within ParcelPath's own dashboard.

---

## Rating

| Endpoint | Mode A | Mode B | Mode C |
|---|---|---|---|
| `GET/POST /v1/service-quotes?facilitator=integrated_vendor_xxx&payload=payload_xxx` | ParcelPath API | UPS Rating API / USPS Prices API | All specified vendors queried |

Rates are returned as `ServiceQuote` records with `ServiceQuoteItem` line items. The `meta` field carries carrier-specific data:

**ParcelPath quotes:**
```json
{
  "carrier": "UPS",
  "service_token": "ups_ground",
  "pp_rate_id": "rate_abc",
  "estimated_days": 5,
  "carrier_amount": 842,
  "insurance_available": true,
  "insurance_cost": 125
}
```

**Direct UPS quotes:**
```json
{
  "carrier": "UPS",
  "service_code": "03",
  "carrier_amount": 1000,
  "markup_amount": 50,
  "markup_type": "flat"
}
```

**Direct USPS quotes:**
```json
{
  "carrier": "USPS",
  "mail_class": "PRIORITY_MAIL",
  "carrier_amount": 810,
  "markup_amount": 25,
  "markup_type": "flat"
}
```

---

## Label Purchase

Selecting a ServiceQuote and confirming the order calls `createOrderFromServiceQuote` on the bridge, which:

1. Calls the carrier's label API (ParcelPath `/v1/labels`, UPS Ship API, USPS Labels API).
2. Decodes the base64 label binary (PDF or ZPL).
3. Writes a `File` record under the `carrier-labels/` folder.
4. Updates `Order.meta.integrated_vendor_order` with tracking number, carrier, and insurance details.
5. The `LabelController::getLabel()` fallback serves the carrier label from Storage before falling back to the internal Blade label.

---

## Tracking

### Polling (primary)

Three scheduled jobs run every 15 minutes:

| Job | Scope | Code mapper |
|---|---|---|
| `PollParcelPathTrackingJob` | Orders with `parcelpath_shipment_id` | Pre-normalized by ParcelPath |
| `PollUPSTrackingJob` | Orders with `shipmentIdentificationNumber` | I→IN_TRANSIT, D→DELIVERED, X→EXCEPTION, P→PICKED_UP, M→MANIFESTED, O→OUT_FOR_DELIVERY, RS→RETURN_TO_SENDER |
| `PollUSPSTrackingJob` | Orders with carrier=USPS | ALERT→EXCEPTION; all others verbatim |

Each job: `TrackingStatus::firstOrCreate` per event (idempotent), terminal transition via `ParcelPath::terminalOrderStatus()` (DELIVERED→completed, RETURN_TO_SENDER→returned).

### Webhooks (supplementary)

`POST /webhooks/parcel/{providerKey}` accepts push events from ParcelPath, UPS, or USPS. Same normalization and dedup key as the poll jobs. Shared-secret auth via `X-Webhook-Secret` header. The `TrackingStatusObserver` handles Order status transitions and SocketCluster broadcast for both poll and webhook paths.

### Real-time updates

When a `TrackingStatus` is created (by any path), the `TrackingStatusObserver` updates the parent Order's status and the existing `SendsWebhooks` trait + `ResourceLifecycleEvent` pipeline broadcasts the event to SocketCluster channels (`company.<uuid>`, `order.<publicId>`). The console and Navigator app receive updates without additional wiring.

---

## Batch Shipping

`POST /v1/batch-shipments/rates` and `POST /v1/batch-shipments/purchase` enable bulk operations:

- **Rates**: submit an array of shipments, each with pickup/dropoff/parcels/facilitator. Returns per-row rate results with `row_id`.
- **Purchase**: submit an array of `service_quote_uuid` selections. Returns per-row tracking numbers + label file UUIDs.
- **Idempotent**: checks PurchaseRate + Order.meta before buying, returns existing label if already purchased.
- **Per-row isolation**: one row failing never aborts the batch.

---

## Insurance (Shipsurance)

**Mode A (ParcelPath):** insurance is managed by ParcelPath's API. The TMS stores the policy details from the label response.

**Mode B (Direct):** operators can enable Shipsurance on the IntegratedVendor:
1. Set `insurance_provider` to `Shipsurance`.
2. Set `insurance_default` to `Auto-insure all` or `Ask per shipment`.
3. Enter the `shipsurance_api_key`.

Premium calculation: declared value rounded up to the next $100 × rate per $100 ($1.00 domestic, $1.50 international).

---

## Service Types

### ParcelPath (13 services)
PP_UPS_GROUND, PP_UPS_GROUND_SAVER, PP_UPS_3DS, PP_UPS_2DA, PP_UPS_2DAM, PP_UPS_1DA, PP_UPS_1DAM, PP_UPS_1DASAVER, PP_USPS_PRIORITY, PP_USPS_EXPRESS, PP_USPS_GROUND_ADV, PP_USPS_FIRST, PP_USPS_MEDIA

### UPS Direct (8 services)
GROUND (03), GROUND_SAVER (93), 3DS (12), 2DA (02), 2DAM (59), 1DA (01), 1DAM (14), 1DASAVER (13)

### USPS Direct (5 services)
PRIORITY (PRIORITY_MAIL), PRIORITY_EXPRESS (PRIORITY_MAIL_EXPRESS), GROUND_ADVANTAGE (USPS_GROUND_ADVANTAGE), FIRST_CLASS (FIRST-CLASS_PACKAGE_SERVICE), MEDIA_MAIL (MEDIA_MAIL)

---

## Dev Environment Notes

### Docker bind mount

Mount only `../fleetops/server` (not the full `../fleetops`) into `application`, `queue`, and `scheduler` services. The full mount drags `server_vendor/` (~58k files) and wedges Docker Desktop.

### Running migrations

```bash
docker compose exec application php artisan migrate \
  --path=/fleetbase/api/vendor/fleetbase/fleetops/server/migrations \
  --realpath --force
```

`--realpath` is required with absolute paths — without it Laravel silently finds nothing.

### Running tests

```bash
docker run --rm -v ~/fleetbase-project/fleetops:/app -w /app \
  fleetbase/fleetbase-api:latest \
  sh -c "ln -sf server_vendor vendor && ./server_vendor/bin/pest"
```

The `vendor → server_vendor` symlink is required because Pest hardcodes the `vendor/` path.
