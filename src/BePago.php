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
            /**
             * Confirma el estado REAL de un cobro consultando la API (fuente de
             * verdad). Nunca confíes en el navegador ni en el iframe.
             * @return array{paid:bool,status:?string,charge:array}
             */
            public function confirm(string $ref): array {
                $charge = $this->sdk->request('GET', '/v1/charges/' . urlencode($ref));
                $status = $charge['status'] ?? ($charge['data']['status'] ?? null);
                return ['paid' => $status === 'paid', 'status' => $status, 'charge' => $charge];
            }
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

    /**
     * Manejador de webhooks seguro-por-defecto. Verifica firma y frescura
     * (±5 min), descarta duplicados por X-BePago-Event-Id y SOLO invoca
     * `onPaid` con eventos `charge.paid` válidos y no vistos.
     *
     * Config: [
     *   'secret'  => string,                        // requerido
     *   'onPaid'  => callable(array $ctx): void,    // $ctx = ['reference'=>..,'data'=>..,'event'=>..]
     *   'onEvent' => callable(array $evt): void,    // opcional, todos los eventos
     *   'store'   => object{has(string):bool, add(string):void}, // opcional
     *   'toleranceSeconds' => int,                  // opcional (default 300)
     * ]
     *
     * En PHP cada request es un proceso nuevo: el store en memoria NO deduplica
     * entre entregas. Para no entregar dos veces pasá un `store` respaldado por
     * tu base (una tabla de event ids procesados).
     *
     * Ejemplo PSR-7 (cualquier framework: Slim, Laravel, Mezzio…):
     *
     *   use Psr\Http\Message\ServerRequestInterface as Req;
     *   use Psr\Http\Message\ResponseInterface as Res;
     *
     *   $wh = (new BePago($sk))->webhooks()->handler([
     *     'secret' => getenv('BEPAGO_WEBHOOK_SECRET'),
     *     'store'  => $misEventIdsEnLaBase,            // has()/add() contra tu DB
     *     'onPaid' => function (array $c) use ($bp) {
     *       $r = $bp->charges()->confirm($c['reference']); // reconfirmá contra la API
     *       if ($r['paid']) entregar($c['reference']);
     *     },
     *   ]);
     *
     *   $app->post('/webhooks/bepago', function (Req $req, Res $res) use ($wh) {
     *     $headers = [];
     *     foreach ($req->getHeaders() as $k => $v) $headers[$k] = $v[0] ?? '';
     *     $out = $wh->process($headers, (string)$req->getBody());
     *     $res->getBody()->write($out['body']);
     *     return $res->withStatus($out['status'])->withHeader('Content-Type', 'application/json');
     *   });
     *
     * @return object con process(array $headers, string $rawBody): array
     */
    public function webhooks(): object {
        return new class {
            public function handler(array $config): object {
                if (empty($config['secret'])) throw new \InvalidArgumentException('bePago webhooks.handler: falta `secret`');
                return new class($config) {
                    private array $config;
                    private object $store;
                    public function __construct(array $config) {
                        $this->config = $config;
                        $this->store = $config['store'] ?? new class {
                            private array $seen = [];
                            public function has(string $id): bool { return isset($this->seen[$id]); }
                            public function add(string $id): void { $this->seen[$id] = true; }
                        };
                    }
                    /**
                     * @param array<string,string> $headers cabeceras (claves case-insensitive)
                     * @param string $rawBody cuerpo EXACTO recibido, sin reserializar
                     * @return array{status:int,body:string,duplicate:bool,ok:bool}
                     */
                    public function process(array $headers, string $rawBody): array {
                        $h = [];
                        foreach ($headers as $k => $v) $h[strtolower($k)] = is_array($v) ? ($v[0] ?? '') : $v;
                        $tolerance = (int)($this->config['toleranceSeconds'] ?? 300);
                        try {
                            $evt = \Bepago\BePago::verifyWebhook($this->config['secret'], [
                                'id' => $h['x-bepago-event-id'] ?? null,
                                'timestamp' => $h['x-webhook-timestamp'] ?? '',
                                'signature' => $h['x-webhook-signature'] ?? '',
                                'body' => $rawBody,
                            ], $tolerance);
                        } catch (\Throwable $e) {
                            return ['status' => 400, 'body' => json_encode(['error' => 'invalid_signature']), 'duplicate' => false, 'ok' => false];
                        }
                        $id = (string)($evt['id'] ?? '');
                        if ($this->store->has($id)) {
                            return ['status' => 200, 'body' => json_encode(['received' => true, 'duplicate' => true]), 'duplicate' => true, 'ok' => true];
                        }
                        $this->store->add($id);
                        try {
                            if (isset($this->config['onEvent']) && is_callable($this->config['onEvent'])) ($this->config['onEvent'])($evt);
                            if (($evt['event'] ?? null) === 'charge.paid' && isset($this->config['onPaid']) && is_callable($this->config['onPaid'])) {
                                ($this->config['onPaid'])(['reference' => $evt['data']['reference'] ?? null, 'data' => $evt['data'] ?? null, 'event' => $evt]);
                            }
                        } catch (\Throwable $e) {
                            return ['status' => 500, 'body' => json_encode(['error' => 'handler_error']), 'duplicate' => false, 'ok' => false];
                        }
                        return ['status' => 200, 'body' => json_encode(['received' => true]), 'duplicate' => false, 'ok' => true];
                    }
                };
            }
        };
    }

    /**
     * Verifica un webhook de bePago y devuelve el evento parseado.
     *
     * Chequea la firma HMAC y la FRESCURA del timestamp: por defecto rechaza
     * entregas fuera de ±5 minutos ($toleranceSeconds configurable). Lanza una
     * RuntimeException si la firma no valida o el timestamp está fuera de ventana.
     *
     * Expone `id` (header X-BePago-Event-Id, estable entre reintentos): guardá
     * los ids ya procesados para descartar duplicados de forma idempotente.
     *
     *   $evt = BePago::verifyWebhook($secret, [
     *     'id'        => $_SERVER['HTTP_X_BEPAGO_EVENT_ID'] ?? null,
     *     'timestamp' => $_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'] ?? '',
     *     'signature' => $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '',
     *     'body'      => file_get_contents('php://input'), // cuerpo EXACTO
     *   ]);
     *   if ($seen->has($evt['id'])) return; // duplicado
     *
     * @param array{id?:?string,timestamp:string,body:string,signature:string} $event
     * @return array{id:?string,event:?string,livemode:?bool,data:?array}
     */
    public static function verifyWebhook(string $webhookSecret, array $event, int $toleranceSeconds = 300): array {
        $timestamp = (string)($event['timestamp'] ?? '');
        $body = (string)($event['body'] ?? '');
        $signature = (string)($event['signature'] ?? '');
        $expected = hash_hmac('sha256', $timestamp . $body, $webhookSecret);
        if (!hash_equals($expected, $signature)) {
            throw new \RuntimeException('bePago webhook: firma inválida');
        }
        $ageMs = abs((int)(microtime(true) * 1000) - (int)$timestamp);
        if ($ageMs > $toleranceSeconds * 1000) {
            throw new \RuntimeException('bePago webhook: timestamp fuera de la ventana permitida');
        }
        $payload = json_decode($body, true) ?? [];
        return [
            'id' => $event['id'] ?? ($payload['id'] ?? null),
            'event' => $payload['event'] ?? null,
            'livemode' => $payload['livemode'] ?? null,
            'data' => $payload['data'] ?? null,
        ];
    }
}
