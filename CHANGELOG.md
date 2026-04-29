# CHANGELOG

## v2.0.1
- **feat(print-note):** Se incrementó la versión de HPOS Ardxoz Woo Print Note a 3.1.
- **fix(print-note):** Rediseño y alineación del botón de impresión usando iconos nativos de WooCommerce y estilos de Dashicons.

## v2.0.0
- **feat(plugins):** Actualización mayor (Major) de versiones para complementos HPOS principales.
- **feat(actions):** Se incrementó la versión de HPOS Ardxoz Woo Actions a 2.0.
- **feat(demv):** Se incrementó la versión de HPOS Ardxoz Woo DEMV a 3.0.
- **feat(metaorder):** Se incrementó la versión de HPOS Ardxoz Woo MetaOrder a 4.0.

## v1.3.0
- **feat(woo):** Nueva lógica de promoción de Cajas de Regalo automáticas basada en umbral de precio (350 Bs).
- **feat(woo):** Prorrateo inteligente de precios en cajas manuales cuando se aplica la promoción.
- **feat(woo):** Vinculación automática de regalos según la sucursal del producto principal.
- **chore:** Sincronización de versiones para `ventova-store-child` y `hpos-ardxoz-pagoqr`.


## v1.2.1
- **fix(autoreload):** Exclusión de `armandxcrazy@gmail.com` para evitar recargas accidentales durante el uso de DEMV.
- **fix(autoreload):** Ajuste de z-index a 9999 para mejorar compatibilidad visual con modales de otros plugins.
- **chore:** Limpieza de lógica de frontend (`MutationObserver`) en favor de verificación de backend.

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
