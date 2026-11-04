<?php
/**
 * bePago SDK — PHP (7.4+; solo las extensiones curl y json, que vienen con PHP).
 *
 * Fuente única: este mismo archivo se publica como el paquete Composer
 * `bepago/sdk` (PSR-4, namespace Bepago\BePago) y se sirve como descarga
 * directa en https://bepago.com/sdk/BePago.php.
 *
 * Con Composer:
 *   composer require bepago/sdk
 *   use Bepago\BePago;
 *   $bp = new BePago('sk_test_…');   // el Key ID (pk_…) sale del secreto; con claves viejas: ['keyId' => 'pk_…']
 *
 * Sin Composer (descarga directa):
 *   require_once 'BePago.php';
 *   use Bepago\BePago;
 *   $bp = new BePago('sk_test_…');
 *
 *   $charge = $bp->charges()->create([
 *     'reference' => 'order-1', 'currency' => 'PYG',
 *     'amount' => 150000, 'description' => 'Test'
 *     // opcional: 'method' => 'qr_sip' | 'pix' | 'qr_ar' (uno de los activos del comercio)
 *   ]);
 */
namespace Bepago;

/**
 * Error de la API o de la conexión. getCode() es el HTTP (0 sin respuesta);
 * getErrorCode() el código de bePago (`insufficient_funds`, `network_error`…).
 */
class BePagoException extends \RuntimeException {
    private ?string $errorCode;
    private ?string $requestId;
    private $details;
    private $response;

    public function __construct(string $message, int $status = 0, ?string $errorCode = null, ?string $requestId = null, $details = null, $response = null) {
        parent::__construct($message, $status);
        $this->errorCode = $errorCode;
        $this->requestId = $requestId;
        $this->details = $details;
        $this->response = $response;
    }
    public function getStatus(): int { return $this->getCode(); }
    public function getErrorCode(): ?string { return $this->errorCode; }
    public function getRequestId(): ?string { return $this->requestId; }
    public function getDetails() { return $this->details; }
    public function getResponse() { return $this->response; }
}

class BePago {
    private string $secret;
    private string $keyId;
    private string $base;
    private int $timeout;
    private int $maxRetries;

    public function __construct(string $secret, array $opts = []) {
        if ($secret === '') throw new \InvalidArgumentException('bePago: falta la clave secreta (sk_test_… o sk_live_…). La creás en Integraciones › Claves de API.');
        $this->secret = $secret;
        // Sin Key ID (secreto del formato anterior) igual se puede verificar webhooks; la API lo pide al primer request
        $this->keyId = $opts['keyId'] ?? (self::keyIdFromSecret($secret) ?? '');
        // https://bepago.com/api — acepta también la dirección con /v1 al final (como la muestra el panel)
        $this->base = preg_replace('#/v1$#', '', rtrim($opts['baseUrl'] ?? 'https://bepago.com/api', '/'));
        $this->timeout = (int)($opts['timeout'] ?? 30);
        // Reintentos automáticos SOLO cuando repetir no puede duplicar nada: GET
        // ante red caída, tiempo agotado o 502/503/504; POST con clave de
        // idempotencia (pagos y lotes) ante red caída o tiempo agotado. Un POST
        // sin clave (cobros, reembolsos) nunca.
        $this->maxRetries = max(0, (int)($opts['maxRetries'] ?? 2));
    }

