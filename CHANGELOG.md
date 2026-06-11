# CHANGELOG

## v2.2.0
- **feat(finanzas-ventova):** Nuevo plugin **Finanzas Ventova** (v2.10) — tesorería y contabilidad básica: cuentas en Bs/USD, movimientos (ingreso/egreso/transferencia) con saldo corrido y validación, categorías/grupos contables, y reportes (flujo de caja, gastos por categoría, Estado de Resultados con CMV desde el Kardex de Inventario y retención IBEX 7%). Integraciones suaves (`class_exists`) con Inventario y DEMV.
- **feat(inventory-csv-ventova):** Módulo de **Compras** completo (v3.49): borrador → recepción por variación (con reparto por color/sucursal), proveedores (CRUD), costos al recibir y **Kardex** central con saldo corrido.
- **feat(inventory-csv-ventova):** **Gastos de importación** por compra integrados con Finanzas: flujo registrar → facturar, caja y **moneda dual** (Bs/$ + TC por gasto), y **TC promedio ponderado** (Σ Bs ÷ Σ $) de los gastos facturados.
- **feat(inventory-csv-ventova):** **Costo de origen (Exw USD)** por línea con subtotal editable (unitario = subtotal ÷ cantidad); logística de importación (Nº tracking, ETA, bultos, peso bruto, CBM, vía) y listado de compras con estados Borrador/Tránsito/Completada.
- **feat(inventory-csv-ventova):** Recibir **sin afectar inventario** (compras previas al plugin) y registrar gastos después de recibir.
- **feat(traspasos-ventova):** Integración con el **Kardex de Inventario** (v4.7) — los traspasos registran `transfer_out`/`transfer_in` en el Kardex cuando el plugin de Inventario está activo (dependencia suave); standalone sigue moviendo stock directo.
- **docs(complementos):** PLUGIN.md de finanzas, inventory y traspasos sincronizados con el código.

## v2.1.0
- **feat(demv):** Se implementaron filtros multi-selección (checkboxes) para departamento y método de envío.
- **feat(demv):** Se agregó panel de estadísticas (Total Depositado, Total, Costo de Envío, 7% IBEX).
- **feat(demv):** Nueva tabla de top usuarios por ventas en el panel de estadísticas.
- **feat(demv):** Se agregó funcionalidad "Pagar Envío" con soporte para pago masivo de envíos filtrados y nueva columna "F. Pago Envío".
- **feat(demv):** Soporte integrado para vista y exportación CSV de Traspasos.
- **feat(demv):** Se incrementó la versión del plugin a 3.6.

## v2.0.2
- **feat(demv):** Se incrementó la versión de HPOS Ardxoz Woo DEMV a 3.1.
- **feat(demv):** Se añadió filtro por sucursal (`pa_sucursal`) en la vista de depósitos.
- **feat(demv):** Se implementó edición en línea para el costo de envío con recálculo automático del monto de depósito.

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
