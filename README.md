# bePago — SDK para PHP

SDK oficial de [bePago](https://bepago.com) para PHP 7.4+. Usa solo las
extensiones `curl` y `json`, presentes en cualquier PHP estándar.

Cobros con checkout embebido, pagos masivos (payouts) y webhooks firmados
(HMAC v2). Es el mismo SDK de la [guía de integración](https://bepago.com/docs):
cada ejemplo de acá funciona copiado tal cual.

## Instalación

```bash
composer require bepago/sdk:^1.6
```

¿Sin Composer? Bajá el archivo único [`BePago.php`](https://bepago.com/sdk/BePago.php)
y usá `require_once 'BePago.php';` — es exactamente el mismo código que el paquete.

## Uso

```php
require 'vendor/autoload.php'; // o: require_once 'BePago.php';

use Bepago\BePago;

$bp = new BePago(getenv('BEPAGO_SECRET')); // sk_test_… o sk_live_…: la clave decide el modo
```

La clave (`sk_…`) está en tu panel (Integraciones › Claves de API): el secreto
lleva adentro la clave pública (`pk_…`), así que alcanza con él. Las claves de un
formato anterior no la traen: el SDK te la pide con un error claro y la pasás
aparte, copiándola completa de la tabla de claves:
`new BePago($secret, ['keyId' => 'pk_…'])`.

Opciones: `'baseUrl' => 'https://bepago.com/api'` (con o sin `/v1` al final),
`'timeout' => 30` (segundos, por intento) y `'maxRetries' => 2` (ver Reintentos).

### Reintentos

El SDK reintenta solo lo que se puede repetir sin duplicar nada, hasta
`'maxRetries'` veces (2 por defecto; `0` los apaga), con esperas cortas que se
duplican (250 ms, 500 ms…):

- **Consultas (GET):** ante red caída, tiempo agotado o un `502`/`503`/`504`.
- **Pagos y lotes (POST con clave de idempotencia):** solo ante red caída o
  tiempo agotado, siempre con la **misma** clave: si el primer intento llegó,
  la API devuelve el mismo pago, no crea otro.
- **Nunca** un POST sin clave de idempotencia (crear un cobro, reembolsar,
  cancelar) ni una respuesta de la API (4xx, o un 5xx de un POST): esos te
  llegan como excepción para que decidas vos.

Cada intento va firmado de nuevo (timestamp y nonce propios). Si se agotan, la
excepción es la del último intento.

### Errores

Todo error lanza `Bepago\BePagoException` (extiende `RuntimeException`):
`getStatus()` es el HTTP (0 si no hubo respuesta), `getErrorCode()` el código de
bePago, `getRequestId()` y `getDetails()`. Sin respuesta de la API (red caída,
tiempo agotado) el código es `network_error` o `timeout`: en pagos, reintentá
con la **misma** clave de idempotencia.

```php
try {
    $bp->payouts()->create($pago, 'pago-0001');
} catch (\Bepago\BePagoException $e) {
    error_log($e->getStatus() . ' ' . $e->getErrorCode() . ' ' . $e->getRequestId());
}
```

## Cobros

### 1. Crear el cobro (en tu backend)

```php
$cobro = $bp->charges()->create([
    'reference'   => 'pedido-1842',  // tu orden: misma referencia = mismo cobro
    'currency'    => 'PYG',
    'amount'      => 150000,         // guaraníes, sin decimales; el monto sale de tu servidor
    'description' => 'Pedido 1842',
    // 'method'   => 'qr_sip',       // opcional: qr_sip | pix | qr_ar (ver abajo)
]);

$cobro['checkout_url']; // lo que embebés en tu página
```

- **Monto mínimo:** lo fija el medio de pago (QR SIP: Gs 1.000) y, si lo
  configuraste, el mínimo propio de tu comercio. Debajo, `422
  charge_amount_invalid` (límites del medio) o `422 charge_below_minimum` (tu
  mínimo; `details.minimum` dice cuánto).
- **`method` (opcional):** fuerza el método de este cobro. Tiene que estar
  activo en tu panel (Integraciones › Métodos de cobro); si no,
  `422 collect_method_not_enabled` con los activos en `details.enabled_methods`.
  Con un método forzado (o si tenés uno solo activo), el checkout va directo al
  QR, sin selector. Sin `method`, el pagador elige entre tus métodos activos.

Consultar, reembolsar y ver saldo:

```php
$bp->charges()->get('pedido-1842');
$bp->charges()->list(['status' => 'paid', 'limit' => 50]);   // data, has_more, next_cursor
$bp->charges()->cancel('pedido-1843');                       // uno todavía sin pagar
$bp->charges()->refund('pedido-1842', 'cliente canceló');
$bp->charges()->balance();
$bp->sandbox()->pay('pedido-1844');                          // solo sk_test_: simula el pago
```

La misma referencia devuelve siempre el mismo cobro, también vencido: si el
cobro de una orden venció, creá otro con otra referencia (`pedido-1842-2`).

### 2. Embeber el checkout en tu página (iframe, `bepago.js`)

El checkout se monta en un iframe **dentro** de tu `div`, sin redirigir ni abrir
otra ventana. Es compacto y sin estilo propio (fondo transparente, sin bordes,
máx. 320 px centrado): el monto en una línea chica, el QR (220 px en
escritorio, 180 px en celular) y debajo
“Esperando pago · vence en 4:50 · **Descargar QR**”. *Descargar QR* baja el QR
como PNG (`bepago-qr-<referencia>.png`, fondo blanco) para abrirlo desde la app
del banco; en celular, si el navegador lo permite, abre compartir/guardar
imagen. El iframe toma el alto de su contenido.

```html
<div id="bepago-checkout"></div>
<script src="https://bepago.com/sdk/bepago-1.5.0.js"
        integrity="sha384-rNIcmH1wI1RykstAPx0YrfMuW7+1WVXqzjTDqNYUgQlk65R8N9CRIauntZQJoHkI" crossorigin="anonymous"></script>
<script>
  BePago.checkout({
    url: '<?= htmlspecialchars($cobro['checkout_url']) ?>',
    mount: '#bepago-checkout',
    theme: 'light',             // 'dark' si tu página tiene fondo oscuro
    showAmount: true,           // false si ya mostrás el monto en tu página
    onPaid: ({ reference }) => {
      // SOLO UI: mostrá "¡Gracias!" o redirigí. La confirmación es de tu backend.
      location.href = '/gracias/' + reference;
    },
  });
</script>
```

`onPaid` llega cuando el checkout avisa `{ type: 'bepago:paid', reference }`
(postMessage, solo a los dominios que registraste en Integraciones › Dominio del
checkout). Corre en el navegador del comprador y se puede falsificar: **nunca
entregues desde ahí**. `onComplete({ reference, status })` llega al terminar el
checkout, en cualquier estado.

### 3. Recibir el webhook y entregar (en tu backend)

El handler verifica firma y frescura (±5 min), descarta duplicados por
`x-bepago-event-id`, responde 200 y **solo** invoca `onPaid` con eventos
válidos; `onEvent` recibe todos los eventos (`payout.completed`, `payout.failed`…).
Antes de entregar, reconfirmá por API con `charges()->confirm()` (`paid` es true
con `paid` o `settled`) y compará el monto contra tu orden.

- Si tu función lanza, responde 500 y el evento **no** queda como visto: el
  reintento de bePago lo vuelve a procesar.
- El aviso de prueba de «Probar mi webhook» llega con `"test": true`: el handler
  responde 200 sin llamar a tus funciones.

```php
use Psr\Http\Message\ServerRequestInterface as Req;
use Psr\Http\Message\ResponseInterface as Res;

$wh = $bp->webhooks()->handler([
    'secret' => getenv('BEPAGO_WEBHOOK_SECRET'),
    'store'  => $misEventIds,   // has()/add() contra tu base: no entregar dos veces
    'onPaid' => function (array $c) use ($bp, $db) {
        $order = $db->orders->findByChargeReference($c['reference']);
        $r = $bp->charges()->confirm($c['reference']);   // reconfirmamos por API
        if ($r['paid'] && $order && (int) $r['charge']['amount'] === (int) $order->amount) entregar($order);
    },
]);

$app->post('/webhooks/bepago', function (Req $req, Res $res) use ($wh) {
    $headers = [];
    foreach ($req->getHeaders() as $k => $v) $headers[$k] = $v[0] ?? '';
    $out = $wh->process($headers, (string) $req->getBody());   // cuerpo CRUDO
    $res->getBody()->write($out['body']);
    return $res->withStatus($out['status'])->withHeader('Content-Type', 'application/json');
});
```

PHP plano (sin framework):

```php
$headers = [];
foreach (getallheaders() as $k => $v) $headers[$k] = $v;
$out = $wh->process($headers, file_get_contents('php://input'));
http_response_code($out['status']);
header('Content-Type: application/json');
echo $out['body'];
```

## Pagos masivos (payouts)

La **clave de idempotencia es obligatoria** en pagos y en lotes (mín. 8
caracteres, única por pago o lote), igual que en la API: el SDK la exige antes
de mandar el pedido. Repetir el mismo pedido con la misma clave devuelve el
mismo pago, sin crear otro.

```php
$payout = $bp->payouts()->create([
    'amount' => 1500000, 'currency' => 'PYG',
    'destination' => ['type' => 'alias', 'alias' => '0981600123'],
], 'idem-key-unica-por-pago');

$bp->payouts()->get($payout['id']);
$bp->payouts()->list(['status' => 'failed']);
$bp->payouts()->cancel($payout['id']);
$bp->payouts()->balance();
```

- **Monto mínimo por pago:** lo fija bePago (hoy Gs 1.000). Uno menor se rechaza
  con `422 amount_below_minimum` y el mensaje dice el mínimo vigente; en un lote
  se rechaza esa fila y las demás siguen.

Lote (hasta 1000 pagos; queda pendiente de aprobación en el panel, cuatro ojos,
salvo auto-aprobación por API habilitada):

```php
$lote = $bp->payouts()->batches()->create([
    'payouts' => [['amount' => 500000, 'destination' => ['type' => 'alias', 'alias' => '0981600123'], 'external_id' => 'nom-oct-001']],
], 'idem-key-unica-por-lote');
$bp->payouts()->batches()->get($lote['id']);
```

## Documentación

Guía completa, referencia de la API, eventos y errores:
**https://bepago.com/docs**

## Licencia

MIT
