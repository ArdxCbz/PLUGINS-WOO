# Pago QR HPOS

- **Slug:** `hpos-ardxoz-woo-pagoqr` (archivo principal `hpos-ardxoz-woo-pagoqr.php`)
- **Versión:** 1.3.1
- **Autor:** Ventova
- **Requiere:** WooCommerce (HPOS compatible), WP ≥ 5.8, PHP ≥ 7.4
- **Prefijos:** clase gateway `HPOS_ARDXOZ_WC_Gateway_QR` · funciones `hpos_ardxoz_pagoqr_*` · gateway id `hpos_ardxoz_pagoqr` · meta `_hpos_ardxoz_pagoqr_receipt` · text-domain `hpos-ardxoz-pagoqr`

## Propósito

Pasarela de pago **por código QR** para WooCommerce, compatible con HPOS y con el checkout de bloques. Muestra un
QR configurable en un popup durante el checkout, permite al cliente **adjuntar el comprobante de pago** (captura),
lo guarda en un directorio privado y lo enlaza al pedido como meta. El pedido queda en `on-hold` esperando
validación manual. El comprobante se visualiza en un metabox lateral de la pantalla de pedido.

## Archivos clave

| Archivo | Responsabilidad |
|---|---|
| `hpos-ardxoz-woo-pagoqr.php` | Bootstrap. Declara compat HPOS, registra el gateway en `woocommerce_payment_gateways`, integra con Blocks, y **define la clase `HPOS_ARDXOZ_WC_Gateway_QR`** (settings, `payment_fields`, `process_payment`). |
| `inc/functions.php` | Soporte: scripts admin/front, campos de imagen (icono/QR) en settings, **popup del checkout** (`wp_footer`), handler AJAX de subida de comprobante, directorio de subida privado, y el **metabox del comprobante** en el pedido. |
| `inc/class-blocks-integration.php` | Integración con WooCommerce Blocks Checkout (`AbstractPaymentMethodType`). Expone título/descripción/icono al checkout de bloques. |
| `assets/js/front.js` | Lógica del checkout: intercepta "Realizar pedido" (legacy y bloques), abre el popup, gestiona subir comprobante / finalizar directo, drag&drop, descarga y compartir del QR. |
| `assets/js/admin.js` | Cargador de medios (WP media) para los campos de icono y QR en los settings del gateway. |
| `assets/js/blocks.js` | Componente JS del método de pago en el checkout de bloques. |

## Gateway: `HPOS_ARDXOZ_WC_Gateway_QR`

`id = 'hpos_ardxoz_pagoqr'`, `has_fields = false`, `supports = ['products']`. Settings (`init_form_fields`):

| Setting | Tipo | Uso |
|---|---|---|
| `enabled` | checkbox | Activa el método. |
| `title` / `description` | text/textarea | Texto en el checkout. |
| `upload_icon` + `preview_icon` | custom + hidden | Icono junto al nombre del método. |
| `number_telephone` | text | Contacto/billetera mostrado en el popup. |
| `upload_qr` + `preview_qr` | custom + hidden | Imagen del QR a escanear. |
| `limit_amount` | text | Monto máximo permitido (validado en JS). |
| `message_limit_amount` | text | Mensaje si se supera el límite. |

Los campos `preview_icon`/`preview_qr` (hidden) guardan la **URL** del adjunto seleccionado con el media uploader;
los `upload_*` solo renderizan el botón (tipos custom `hpos_ardxoz_pagoqr_icon`/`_image`).

## `process_payment()`

1. Lee `WC()->session->get('hpos_ardxoz_pagoqr_receipt_url')` (lo dejó el AJAX de subida).
2. Si existe, lo guarda en el pedido como meta `_hpos_ardxoz_pagoqr_receipt` (`esc_url_raw`) y limpia la sesión.
3. Cambia el pedido a **`on-hold`** ("Esperando validación del pago por QR").
4. `wc_reduce_stock_levels()`, vacía el carrito y redirige al `return_url`.

## Meta keys del pedido (HPOS)

| Meta | Valor | Uso |
|---|---|---|
| `_hpos_ardxoz_pagoqr_receipt` | URL | Comprobante de pago subido. Lo escribe `process_payment`, lo lee el metabox. |

## Hooks que registra

**Actions:**
- `before_woocommerce_init` → declara compat HPOS.
- `plugins_loaded` → textdomain + define/instancia el gateway (`hpos_ardxoz_pagoqr_init_gateway_class`).
- `woocommerce_blocks_loaded` + `woocommerce_blocks_payment_method_type_registration` → registra la integración de bloques.
- `admin_enqueue_scripts` → media uploader + assets admin (solo en `wc-settings`).
- `wp_enqueue_scripts` → assets front (solo en checkout legacy o bloque `woocommerce/checkout`).
- `wp_footer` → renderiza el popup del QR (solo checkout, solo si el gateway está `enabled`).
- `add_meta_boxes` → metabox "Comprobante de Pago QR" (pantalla `shop_order` legacy + `wc-orders` HPOS).
- `woocommerce_update_options_payment_gateways_hpos_ardxoz_pagoqr` → guarda settings.

