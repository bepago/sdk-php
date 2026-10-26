# bePago — SDK para PHP

SDK oficial de [bePago](https://bepago.com) para PHP 7.4+. Sin dependencias
(solo las extensiones `curl` y `json`, presentes en cualquier PHP estándar).

Cobros, pagos masivos (payouts) y verificación de webhooks con firma HMAC v2.

## Instalación

```bash
composer require bepago/sdk
```

¿Sin Composer? Bajá el archivo único [`BePago.php`](https://bepago.com/sdk/BePago.php)
y usá `require_once 'BePago.php';` — es exactamente el mismo código que el paquete.

## Uso

```php
require 'vendor/autoload.php'; // o: require_once 'BePago.php';

use Bepago\BePago;

$bp = new BePago('sk_live_tu_secreto');
```

La clave (`sk_…`) la obtenés en tu panel de bePago. El key ID público (`pk_…`)
se deriva solo; si necesitás pasarlo aparte: `new BePago($secret, ['keyId' => 'pk_…'])`.

### Crear un cobro

```php
$cobro = $bp->charges()->create([
    'reference'   => 'pedido-001',
    'currency'    => 'PYG',
    'amount'      => 150000,
    'description' => 'Pedido #001',
]);

echo $cobro['checkout_url']; // link de pago para el cliente
```

Consultar, reembolsar y ver saldo:

```php
$bp->charges()->get('pedido-001');
$bp->charges()->refund('pedido-001', 'cliente canceló');
$bp->charges()->balance();
```

### Pago masivo (payout)

```php
$payout = $bp->payouts()->create([
    'currency'    => 'PYG',
    'amount'      => 500000,
    'destination' => ['alias' => 'juan.perez', 'holder_name' => 'Juan Pérez'],
], 'idem-key-unica'); // clave de idempotencia (obligatoria en payouts)

$bp->payouts()->get($payout['id']);
$bp->payouts()->cancel($payout['id']);
$bp->payouts()->balance();
```

Lote de pagos (hasta 1000 por llamada; queda pendiente de aprobación en el
panel, cuatro ojos, salvo auto-aprobación por API habilitada):

```php
$lote = $bp->payouts()->batches()->create([
    'payouts' => [['amount' => 150000, 'destination' => ['type' => 'alias', 'alias' => 'juan.perez'], 'external_id' => 'premio-88']],
], 'idem-key-unica');
$bp->payouts()->batches()->get($lote['id']);
```

### Verificar un webhook

bePago firma cada webhook con `HMAC-SHA256` sobre `timestamp + body` usando el
secreto del webhook. `verifyWebhook` chequea la firma **y la frescura del
timestamp** (rechaza entregas fuera de ±5 minutos por defecto, configurable) y
devuelve el evento ya parseado, con su `id`:

```php
use Bepago\BePago;

$rawBody = file_get_contents('php://input'); // el cuerpo tal cual llegó

try {
    $evt = BePago::verifyWebhook('whsec_tu_secreto_de_webhook', [
        'id'        => $_SERVER['HTTP_X_BEPAGO_EVENT_ID'] ?? null,
        'timestamp' => $_SERVER['HTTP_X_WEBHOOK_TIMESTAMP'] ?? '',
        'signature' => $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '',
        'body'      => $rawBody,
    ]);
} catch (\RuntimeException $e) {
    http_response_code(400);
    exit('webhook inválido'); // firma o timestamp fuera de ventana
}

// Anti-replay: cada evento trae un id ESTABLE entre reintentos. Guardá los ids
// ya procesados y descartá los duplicados (los reintentos reenvían el mismo id
// con un timestamp nuevo).
if (ya_procesado($evt['id'])) { http_response_code(200); exit; }
marcar_procesado($evt['id']);

// procesar $evt['event'] / $evt['data'] …
http_response_code(200);
```

Tolerancia configurable (ej. ±10 minutos): pasá el 3er argumento
`BePago::verifyWebhook($secret, [...], 600)`.

## Documentación

Referencia completa de la API, tipos de eventos y ejemplos:
**https://bepago.com/docs**

## Licencia

MIT
