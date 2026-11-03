# Cambios — bePago SDK para PHP

## 1.6.3 — 2026-11-03

- `sandbox()->fund()` acepta también el cuerpo de la API: `fund(['amount' => 5000000])` (además de `fund(5000000)` y `fund()`).

## 1.6.2 — 2026-11-03

- `sandbox()->fund(?int $amount)`: carga saldo de prueba para pagos masivos (`POST /v1/test/balance/fund`, solo `sk_test_`).

## 1.6.1 — 2026-11-03

- El evento de `verifyWebhook` / `onEvent` trae `merchant_id` (tu ID de comercio), como el cuerpo real del aviso.

## 1.6.0 — 2026-11-03

- **Reintentos automáticos** (`'maxRetries'`, 2 por defecto; `0` los apaga):
  las consultas (GET) ante red caída, tiempo agotado o `502`/`503`/`504`; los
  pagos y lotes (POST con clave de idempotencia) solo ante red caída o tiempo
  agotado, con la misma clave. Un POST sin clave nunca se reintenta. Cada
  intento se firma de nuevo.

## 1.5.0 — 2026-11-03

- El Key ID (`pk_…`) sale del secreto: `new BePago($secret)` alcanza con las
  claves nuevas; con las de un formato anterior, excepción clara que pide
  `['keyId' => …]`.
- `BePagoException` con `getStatus()` (0 sin respuesta), `getErrorCode()`,
  `getRequestId()`, `getDetails()` y `getResponse()`; `network_error`,
  `timeout` e `invalid_response` cuando no hay respuesta JSON de la API.
- `'baseUrl'` acepta la dirección con `/v1` al final.
- Nuevos: `charges()->list()`, `charges()->cancel()`, `payouts()->list()`
  (paginados por cursor) y `sandbox()->pay()`.
- `webhooks()->handler()`: el aviso de prueba del panel (`test` true) responde
  200 sin llamar a tus funciones.

## 1.4.0 — 2026-11-03

- La clave de idempotencia es obligatoria en `payouts()->create()` y
  `payouts()->batches()->create()` (mín. 8 caracteres), igual que la API.
- Cobros con `'method'` (`qr_sip`, `pix`, `qr_ar`); README alineado con la guía
  y con el checkout embebido (`bepago.js`).

## 1.3.0 — 2026-10-26

- Lotes de pagos masivos: `payouts()->batches()->create()` y `->get()`.

## 1.2.0 — 2026-10-25

- `charges()->confirm($ref)`: confirma el estado real de un cobro contra la API.
- `webhooks()->handler()`: verifica firma y frescura, descarta duplicados por id
  de evento (con un `store` propio), invoca `onPaid`/`onEvent` y responde 500 si
  tu función lanza. `process()` devuelve status y body para PSR-7.

## 1.1.0 — 2026-10-23

- `BePago::verifyWebhook()` rechaza timestamps fuera de ±5 minutos y devuelve el
  `id` del evento para deduplicar.

## 1.0.0 — 2026-10-23

- Primera versión estable como paquete Composer `bepago/sdk` (PSR-4,
  `Bepago\BePago`), mismo archivo que la descarga directa `BePago.php`.
