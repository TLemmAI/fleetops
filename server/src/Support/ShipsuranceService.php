<?php

namespace Fleetbase\FleetOps\Support;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use RuntimeException;

/**
 * Thin HTTP client for the Shipsurance insurance API.
 *
 * Provides quote, purchase, and void operations for TMS-side
 * insurance on direct UPS/USPS shipments (Mode B). Gated behind
 * the `insurance_provider = 'shipsurance'` option on IntegratedVendor.
 *
 * ParcelPath (Mode A) handles insurance through its own API — this
 * service is NOT called in the ParcelPath flow.
 *
 * ## Usage
 *
 * The bridge's createOrderFromServiceQuote checks the IV options:
 *   if ($options['insurance_provider'] === 'shipsurance'
 *       && InsuranceCalculation::shouldInsure($options['insurance_default']))
 *   {
 *       $svc = new ShipsuranceService($options['shipsurance_api_key']);
 *       $policy = $svc->purchaseInsurance($trackingNumber, $declaredValue, $carrier);
 *       // store $policy on Order.meta.integrated_vendor_order.insurance
 *   }
 *
 * ## Authentication
 *
 * Bearer token using the operator's Shipsurance API key, passed in
 * the constructor. The key is stored on IntegratedVendor.options
 * (not in credentials, because it's an ancillary service key, not
 * the carrier credential).
 *
 * Constructor accepts an injectable HandlerStack for Pest testing.
 */
class ShipsuranceService
{
    private const HOST = 'https://api.shipsurance.com';
    private const SANDBOX_HOST = 'https://api-sandbox.shipsurance.com';

    private string $apiKey;
    private bool $sandbox;
    private Client $http;

    public function __construct(string $apiKey, bool $sandbox = false, ?HandlerStack $handler = null)
    {
        $this->apiKey  = $apiKey;
        $this->sandbox = $sandbox;

        $config = ['base_uri' => $this->baseUrl()];
        if ($handler !== null) {
            $config['handler'] = $handler;
        }
        $this->http = new Client($config);
    }

    public function baseUrl(): string
    {
        return $this->sandbox ? self::SANDBOX_HOST : self::HOST;
    }

    /**
     * Get an insurance quote (premium) for a shipment.
     *
     * @param string $trackingNumber carrier tracking identifier
     * @param int    $declaredValueCents  value to insure in cents
     * @param string $carrier  'UPS' or 'USPS'
     * @param bool   $domestic
     * @return array{premium_cents: int, quote_id: ?string, coverage_cents: int}
     */
    public function getQuote(string $trackingNumber, int $declaredValueCents, string $carrier, bool $domestic = true): array
    {
        $response = $this->request('POST', '/v1/quotes', [
            'json' => [
                'tracking_number' => $trackingNumber,
                'declared_value'  => $declaredValueCents / 100, // API expects dollars
                'carrier'         => strtoupper($carrier),
                'domestic'        => $domestic,
            ],
        ]);

        return [
            'premium_cents'  => (int) round(((float) ($response['premium'] ?? 0)) * 100),
            'quote_id'       => $response['quote_id'] ?? null,
            'coverage_cents' => (int) round(((float) ($response['coverage'] ?? 0)) * 100),
        ];
    }

    /**
     * Purchase insurance for a shipment.
     *
     * @param string $trackingNumber
     * @param int    $declaredValueCents
     * @param string $carrier
     * @param bool   $domestic
     * @return array{policy_id: string, premium_cents: int, coverage_cents: int, purchased: bool}
     */
    public function purchaseInsurance(string $trackingNumber, int $declaredValueCents, string $carrier, bool $domestic = true): array
    {
        $response = $this->request('POST', '/v1/policies', [
            'json' => [
                'tracking_number' => $trackingNumber,
                'declared_value'  => $declaredValueCents / 100,
                'carrier'         => strtoupper($carrier),
                'domestic'        => $domestic,
            ],
        ]);

        return [
            'policy_id'      => (string) ($response['policy_id'] ?? ''),
            'premium_cents'  => (int) round(((float) ($response['premium'] ?? 0)) * 100),
            'coverage_cents' => (int) round(((float) ($response['coverage'] ?? 0)) * 100),
            'purchased'      => (bool) ($response['purchased'] ?? false),
        ];
    }

    /**
     * Void / cancel an insurance policy.
     *
     * @param string $policyId
     * @return bool true if successfully voided
     */
    public function voidInsurance(string $policyId): bool
    {
        $response = $this->request('DELETE', '/v1/policies/' . rawurlencode($policyId));

        return (bool) ($response['voided'] ?? false);
    }

    /**
     * Execute an authenticated request against the Shipsurance API.
     */
    private function request(string $method, string $path, array $options = []): array
    {
        $options['headers'] = array_merge($options['headers'] ?? [], [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        ]);
        $options['http_errors'] = false;

        $response = $this->http->request($method, $path, $options);
        $status   = $response->getStatusCode();
        $body     = json_decode((string) $response->getBody(), true) ?? [];

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException(sprintf(
                'Shipsurance API error: HTTP %d — %s',
                $status,
                $body['error'] ?? 'unknown error'
            ));
        }

        return $body;
    }
}
