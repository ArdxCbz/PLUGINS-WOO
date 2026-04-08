# CHANGELOG

## v1.2.0
- **feat(pago-qr):** Nueva pasarela `hpos-ardxoz-pagoqr` optimizada para HPOS.
- **feat(pago-qr):** Soporte nativo para WooCommerce Blocks (nuevo Checkout).
- **feat(pago-qr):** Funcionalidad de descarga y compartición de código QR en el checkout.
- **feat(pago-qr):** Mejora de estabilidad usando `WC()->session` y flujo de carga de comprobante opcional.
- **feat(pago-qr):** Diseño de popup rediseñado 100% en español y responsivo.

## v1.1.1
- **fix:** Se movió el tooltip de cuenta regresiva (autoreload) hacia la izquierda para evitar traslapos con la UI de WordPress.
- **fix:** Se bloqueron las rutas de feed y marketing (React) en el menú para el rol vendedor.

## v1.1.0 (migraciones)
- **feat:** Incorporación oficial del child-theme `ventova-store-child` a los complementos.
- **feat:** Se agregó auto-recarga desatendida en modo HPOS (`admin-orders-autoreload.php`) para forzar refresco de órdenes cada 3 minutos, aplicable sólo a perfiles Administrador y Vendedor.
