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

### Verificar un webhook

bePago firma cada webhook con `HMAC-SHA256` sobre `timestamp + body` usando el
secreto del webhook. Verificá la firma antes de procesar el evento:

```php
use Bepago\BePago;

$timestamp = $_SERVER['HTTP_X_TIMESTAMP'] ?? '';
$signature = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
$rawBody   = file_get_contents('php://input'); // el cuerpo tal cual llegó

$ok = BePago::verifyWebhook('whsec_tu_secreto_de_webhook', $timestamp, $rawBody, $signature);

if (!$ok) {
    http_response_code(400);
    exit('firma inválida');
}

$evento = json_decode($rawBody, true);
// procesar $evento['type'] …
```

## Documentación

Referencia completa de la API, tipos de eventos y ejemplos:
**https://bepago.com/docs**

## Licencia

MIT