    /**
     * Key ID público (pk_…) de un secreto. Los secretos de ahora llevan adentro
     * el cuerpo de su Key ID (sk_<modo>_<16 del Key ID><43>); con los anteriores
     * devuelve null (hay que pasar el Key ID aparte).
     */
    public static function keyIdFromSecret(string $secret): ?string {
        if (!preg_match('/^sk_(test|live)_([A-Za-z0-9_-]+)$/', $secret, $m) || strlen($m[2]) !== 59) return null;
        return 'pk_' . $m[1] . '_' . substr($m[2], 0, 16);
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

    /**
     * Pagos y lotes llevan X-Idempotency-Key OBLIGATORIO (mín. 8 caracteres), igual
     * que la API (400 missing_idempotency_key): se valida antes de firmar. Sin
     * clave, BePagoException con el mismo código que la API y status 0 (no hubo
     * request): se atrapa con el mismo catch que los errores de la API.
     */
    public static function requireIdempotencyKey(?string $key): string {
        if ($key === null || strlen(trim($key)) < 8) {
            throw new BePagoException('bePago: falta idempotencyKey (string de al menos 8 caracteres, único por pago o lote)', 0, 'missing_idempotency_key');
        }
        return $key;
    }

    public function request(string $method, string $path, ?array $body = null, ?string $idempotencyKey = null): array {
        if ($this->keyId === '') {
            throw new BePagoException("bePago: esta clave secreta es de un formato anterior y no trae su Key ID adentro. Pasalo aparte: new BePago(\$secret, ['keyId' => 'pk_…']). Está completo, con botón Copiar, en Integraciones › Claves de API (columna Clave pública).", 0, 'missing_key_id');
        }
        $bodyJson = $body !== null ? json_encode($body, JSON_UNESCAPED_UNICODE) : null;
        for ($attempt = 0; ; $attempt++) {
            try {
                return $this->requestOnce($method, $path, $bodyJson, $idempotencyKey);
            } catch (BePagoException $e) {
                if ($attempt >= $this->maxRetries || !self::retryable($e, $method, $idempotencyKey)) throw $e;
                // 250 ms, 500 ms, 1 s… (tope 2 s) más un poco de azar
                usleep((min(250 * (2 ** $attempt), 2000) + random_int(0, 99)) * 1000);
            }
        }
    }

    /**
     * ¿Se puede repetir sin riesgo? GET: red caída, tiempo agotado o 502/503/504.
     * POST: solo con clave de idempotencia y sin respuesta (red o tiempo): con
     * respuesta, la API ya decidió. Nunca un POST sin clave.
     */
    private static function retryable(BePagoException $e, string $method, ?string $idempotencyKey): bool {
        $noResponse = in_array($e->getErrorCode(), ['network_error', 'timeout'], true);
        if ($method === 'GET') return $noResponse || in_array($e->getStatus(), [502, 503, 504], true);
        return $method === 'POST' && $idempotencyKey !== null && $idempotencyKey !== '' && $noResponse;
    }

    private function requestOnce(string $method, string $path, ?string $bodyJson, ?string $idempotencyKey): array {
        // Cada intento con su propio timestamp y nonce: un nonce no se usa dos veces
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
            CURLOPT_TIMEOUT => $this->timeout,
        ]);
        $response = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($response === false) {
            // Sin respuesta no se sabe si la API lo procesó: en pagos, reintentá con la MISMA idempotencyKey.
            $errno = curl_errno($ch);
            $timedOut = $errno === CURLE_OPERATION_TIMEDOUT;
            throw new BePagoException($timedOut ? "bePago: sin respuesta en {$this->timeout} s" : 'bePago: no se pudo conectar con ' . $this->base . ' (' . curl_error($ch) . ')', 0, $timedOut ? 'timeout' : 'network_error');
        }

