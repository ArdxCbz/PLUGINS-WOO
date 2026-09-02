# Plan de Mejora y Refactorización: `hpos-ardxoz-woo-orders`

- **Plugin:** `hpos-ardxoz-woo-orders`
- **Versión objetivo:** 7.13
- **Fecha:** Septiembre 2026
- **Ubicación:** `f:\VENTOVA\produccion\complementos\hpos-ardxoz-woo-orders\`

---

## 🎯 Objetivos del Plan

1. **Centralización y Extensibilidad de Formas de Envío:**
   - Crear una clase helper `Shipping_Methods` para definir las formas de envío disponibles (`IBEX`, `CBS`, `SUECIA`, `LOCAL`, `ENCOMIENDA`) y sus badges de colores asociados (`#8B4513`, `#3498db`, `#2ecc71`, `#2980b9`, `#9b59b6`).
   - Agregar el filtro WordPress `hawo_shipping_methods` para que se puedan sumar o modificar métodos de envío desde otros complementos.

2. **Unificación de Departamentos de Bolivia:**
   - Crear `Location_Helper` con la lista única de códigos ISO de departamentos (`BO-S`, `BO-L`, `BO-C`, `BO-P`, `BO-B`, `BO-T`, `BO-N`, `BO-H`, `BO-O`).
   - Corregir la inconsistencia de `BO-H` unificándolo a "Chuquisaca" tanto en la columna de Ruta como en Datos del Cliente.

3. **Centralización de Sucursales de Origen:**
   - Mapear las sucursales `COCHABAMBA` (CBBA), `SANTA CRUZ` (SCZ) y `LA PAZ` (LPZ) con sus estilos visuales desde `Location_Helper`.

4. **Extracción de Assets CSS/JS Estáticos:**
   - Mover estilos inline del modal y de la columna a `assets/css/orders-admin.css`.
   - Mover el script interactivo del modal y la llamada AJAX a `assets/js/orders-admin.js`.
   - Registrar y encolar adecuadamente los assets mediante `wp_enqueue_script` / `wp_enqueue_style` únicamente en la pantalla `wc-orders`.

5. **Flexibilización de Permisos:**
   - Permitir que usuarios con `manage_woocommerce` (como `shop_manager`) puedan acceder a la edición en modal mediante el filtro `hawo_can_edit_order_info`.

---

## 🛠 Archivos a Crear y Modificar

| Acción | Archivo | Descripción |
|---|---|---|
| **[NUEVO]** | `includes/class-shipping-methods.php` | Helper estático de métodos de envío y sus colores + filtro WP. |
| **[NUEVO]** | `includes/class-location-helper.php` | Helper estático de departamentos de Bolivia y badges de sucursales. |
| **[NUEVO]** | `assets/css/orders-admin.css` | Estilos CSS para el modal unificado y la celda `order_info`. |
| **[NUEVO]** | `assets/js/orders-admin.js` | Lógica JS del modal, delegación de eventos y guardado AJAX. |
| **[MODIFICAR]** | `hpos-ardxoz-woo-orders.php` | Subir versión a 7.13 y encolar los nuevos assets CSS/JS. |
| **[MODIFICAR]** | `includes/class-info-column.php` | Usar `Shipping_Methods` y renderizar HTML limpio del modal. |
| **[MODIFICAR]** | `includes/class-status-location-column.php` | Usar `Location_Helper` para departamentos y sucursales. |
| **[MODIFICAR]** | `includes/class-customer-column.php` | Usar `Location_Helper` para la lista de departamentos. |
| **[MODIFICAR]** | `PLUGIN.md` | Actualizar la documentación a la versión 7.13. |

---

## 🧪 Plan de Verificación Manual

1. Ingresar a WooCommerce > Pedidos (`wc-orders`).
2. Comprobar que los badges de Formas de Envío y Sucursales de Origen se rendericen correctamente.
3. Abrir el modal de edición rápida haciendo clic en la celda Información.
4. Modificar los campos (Guía, Forma de Envío, Costo, Pasarela y Notas) y presionar Guardar.
5. Verificar que la página recargue con los datos actualizados correctamente.