**Filters:**
- `woocommerce_payment_gateways` → alta del gateway.
- `upload_dir` (temporal, solo durante la subida) → redirige al subdirectorio privado.

**AJAX:**
- `wp_ajax_hpos_ardxoz_pagoqr_upload` y `wp_ajax_nopriv_*` → sube el comprobante (cliente no logueado incluido). Verifica el nonce `hpos_ardxoz_pagoqr_upload` y valida tamaño (≤8 MB) y tipo real de imagen antes de aceptar el archivo.

## Dependencias

- **WooCommerce** + `WC_Payment_Gateway`. Compatible con HPOS y con el checkout de bloques (degrada a settings por defecto si Blocks no está).
- Sin dependencia de otros plugins propios. El método de pago que produce (`Pago QR`) es leído por [[plugin-hpos-ardxoz-woo-demv]] en su lógica de pagos (`PAYMENT_QR = 'Pago QR'`) y por las columnas de [[plugin-hpos-ardxoz-woo-orders]].

## Reglas de negocio no obvias

1. **Comprobante vía sesión, no en el submit** — El archivo se sube por AJAX **antes** de finalizar el pedido y se guarda en `WC()->session`. `process_payment` lo rescata de la sesión y lo escribe como meta. Si la sesión se pierde entre subida y finalización, el pedido queda sin comprobante.
2. **"Finalizar ahora" sin comprobante** — El popup ofrece dos rutas: *Subir Comprobante* (paso 2) o *Finalizar ahora* (directo). El comprobante es **opcional** en la práctica pese al texto "obligatorio" en los params JS. Un pedido puede quedar en `on-hold` sin comprobante adjunto.
3. **Doble interceptación de checkout** — `front.js` intercepta tanto el botón legacy (`#place_order` con clase `hpos-ardxoz-pagoqr-active`) como el de bloques (`.wc-block-components-checkout-place-order-button`). El flag `popupCompleted` evita el bucle: tras el popup, re-dispara el submit real dejándolo pasar.
4. **Pedido siempre `on-hold`** — Todo pago QR entra en espera de validación manual; nunca se auto-completa. La conciliación (depósito/efectivo) la hacen DEMV/Actions después.
5. **Directorio privado para comprobantes** — Las imágenes se suben a `wp-content/uploads/hpos-ardxoz-comprobantes-qr/` con un `index.html` vacío para evitar listado de directorio. Es **privacidad por oscuridad**: la URL sigue siendo pública si se conoce.
6. **Límite de monto solo en cliente** — `limit_amount` se valida en JS (atributo `data-limit` del popup). No hay validación server-side: un POST manual podría superar el límite.
7. **Compat HPOS en el metabox** — `register_meta_box` agrega la pantalla `woocommerce_page_wc-orders` solo si existe `CustomOrdersTableController`; el render acepta tanto `WP_Post` (legacy) como `WC_Order` (HPOS).
8. **Subida protegida por nonce + validación** (desde v1.3.1) — El handler AJAX `hpos_ardxoz_pagoqr_upload` verifica el nonce `hpos_ardxoz_pagoqr_upload` (creado en `wp_localize_script`, válido también para invitados por la sesión de WC) y valida server-side: tamaño ≤ 8 MB y tipo **real** de imagen (`wp_check_filetype_and_ext` + `mimes` allowlist jpg/png/gif/webp). Sigue aceptando `nopriv` por diseño (clientes no logueados en checkout), pero ya no acepta POST anónimos sin token ni archivos no-imagen.

## Issues conocidos / deuda técnica

- **Rate-limit de subida** — Resuelto el nonce + validación de tipo/tamaño en v1.3.1 (ver regla 8). **Pendiente:** no hay límite de frecuencia; un cliente con sesión válida podría subir muchas imágenes en bucle. Considerar throttle por IP/sesión si se observa abuso.
- **Comprobante opcional pese a "obligatorio"** — El texto de UI dice que es obligatorio, pero "Finalizar ahora" lo salta. Inconsistencia funcional: decidir si debe ser exigible.
- **Sin limpieza de huérfanos** — Si la sesión se pierde, la imagen subida queda en el directorio sin pedido asociado. No hay GC.
- **Validación de límite client-side** — `limit_amount` es trivialmente evitable. Mover a `process_payment` o a `woocommerce_checkout_process` si debe ser estricto.
- **`new HPOS_ARDXOZ_WC_Gateway_QR()` repetido** — Se instancia el gateway varias veces (popup, blocks data) solo para leer opciones; podría cachearse o leer la option directamente.
- **Privacidad por oscuridad** — Los comprobantes (pueden contener datos bancarios del cliente) son accesibles por URL directa. Para datos sensibles, servir vía endpoint autenticado.