        $data = $response === '' ? null : json_decode($response, true);
        if ($status >= 400) {
            $e = is_array($data) ? ($data['error'] ?? []) : [];
            throw new BePagoException($e['message'] ?? ("bePago: HTTP $status" . (is_array($data) ? '' : ' (respuesta sin JSON)')), $status,
                $e['code'] ?? (is_array($data) ? null : 'invalid_response'), $e['request_id'] ?? null, $e['details'] ?? null, $data ?? $response);
        }
        if (!is_array($data)) throw new BePagoException('bePago: respuesta sin JSON', $status, 'invalid_response', null, null, $response);
        return $data;
    }

    /** "?limit=20&status=paid" a partir de un arreglo de filtros (sin los vacíos). */
    public static function query(array $params): string {
        $q = http_build_query(array_filter($params, fn($v) => $v !== null && $v !== ''), '', '&', PHP_QUERY_RFC3986);
        return $q === '' ? '' : '?' . $q;
    }

    /**
     * Modo de la clave: true (sk_live_), false (sk_test_) o null si no se sabe.
     * El handler de webhooks lo compara con el `livemode` de cada aviso.
     */
    public static function livemodeOfSecret(string $secret): ?bool {
        if (strpos($secret, 'sk_live_') === 0) return true;
        if (strpos($secret, 'sk_test_') === 0) return false;
        return null;
    }

    /**
     * Un segmento de ruta (tu `reference` o un id), codificado igual que
     * encodeURIComponent de JavaScript (y que los SDK de Node y Python): es lo
     * que viaja y lo que se firma. `urlencode` no sirve (un espacio sería `+`).
     * Vacío, `.` o `..` no son un segmento: cURL los resolvería como ruta.
     */
    public static function segment(string $value): string {
        if ($value === '' || $value === '.' || $value === '..') {
            throw new BePagoException('bePago: referencia o id inválido para la ruta (' . json_encode($value) . ')', 0, 'invalid_reference');
        }
        return strtr(rawurlencode($value), ['%21' => '!', '%2A' => '*', '%27' => "'", '%28' => '(', '%29' => ')']);
    }

    public function charges(): object {
        $self = $this;
        return new class($self) {
            private $sdk;
            public function __construct($sdk) { $this->sdk = $sdk; }
            public function create(array $params): array { return $this->sdk->request('POST', '/v1/charges', $params); }
            public function get(string $ref): array { return $this->sdk->request('GET', '/v1/charges/' . BePago::segment($ref)); }
            /** Cobros del más nuevo al más viejo: data, has_more, next_cursor. Filtros: limit, status, from, to, cursor. */
            public function list(array $params = []): array { return $this->sdk->request('GET', '/v1/charges' . BePago::query($params)); }
            /** Cancela un cobro todavía sin pagar (created/pending). */
            public function cancel(string $ref): array { return $this->sdk->request('POST', '/v1/charges/' . BePago::segment($ref) . '/cancel'); }
            public function refund(string $ref, string $reason): array { return $this->sdk->request('POST', '/v1/charges/' . BePago::segment($ref) . '/refund', ['reason' => $reason]); }
            public function balance(): array { return $this->sdk->request('GET', '/v1/charges/balance'); }
            /**
             * Confirma el estado REAL de un cobro consultando la API (fuente de
             * verdad). Nunca confíes en el navegador ni en el iframe. `paid` es
             * true si el cobro figura `paid` o `settled` (pagado y ya liquidado).
             * @return array{paid:bool,status:?string,charge:array}
             */
            public function confirm(string $ref): array {
                $charge = $this->sdk->request('GET', '/v1/charges/' . BePago::segment($ref));
                $status = $charge['status'] ?? ($charge['data']['status'] ?? null);
                return ['paid' => $status === 'paid' || $status === 'settled', 'status' => $status, 'charge' => $charge];
            }
        };
    }

    public function payouts(): object {
        $self = $this;
        return new class($self) {
            private $sdk;
            public function __construct($sdk) { $this->sdk = $sdk; }
            public function create(array $params, ?string $idempotencyKey = null): array { return $this->sdk->request('POST', '/v1/payouts', $params, BePago::requireIdempotencyKey($idempotencyKey)); }
            public function get(string $id): array { return $this->sdk->request('GET', '/v1/payouts/' . BePago::segment($id)); }
            /** Pagos del más nuevo al más viejo: data, has_more, next_cursor. Filtros: limit, status, from, to, cursor. */
            public function list(array $params = []): array { return $this->sdk->request('GET', '/v1/payouts' . BePago::query($params)); }
            public function cancel(string $id): array { return $this->sdk->request('POST', '/v1/payouts/' . BePago::segment($id) . '/cancel'); }
            public function balance(): array { return $this->sdk->request('GET', '/v1/balance'); }
            /** Lotes de pagos masivos (hasta 1000 por llamada; quedan pendientes de aprobación en el panel). */
            public function batches(): object {
                $sdk = $this->sdk;
                return new class($sdk) {
                    private $sdk;
                    public function __construct($sdk) { $this->sdk = $sdk; }
                    public function create(array $params, ?string $idempotencyKey = null): array { return $this->sdk->request('POST', '/v1/payouts/batches', $params, BePago::requireIdempotencyKey($idempotencyKey)); }
                    public function get(string $id): array { return $this->sdk->request('GET', '/v1/payouts/batches/' . BePago::segment($id)); }
                };
            }
        };
    }

    /** Solo con claves sk_test_: simula que el pagador pagó (el cobro pasa a paid y sale el webhook charge.paid). */
    public function sandbox(): object {
        $self = $this;
        return new class($self) {
            private $sdk;
            public function __construct($sdk) { $this->sdk = $sdk; }
            public function pay(string $ref): array { return $this->sdk->request('POST', '/v1/test/charges/' . BePago::segment($ref) . '/pay'); }
            /**
             * Carga saldo de prueba para pagos masivos (sin monto: la carga estándar).
             * Acepta el monto solo (`fund(5000000)`) o el mismo cuerpo de la API (`fund(['amount' => 5000000])`).
             * @param int|array{amount?: int}|null $amount
             */
            public function fund($amount = null): array {
                $value = is_array($amount) ? ($amount['amount'] ?? null) : $amount;
                return $this->sdk->request('POST', '/v1/test/balance/fund', $value === null ? null : ['amount' => $value]);
            }
        };
    }

    /**
     * Manejador de webhooks seguro-por-defecto. Verifica firma y frescura
     * (±5 min), descarta duplicados por X-BePago-Event-Id y SOLO invoca
     * `onPaid` con eventos `charge.paid` válidos y no vistos; `onEvent` recibe
     * TODOS los eventos (payout.completed, payout.failed, charge.refunded…).
     *
     * Si `onPaid`/`onEvent` lanzan, responde 500 y el evento NO queda como
     * visto: el reintento de bePago lo vuelve a procesar (que tu entrega sea
     * idempotente por orden). El aviso de prueba del panel («Probar mi
     * webhook», `test` true) responde 200 sin llamar a tus funciones. Un aviso
     * del otro modo (`livemode` distinto del de tu clave: de prueba con
     * sk_live_, o real con sk_test_) también: 200 y se ignora.
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
        return new class(self::livemodeOfSecret($this->secret)) {
            private ?bool $keyLivemode;
            public function __construct(?bool $keyLivemode) { $this->keyLivemode = $keyLivemode; }
            public function handler(array $config): object {
                if (empty($config['secret'])) throw new \InvalidArgumentException('bePago webhooks.handler: falta `secret`');
                return new class($config, $this->keyLivemode) {
                    private array $config;
                    private object $store;
                    private ?bool $keyLivemode;
                    public function __construct(array $config, ?bool $keyLivemode) {
                        $this->config = $config;
                        $this->keyLivemode = $keyLivemode;
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
                        if (!empty($evt['test'])) {
                            return ['status' => 200, 'body' => json_encode(['received' => true, 'test' => true]), 'duplicate' => false, 'ok' => true, 'test' => true];
                        }
                        // Aviso del otro modo (de prueba a un cliente con sk_live_, o al revés): 200 y se ignora,
                        // sin callbacks ni marcarlo como visto. Sin clave de modo conocido, como siempre.
                        if ($this->keyLivemode !== null && is_bool($evt['livemode'] ?? null) && $evt['livemode'] !== $this->keyLivemode) {
                            return ['status' => 200, 'body' => json_encode(['received' => true, 'ignored' => 'livemode_mismatch']), 'duplicate' => false, 'ok' => true, 'ignored' => true];
                        }
                        $id = (string)($evt['id'] ?? '');
                        if ($this->store->has($id)) {
                            return ['status' => 200, 'body' => json_encode(['received' => true, 'duplicate' => true]), 'duplicate' => true, 'ok' => true];
                        }
                        try {
                            if (isset($this->config['onEvent']) && is_callable($this->config['onEvent'])) ($this->config['onEvent'])($evt);
                            if (($evt['event'] ?? null) === 'charge.paid' && isset($this->config['onPaid']) && is_callable($this->config['onPaid'])) {
                                ($this->config['onPaid'])(['reference' => $evt['data']['reference'] ?? null, 'data' => $evt['data'] ?? null, 'event' => $evt]);
                            }
                        } catch (\Throwable $e) {
                            // Sin marcar como visto: el reintento lo vuelve a procesar.
                            return ['status' => 500, 'body' => json_encode(['error' => 'handler_error']), 'duplicate' => false, 'ok' => false];
                        }
                        $this->store->add($id);
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
     * `test` es true en el aviso de prueba de «Probar mi webhook» del panel
     * (charge.paid de un cobro que no existe): no entregues nada con él.
     *
     * @return array{id:?string,event:?string,livemode:?bool,merchant_id:?string,test:bool,data:?array}
     */
    public static function verifyWebhook(string $webhookSecret, array $event, int $toleranceSeconds = 300): array {
        $timestamp = (string)($event['timestamp'] ?? '');
        $body = (string)($event['body'] ?? '');
        $signature = (string)($event['signature'] ?? '');
        $expected = hash_hmac('sha256', $timestamp . $body, $webhookSecret);
        if (!hash_equals($expected, $signature)) {
            throw new BePagoException('bePago webhook: firma inválida', 0, 'invalid_signature');
        }
        $ageMs = abs((int)(microtime(true) * 1000) - (int)$timestamp);
        if ($ageMs > $toleranceSeconds * 1000) {
            throw new BePagoException('bePago webhook: timestamp fuera de la ventana permitida', 0, 'invalid_timestamp');
        }
        $payload = json_decode($body, true) ?? [];
        return [
            'id' => $event['id'] ?? ($payload['id'] ?? null),
            'event' => $payload['event'] ?? null,
            'livemode' => $payload['livemode'] ?? null,
            'merchant_id' => $payload['merchant_id'] ?? null,
            'test' => ($payload['test'] ?? false) === true,
            'data' => $payload['data'] ?? null,
        ];
    }
}
