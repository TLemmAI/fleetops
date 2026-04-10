<?php

use Fleetbase\FleetOps\Support\ShipsuranceService;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

class ShipsuranceTestHarness
{
    public ShipsuranceService $svc;
    public array $history = [];

    public function __construct(array $responses = [], bool $sandbox = true)
    {
        $mock = new MockHandler(array_map(
            fn ($p) => new Response(
                $p['status'] ?? 200,
                ['Content-Type' => 'application/json'],
                json_encode($p['body'] ?? [])
            ),
            $responses
        ));
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));
        $this->svc = new ShipsuranceService('test-api-key', $sandbox, $stack);
    }
}

// ── Host selection ───────────────────────────────────────────────────────

test('sandbox routes to api-sandbox.shipsurance.com', function () {
    expect((new ShipsuranceService('k', true))->baseUrl())
        ->toBe('https://api-sandbox.shipsurance.com');
});

test('production routes to api.shipsurance.com', function () {
    expect((new ShipsuranceService('k', false))->baseUrl())
        ->toBe('https://api.shipsurance.com');
});

// ── getQuote ─────────────────────────────────────────────────────────────

test('getQuote sends correct body and converts response to cents', function () {
    $h = new ShipsuranceTestHarness([
        ['status' => 200, 'body' => ['premium' => 1.50, 'quote_id' => 'q_123', 'coverage' => 200.00]],
    ]);

    $result = $h->svc->getQuote('1Z999', 20000, 'UPS', true);

    expect($result['premium_cents'])->toBe(150);
    expect($result['quote_id'])->toBe('q_123');
    expect($result['coverage_cents'])->toBe(20000);

    $req = $h->history[0]['request'];
    expect($req->getMethod())->toBe('POST');
    expect($req->getHeaderLine('Authorization'))->toBe('Bearer test-api-key');
    $body = json_decode((string) $req->getBody(), true);
    expect($body['declared_value'])->toBe(200); // cents → dollars (int division)
    expect($body['carrier'])->toBe('UPS');
    expect($body['domestic'])->toBeTrue();
});

// ── purchaseInsurance ────────────────────────────────────────────────────

test('purchaseInsurance returns policy_id and cents-converted amounts', function () {
    $h = new ShipsuranceTestHarness([
        ['status' => 200, 'body' => ['policy_id' => 'pol_abc', 'premium' => 2.00, 'coverage' => 300.00, 'purchased' => true]],
    ]);

    $result = $h->svc->purchaseInsurance('1Z999', 30000, 'USPS', true);

    expect($result['policy_id'])->toBe('pol_abc');
    expect($result['premium_cents'])->toBe(200);
    expect($result['coverage_cents'])->toBe(30000);
    expect($result['purchased'])->toBeTrue();
});

test('purchaseInsurance sends Bearer auth', function () {
    $h = new ShipsuranceTestHarness([
        ['status' => 200, 'body' => ['policy_id' => 'p', 'purchased' => true]],
    ]);
    $h->svc->purchaseInsurance('1Z', 10000, 'UPS');
    expect($h->history[0]['request']->getHeaderLine('Authorization'))->toBe('Bearer test-api-key');
});

// ── voidInsurance ────────────────────────────────────────────────────────

test('voidInsurance returns true on successful void', function () {
    $h = new ShipsuranceTestHarness([
        ['status' => 200, 'body' => ['voided' => true]],
    ]);
    expect($h->svc->voidInsurance('pol_abc'))->toBeTrue();
    expect($h->history[0]['request']->getMethod())->toBe('DELETE');
});

test('voidInsurance returns false on failed void', function () {
    $h = new ShipsuranceTestHarness([
        ['status' => 200, 'body' => ['voided' => false]],
    ]);
    expect($h->svc->voidInsurance('pol_abc'))->toBeFalse();
});

// ── Error handling ───────────────────────────────────────────────────────

test('non-2xx response throws RuntimeException', function () {
    $h = new ShipsuranceTestHarness([
        ['status' => 422, 'body' => ['error' => 'invalid tracking number']],
    ]);
    expect(fn () => $h->svc->getQuote('bad', 10000, 'UPS'))
        ->toThrow(RuntimeException::class, 'invalid tracking number');
});

test('401 response throws RuntimeException', function () {
    $h = new ShipsuranceTestHarness([
        ['status' => 401, 'body' => ['error' => 'unauthorized']],
    ]);
    expect(fn () => $h->svc->purchaseInsurance('1Z', 10000, 'UPS'))
        ->toThrow(RuntimeException::class);
});
