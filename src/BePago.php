<?php
/**
 * bePago SDK — PHP (7.4+, sin dependencias).
 *
 * Fuente única: este mismo archivo se publica como el paquete Composer
 * `bepago/sdk` (PSR-4, namespace Bepago\BePago) y se sirve como descarga
 * directa en https://bepago.com/sdk/BePago.php.
 *
 * Con Composer:
 *   composer require bepago/sdk
 *   use Bepago\BePago;
 *   $bp = new BePago('sk_test_…');
 *
 * Sin Composer (descarga directa):
 *   require_once 'BePago.php';
 *   use Bepago\BePago;
 *   $bp = new BePago('sk_test_…');
 *
 *   $charge = $bp->charges()->create([
 *     'reference' => 'order-1', 'currency' => 'PYG',
 *     'amount' => 150000, 'description' => 'Test'
 *   ]);
 */
namespace Bepago;

class BePago {
    private string $secret;
    private string $keyId;
    private string $base;

    public function __construct(string $secret, array $opts = []) {
        $this->secret = $secret;
        $this->keyId = $opts['keyId'] ?? str_replace('sk_', 'pk_', $secret);
        $this->base = $opts['baseUrl'] ?? 'https://bepago.com/api';
    }

    private function sign(string $method, string $path, ?string $body, ?string $idempotencyKey): array {
        $timestamp = (string)(int)(microtime(true) * 1000);
        $nonce = bin2hex(random_bytes(16));
        $bodyStr = $body ?? '';
        $bodyHash = hash('sha256', $bodyStr);
        $canonical = implode("\n", ['v2', $timestamp, $nonce, strtoupper($method), $path, $idempotencyKey ?? '', $bodyHash]);
        $signature = hash_hmac('sha256', $canonical, $this->secret);
        return compact('timestamp', 'nonce', 'signature', 'bodyStr');
    }

    public function request(string $method, string $path, ?array $body = null, ?string $idempotencyKey = null): array {
        $bodyJson = $body !== null ? json_encode($body, JSON_UNESCAPED_UNICODE) : null;
        $sig = $this->sign($method, $path, $bodyJson, $idempotencyKey);

        $headers = [
            'Content-Type: application/json',
            'X-Api-Key: ' . $this->keyId,
            'X-Timestamp: ' . $sig['timestamp'],
            'X-Nonce: ' . $sig['nonce'],
            'X-Signature-Version: 2',
            'X-Signature: ' . $sig['signature'],
        ];
        if ($idempotencyKey) $headers[] = 'X-Idempotency-Key: ' . $idempotencyKey;

        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $bodyJson,
            CURLOPT_TIMEOUT => 30,
        ]);
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $data = json_decode($response, true) ?? [];
        if ($status >= 400) {
            throw new \RuntimeException($data['error']['message'] ?? "HTTP $status", $status);
        }
        return $data;
    }

    public function charges(): object {
        $self = $this;
        return new class($self) {
            private $sdk;
            public function __construct($sdk) { $this->sdk = $sdk; }
            public function create(array $params): array { return $this->sdk->request('POST', '/v1/charges', $params); }
            public function get(string $ref): array { return $this->sdk->request('GET', '/v1/charges/' . urlencode($ref)); }
            public function refund(string $ref, string $reason): array { return $this->sdk->request('POST', '/v1/charges/' . urlencode($ref) . '/refund', ['reason' => $reason]); }
            public function balance(): array { return $this->sdk->request('GET', '/v1/charges/balance'); }
        };
    }

    public function payouts(): object {
        $self = $this;
        return new class($self) {
            private $sdk;
            public function __construct($sdk) { $this->sdk = $sdk; }
            public function create(array $params, string $idempotencyKey): array { return $this->sdk->request('POST', '/v1/payouts', $params, $idempotencyKey); }
            public function get(string $id): array { return $this->sdk->request('GET', '/v1/payouts/' . urlencode($id)); }
            public function cancel(string $id): array { return $this->sdk->request('POST', '/v1/payouts/' . urlencode($id) . '/cancel'); }
            public function balance(): array { return $this->sdk->request('GET', '/v1/balance'); }
        };
    }

    public static function verifyWebhook(string $webhookSecret, string $timestamp, string $body, string $signature): bool {
        $expected = hash_hmac('sha256', $timestamp . $body, $webhookSecret);
        return hash_equals($expected, $signature);
    }
}
