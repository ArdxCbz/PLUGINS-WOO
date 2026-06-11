<?php
if (!defined('ABSPATH')) {
    exit;
}
/**
 * Form de compra unificado (3.19+).
 *
 * Una sola pantalla muestra cabecera + bloque "Agregar producto" + tabla de
 * productos + bloque "Confirmar recepción". El draft se crea **al primer
 * Agregar producto** vía AJAX (creación lazy), evitando el doble-paso previo
 * a 3.19 y los drafts huérfanos sin líneas.
 *
 *  - Si $purchase===null → modo nuevo. El bloque de productos arranca
 *    deshabilitado hasta que la cabecera tenga proveedor + sucursal + fecha;
 *    el primer Agregar dispara `iem_create_draft_purchase` (AJAX) y a partir
 *    de ahí la URL pasa a edit sin reload.
 *  - Si $purchase es draft → editar header + productos con autosave AJAX.
 *
 * Productos (3.18+ — modelo padre-only):
 *  - Bloque "Agregar producto" con <select class="wc-product-search"> de WC
 *    (Select2 + AJAX a `woocommerce_json_search_products`).
 *  - Tabla con qty y costo editables (autosave por debounce).
 *  - Botón eliminar por fila.
 *
 * Recepción:
 *  - Por cada línea variable se elige la variación destino con dropdown.
 *  - Indicador de progreso "Listo para recibir: X de Y productos con variación".
 *  - El form envía `received_variation_id[<line_id>]` a `iem_receive_purchase`.
 *
 * @var array|null $purchase      Fila iem_purchases en draft o null.
 * @var array      $lines         Filas de purchase_lines (vacío en nueva).
 * @var array      $suppliers     Activos (id => name) + el actual aunque sea inactivo.
 * @var array      $sucursales    slug => name.
 * @var string     $nonce
 * @var string     $ajax_nonce
 */
$base_url = admin_url('admin-post.php');
$is_new   = empty($purchase);

$save_url = add_query_arg([
    'action'               => 'iem_save_purchase',
    IEM_Admin::NONCE_FIELD => $nonce,
], $base_url);

$receive_url = $is_new ? '#' : add_query_arg([
    'action'               => 'iem_receive_purchase',
    'id'                   => (int) $purchase['id'],
    IEM_Admin::NONCE_FIELD => $nonce,
], $base_url);

$list_url = IEM_Admin::tab_url('compras');

$f_code     = !$is_new ? (string) $purchase['code']           : '';
$f_supplier = !$is_new ? (int)    $purchase['supplier_id']    : 0;
$f_sucursal = !$is_new ? (string) $purchase['sucursal_slug']  : '';
$f_date     = !$is_new ? (string) $purchase['purchase_date']  : wp_date('Y-m-d');
$f_invoice  = !$is_new ? (string) $purchase['invoice_number'] : '';
$f_notes    = !$is_new ? (string) $purchase['notes']          : '';
$f_total    = !$is_new ? (float)  $purchase['total']          : 0;
$f_id       = !$is_new ? (int)    $purchase['id']             : 0;
// 2.3+: datos logísticos de la importación (cabecera).
$f_packages = !$is_new ? (int)    ($purchase['package_count']   ?? 0) : 0;
$f_weight   = !$is_new ? (float)  ($purchase['gross_weight_kg'] ?? 0) : 0;
$f_cbm      = !$is_new ? (float)  ($purchase['cbm_m3']          ?? 0) : 0;
$f_via      = !$is_new ? (string) ($purchase['shipping_via']    ?? '') : '';
// 2.7+: tracking (alfanumérico) y ETA (fecha de arribo).
$f_tracking = !$is_new ? (string) ($purchase['tracking_number'] ?? '') : '';
$f_eta      = !$is_new ? (string) ($purchase['eta_date']        ?? '') : '';
if ($f_eta === '0000-00-00') { $f_eta = ''; }

// URL canónica de edit del draft (para history.replaceState al crear lazy).
$edit_url_template = IEM_Admin::tab_url('compras', ['action' => 'edit', 'id' => '__ID__']);

// Pre-cargamos las variaciones de cada padre presente en las líneas (una sola
// vez por padre, no por línea). Se usa para alimentar los dropdowns de
// recepción y para detectar líneas variables vs simples.
$variations_by_parent = [];
if (!$is_new) {
    $seen = [];
    foreach ($lines as $L) {
        $pid = (int) $L['item_id'];
        if ($pid <= 0 || isset($seen[$pid])) continue;
        $seen[$pid] = true;
        $variations_by_parent[$pid] = IEM_Purchases::get_variations_for_parent($pid);
    }
}

$line_count = count($lines);

// Opciones del select de proveedor (reutilizadas por PHP y JS appendLine).
$supplier_options = '';
foreach ($suppliers as $sid => $sname) {
    $supplier_options .= '<option value="' . (int) $sid . '">' . esc_html($sname) . '</option>';
}
?>
<div class="wrap iem-purchase-form">
    <h1 class="wp-heading-inline" id="iem-form-title">
        <?php echo $is_new ? 'Nueva compra' : 'Compra ' . esc_html($f_code); ?>
    </h1>
    <a href="<?php echo esc_url($list_url); ?>" class="page-title-action">← Volver al listado</a>
    <hr class="wp-header-end">

    <?php // ── Banner contextual (estado en vivo) ─────────────────── ?>
    <div id="iem-status-banner" style="margin-top:12px;padding:8px 14px;border-radius:4px;
                background:#f0f6fc;border-left:4px solid #2271b1;display:flex;gap:18px;
                flex-wrap:wrap;align-items:center;font-size:13px;">
        <span><strong>Estado:</strong> <span id="iem-status-text"><?php
            echo $is_new ? 'Sin guardar (agrega un producto para crear el borrador)' : 'Borrador';
        ?></span></span>
        <span><strong>Productos:</strong> <span id="iem-count-text"><?php echo (int) $line_count; ?></span></span>
        <span><strong>Total:</strong> Bs <span id="iem-banner-total"><?php
            echo esc_html(number_format($f_total, 2, '.', ',')); ?></span></span>
    </div>

    <?php // ── Datos de la compra (cabecera) ────────────────────────── ?>
    <h2 style="margin-top:18px;">Datos de la compra</h2>
    <form method="post" action="<?php echo esc_url($save_url); ?>" id="iem-header-form">
        <input type="hidden" name="id" id="iem-purchase-id" value="<?php echo (int) $f_id; ?>">

        <div class="iem-card iem-h-grid" style="padding:16px;
                    display:grid;grid-template-columns:repeat(3, minmax(0, 1fr));gap:14px 24px;align-items:start;">

            <?php // ── Columna 1: identidad de la compra ──────────────────── ?>
            <div class="iem-h-col">
                <label class="iem-h-field"><strong>Código</strong>
                    <input type="text" name="code" id="iem-h-code" value="<?php echo esc_attr($f_code); ?>"
                           placeholder="PO-XXXX (vacío = autogenera COMP-AAAA-NNNN)" maxlength="50"
                           title="Texto libre. Si lo dejas vacío se autogenera (COMP-AAAA-NNNN)."
                           style="width:100%;font-family:monospace;">
                </label>
                <label class="iem-h-field"><strong>Fecha de la compra *</strong>
                    <input type="date" name="purchase_date" id="iem-h-date"
                           value="<?php echo esc_attr($f_date); ?>" required style="width:100%;">
                </label>
                <label class="iem-h-field"><strong>Proveedor por defecto</strong>
                    <select name="supplier_id" id="iem-h-supplier" style="width:100%;"
                            title="Pre-rellena el proveedor de cada producto que agregues. El proveedor real se define por producto (cada línea puede tener uno distinto).">
                        <option value="">— Sin proveedor por defecto —</option>
                        <?php foreach ($suppliers as $sid => $sname): ?>
                            <option value="<?php echo (int) $sid; ?>"
                                <?php selected($f_supplier, (int) $sid); ?>>
                                <?php echo esc_html($sname); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="iem-h-field">Nº de factura del proveedor
                    <input type="text" name="invoice_number" value="<?php echo esc_attr($f_invoice); ?>"
                           maxlength="50" style="width:100%;">
                </label>
                <label class="iem-h-field"><strong>Sucursal sugerida</strong>
                    <select name="sucursal_slug" id="iem-h-sucursal" style="width:100%;"
                            title="Autoselecciona las variaciones de esta sucursal al confirmar la recepción.">
                        <option value="">— Sin sugerencia —</option>
                        <?php foreach ($sucursales as $slug => $name): ?>
                            <option value="<?php echo esc_attr($slug); ?>"
                                <?php selected($f_sucursal, $slug); ?>>
                                <?php echo esc_html($name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>

            <?php // ── Columna 2: logística / tracking ─────────────────────── ?>
            <div class="iem-h-col">
                <label class="iem-h-field">Vía
                    <select name="shipping_via" id="iem-h-via" style="width:100%;">
                        <option value=""         <?php selected($f_via, ''); ?>>— Sin definir —</option>
                        <option value="aerea"    <?php selected($f_via, 'aerea'); ?>>Aérea</option>
                        <option value="maritima" <?php selected($f_via, 'maritima'); ?>>Marítima</option>
                    </select>
                </label>
                <label class="iem-h-field">Nº de tracking
                    <input type="text" name="tracking_number" id="iem-h-tracking"
                           value="<?php echo esc_attr($f_tracking); ?>"
                           maxlength="100" style="width:100%;">
                </label>
                <label class="iem-h-field">ETA (fecha de arribo)
                    <input type="date" name="eta_date" id="iem-h-eta"
                           value="<?php echo esc_attr($f_eta); ?>" style="width:100%;">
                </label>
                <label class="iem-h-field">Cantidad de bultos
                    <input type="number" name="package_count" id="iem-h-packages" min="0" step="1"
                           value="<?php echo esc_attr($f_packages > 0 ? (string) $f_packages : ''); ?>"
                           style="width:100%;">
                </label>
                <label class="iem-h-field">Peso bruto (Kg)
                    <input type="number" name="gross_weight_kg" id="iem-h-weight" min="0" step="0.01"
                           value="<?php echo esc_attr($f_weight > 0 ? number_format($f_weight, 2, '.', '') : ''); ?>"
                           style="width:100%;">
                </label>
            </div>

            <?php // ── Columna 3: volumen + notas + guardar ────────────────── ?>
            <div class="iem-h-col">
                <label class="iem-h-field">CBM (m³)
                    <input type="number" name="cbm_m3" id="iem-h-cbm" min="0" step="0.0001"
                           value="<?php echo esc_attr($f_cbm > 0 ? number_format($f_cbm, 4, '.', '') : ''); ?>"
                           style="width:100%;">
                </label>
                <label class="iem-h-field iem-h-notes">Notas
                    <textarea name="notes" rows="7" style="width:100%;resize:vertical;"><?php
                        echo esc_textarea($f_notes); ?></textarea>
                </label>
                <button type="submit" id="iem-h-save"
                        class="button button-primary"
                        style="width:100%;<?php echo $is_new ? 'display:none;' : ''; ?>">
                    Guardar cambios
                </button>
            </div>
        </div>
    </form>

    <?php // ── Agregar producto ──────────────────────────────────── ?>
    <h2 style="margin-top:24px;">Productos</h2>

    <div class="iem-card" style="padding:14px 16px;">
        <h3 style="margin-top:0;">Agregar producto</h3>
        <p class="iem-help" style="margin:0 0 10px;">
            Busca por nombre o SKU. Se listan productos padre (simples o variables);
            las variaciones se asignan al confirmar la recepción. Carga el
            <strong>subtotal en USD</strong> (el costo unitario USD se calcula solo);
            el <strong>costo unitario en Bs</strong> baja vacío para ajustarlo en la tabla.
        </p>
        <div style="display:grid;grid-template-columns:2fr 1.2fr 0.6fr 0.9fr 0.6fr;gap:10px;align-items:end;">
            <p style="margin:0;">
                <label><strong>Producto</strong>
                    <select id="iem-pl-product" class="wc-product-search"
                            style="width:100%;"
                            data-placeholder="Buscar producto (padre)…"
                            data-action="woocommerce_json_search_products"
                            data-allow_clear="true"
                            data-minimum_input_length="2"
                            data-exclude_type="variation,grouped,external">
                    </select>
                </label>
            </p>
            <p style="margin:0;">
                <label><strong>Proveedor *</strong>
                    <select id="iem-pl-supplier" style="width:100%;">
                        <option value="">— Selecciona —</option>
                        <?php foreach ($suppliers as $sid => $sname): ?>
                            <option value="<?php echo (int) $sid; ?>"
                                <?php selected($f_supplier, (int) $sid); ?>>
                                <?php echo esc_html($sname); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </p>
            <p style="margin:0;">
                <label>Cantidad
                    <input type="number" id="iem-pl-qty" min="1" step="1" value="1" style="width:100%;">
                </label>
            </p>
            <p style="margin:0;">
                <label>Subtotal (USD) *
                    <input type="number" id="iem-pl-origin" min="0" step="0.01" value="0" style="width:100%;"
                           placeholder="0.00"
                           title="Subtotal en USD de la línea. El costo unitario USD = subtotal ÷ cantidad.">
                </label>
            </p>
            <p style="margin:0;">
                <button type="button" class="button button-primary" id="iem-pl-add" disabled>
                    Agregar
                </button>
            </p>
        </div>
        <p style="margin:8px 0 0;">
            <span id="iem-pl-feedback" style="font-size:12px;color:#666;">
                <?php echo $is_new
                    ? 'Completa proveedor y fecha para empezar.'
                    : 'Selecciona un producto para habilitar el botón.'; ?>
            </span>
        </p>
    </div>

    <?php // ── Tabla de productos ──────────────────────────────────── ?>
    <table class="widefat fixed striped" id="iem-purchase-lines" style="margin-top:14px;">
        <thead>
            <tr>
                <th style="width:130px;">SKU</th>
                <th>Producto</th>
                <th style="width:170px;">Proveedor</th>
                <th style="width:80px;text-align:right;">Cantidad</th>
                <th style="width:120px;text-align:right;">Costo unit. (USD)</th>
                <th style="width:130px;text-align:right;">Subtotal (USD)</th>
                <th style="width:120px;text-align:right;">Costo unit. (Bs)</th>
                <th style="width:120px;text-align:right;">Subtotal (Bs)</th>
                <th style="width:110px;text-align:center;">Acciones</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($lines)): ?>
            <tr id="iem-empty-row"><td colspan="9"><em>Sin productos. Agrega el primero arriba.</em></td></tr>
        <?php else:
            $f_origin_total = 0.0;
            foreach ($lines as $L):
                $l_origin_sub = (int) $L['qty'] * (float) ($L['origin_cost'] ?? 0);
                $f_origin_total += $l_origin_sub;
        ?>
            <tr data-line-id="<?php echo (int) $L['id']; ?>">
                <td style="font-family:monospace;"><?php echo esc_html($L['sku']); ?></td>
                <td><?php echo esc_html($L['name']); ?></td>
                <td>
                    <select class="iem-pl-edit-supplier" style="width:100%;">
                        <option value="">— Selecciona —</option>
                        <?php foreach ($suppliers as $sid => $sname): ?>
                            <option value="<?php echo (int) $sid; ?>"
                                <?php selected((int) ($L['supplier_id'] ?? 0), (int) $sid); ?>>
                                <?php echo esc_html($sname); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td style="text-align:right;">
                    <input type="number" class="iem-pl-edit-qty" min="1" step="1"
                           value="<?php echo (int) $L['qty']; ?>" style="width:80px;text-align:right;">
                </td>
                <td style="text-align:right;">
                    <input type="number" class="iem-pl-edit-origin" min="0" step="0.0001"
                           value="<?php echo esc_attr(number_format((float) ($L['origin_cost'] ?? 0), 4, '.', '')); ?>"
                           style="width:120px;text-align:right;"
                           title="Costo unitario USD. Se recalcula = subtotal ÷ cantidad.">
                </td>
                <td style="text-align:right;">
                    <input type="number" class="iem-pl-edit-origin-sub" min="0" step="0.01"
                           value="<?php echo esc_attr(number_format($l_origin_sub, 2, '.', '')); ?>"
                           style="width:120px;text-align:right;font-family:monospace;"
                           title="Editable: el costo unitario USD se recalcula = subtotal ÷ cantidad.">
                </td>
                <td style="text-align:right;">
                    <input type="number" class="iem-pl-edit-cost" min="0" step="0.0001"
                           value="<?php echo (float) $L['unit_cost'] > 0 ? esc_attr(number_format((float) $L['unit_cost'], 4, '.', '')) : ''; ?>"
                           style="width:120px;text-align:right;"
                           placeholder="0" title="Costo unitario en Bs. Ajusta este valor; el Subtotal (Bs) se recalcula.">
                </td>
                <td style="text-align:right;font-family:monospace;" class="iem-pl-subtotal">
                    <?php echo esc_html(number_format((float) $L['line_total'], 2, '.', ',')); ?>
                </td>
                <td style="text-align:center;">
                    <span class="iem-savestate" data-line-id="<?php echo (int) $L['id']; ?>"></span>
                    <button type="button" class="button button-small iem-pl-delete">Eliminar</button>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
        <tfoot>
            <tr>
                <th colspan="5" style="text-align:right;">Totales:</th>
                <th style="text-align:right;font-family:monospace;" id="iem-origin-total"
                    title="Total de costo origen (USD)">
                    <?php echo esc_html(number_format($f_origin_total ?? 0, 2, '.', ',')); ?>
                </th>
                <th></th>
                <th style="text-align:right;font-family:monospace;" id="iem-purchase-total"
                    title="Total de la compra (Bs)">
                    <?php echo esc_html(number_format($f_total, 2, '.', ',')); ?>
                </th>
                <th></th>
            </tr>
            <tr>
                <th colspan="5"></th>
                <th style="text-align:right;font-weight:400;color:#666;font-size:11px;">USD origen</th>
                <th></th>
                <th style="text-align:right;font-weight:400;color:#666;font-size:11px;">Bs compra</th>
                <th></th>
            </tr>
        </tfoot>
    </table>

    <?php // ── Gastos de importación (partial reutilizable, 2.6+) ──────────── ?>
    <?php if (!$is_new) {
        $ic_purchase_id = (int) $f_id;
        include IEM_PLUGIN_DIR . 'templates/partials/purchase-expenses.php';
    } ?>

    <?php // ── Confirmar recepción (solo si hay líneas y la compra ya existe) ── ?>
    <?php if (!$is_new && !empty($lines)): ?>
    <div style="margin-top:24px;padding:14px 16px;background:#f6f7f7;border-left:4px solid #2271b1;">
        <h3 style="margin-top:0;">Confirmar recepción</h3>
        <p>
            Elige a qué variación entra el stock de cada producto. Si el producto tiene
            <strong>variación de color</strong>, elige la sucursal y reparte la cantidad
            entre los colores (la suma debe igualar la cantidad comprada).
            Al confirmar, el sistema suma el stock y actualiza el costo.
            <strong>Esta acción es irreversible.</strong>
        </p>

        <form method="post" action="<?php echo esc_url($receive_url); ?>"
              id="iem-receive-form"
              onsubmit="return iemValidateReceive(this);">

            <table class="widefat striped" style="margin-bottom:14px;">
                <thead>
                    <tr>
                        <th>Producto (padre)</th>
                        <th style="width:90px;text-align:right;">Cant.</th>
                        <th>Destino del stock</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($lines as $L):
                    $lid       = (int) $L['id'];
                    $pid       = (int) $L['item_id'];
                    $lqty      = (int) $L['qty'];
                    $vars      = $variations_by_parent[$pid] ?? [];
                    $is_simple = empty($vars);
                    $has_color = false;
                    foreach ($vars as $v) { if (($v['color'] ?? '') !== '') { $has_color = true; break; } }

                    // Sugerencia de variación/sucursal según el header.
                    $suggested = 0;
                    if (!$is_simple && !$has_color && $f_sucursal !== '') {
                        foreach ($vars as $v) {
                            if ($v['sucursal_slug'] === $f_sucursal) { $suggested = (int) $v['id']; break; }
                        }
                    }
                    // Agrupar variaciones por sucursal (para el modo color).
                    $by_suc = [];
                    foreach ($vars as $v) { $by_suc[(string) $v['sucursal_slug']][] = $v; }
                ?>
                    <tr class="iem-recv-row" data-line="<?php echo $lid; ?>"
                        data-mode="<?php echo $is_simple ? 'simple' : ($has_color ? 'color' : 'single'); ?>">
                        <td>
                            <strong><?php echo esc_html($L['name']); ?></strong>
                            <?php if ($L['sku'] !== ''): ?>
                                <br><small style="font-family:monospace;color:#666;">[<?php
                                    echo esc_html($L['sku']); ?>]</small>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:right;"><?php echo $lqty; ?></td>
                        <td>
                            <?php if ($is_simple): ?>
                                <em style="color:#666;">— Producto simple (sin variación) —</em>
                                <input type="hidden" data-iem-recv-marker="1" value="0">
                            <?php elseif (!$has_color): ?>
                                <select name="recv_variation[<?php echo $lid; ?>]"
                                        class="iem-recv-select" data-line="<?php echo $lid; ?>"
                                        required style="width:100%;">
                                    <option value="">— Elegir variación destino —</option>
                                    <?php foreach ($vars as $v): ?>
                                        <option value="<?php echo (int) $v['id']; ?>"
                                            <?php selected($suggested, (int) $v['id']); ?>>
                                            <?php echo esc_html($v['label']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <select class="iem-recv-sucursal" data-line="<?php echo $lid; ?>"
                                        style="width:100%;margin-bottom:8px;">
                                    <option value="">— Elegir sucursal —</option>
                                    <?php foreach ($by_suc as $slug => $group):
                                        $sname = $slug === '' ? 'Sin sucursal' : ($sucursales[$slug] ?? $slug);
                                    ?>
                                        <option value="<?php echo esc_attr($slug); ?>"
                                            <?php selected($f_sucursal, $slug); ?>>
                                            <?php echo esc_html($sname); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="iem-recv-colors" data-line="<?php echo $lid; ?>"
                                     data-qty="<?php echo $lqty; ?>">
                                    <?php foreach ($by_suc as $slug => $group): ?>
                                        <div class="iem-recv-suc" data-line="<?php echo $lid; ?>"
                                             data-suc="<?php echo esc_attr($slug); ?>" style="display:none;">
                                            <?php foreach ($group as $v):
                                                $clabel = ($v['color'] ?? '') !== '' ? $v['color'] : $v['label'];
                                            ?>
                                                <label style="display:inline-flex;align-items:center;gap:4px;margin:0 14px 6px 0;">
                                                    <span><?php echo esc_html($clabel); ?></span>
                                                    <input type="number" min="0" step="1" value="0"
                                                           class="iem-recv-qty" data-line="<?php echo $lid; ?>"
                                                           name="receipts[<?php echo $lid; ?>][<?php echo (int) $v['id']; ?>]"
                                                           disabled style="width:70px;text-align:right;">
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endforeach; ?>
                                    <div style="font-size:12px;color:#666;margin-top:2px;">
                                        Asignado: <span class="iem-recv-asigned" data-line="<?php echo $lid; ?>">0</span>
                                        / <strong><?php echo $lqty; ?></strong>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <div id="iem-recv-progress" style="margin:0 0 12px;padding:8px 12px;border-radius:4px;
                        font-size:13px;display:flex;gap:8px;align-items:center;">
                <strong>Listo para recibir:</strong>
                <span id="iem-recv-count">0</span> de
                <span id="iem-recv-total">0</span> productos con destino completo.
            </div>

            <p style="margin:0 0 12px;padding:8px 12px;background:#fff8e5;border-left:4px solid #d68f00;">
                <label style="display:inline-flex;align-items:flex-start;gap:8px;cursor:pointer;">
                    <input type="checkbox" name="skip_inventory" id="iem-recv-skip" value="1" style="margin-top:3px;">
                    <span><strong>Recibir sin afectar el inventario.</strong> El stock ya se ajustó
                    a mano (compra hecha antes de subir el plugin). <strong>No</strong> suma stock, no
                    escribe kardex ni toca costos; solo marca la compra como recibida para poder
                    registrarle gastos. No hace falta elegir destino.</span>
                </label>
            </p>

            <div style="display:flex;gap:14px;align-items:center;flex-wrap:wrap;">
                <label>Fecha de recepción
                    <input type="date" name="received_date"
                           value="<?php echo esc_attr(wp_date('Y-m-d')); ?>" required>
                </label>
                <button type="submit" id="iem-recv-submit" class="button button-primary">
                    Confirmar recepción
                </button>
                <span class="iem-help">
                    Debe ser ≥ <?php echo esc_html($f_date); ?> y ≤ hoy.
                </span>
            </div>
        </form>
    </div>
    <script>
    function iemRecvRowReady(row){
        var mode = row.getAttribute('data-mode');
        if (mode === 'simple') return true;
        if (mode === 'single') {
            var sel = row.querySelector('select.iem-recv-select');
            return !!(sel && sel.value);
        }
        // color: la suma de las cantidades habilitadas debe igualar el qty.
        var box = row.querySelector('.iem-recv-colors');
        if (!box) return false;
        var qty = parseInt(box.getAttribute('data-qty'), 10) || 0;
        var sum = 0, any = false;
        box.querySelectorAll('input.iem-recv-qty').forEach(function(inp){
            if (!inp.disabled) { any = true; sum += (parseInt(inp.value, 10) || 0); }
        });
        return any && sum === qty;
    }
    function iemValidateReceive(form){
        var skip = form.querySelector('#iem-recv-skip');
        if (skip && skip.checked) {
            // Sin afectar inventario: no se valida destino (no se toca stock).
            return confirm('¿Confirmar la recepción SIN afectar el inventario? La compra quedará '
                + 'recibida pero NO se sumará stock ni se tocarán costos. No se puede deshacer.');
        }
        var rows = form.querySelectorAll('tr.iem-recv-row');
        for (var i = 0; i < rows.length; i++) {
            if (!iemRecvRowReady(rows[i])) {
                alert('Falta completar el destino (o la suma por color no coincide con la cantidad) de al menos un producto.');
                return false;
            }
        }
        if (!confirm('¿Confirmar la recepción? Suma stock y actualiza costos. No se puede deshacer.')) {
            return false;
        }
        return true;
    }
    </script>
    <?php endif; ?>
</div>

<script>
(function(){
    window.IEM_AJAX = {
        url:   <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>,
        nonce: <?php echo wp_json_encode($ajax_nonce); ?>,
        field: <?php echo wp_json_encode(IEM_Ajax::NONCE_FIELD); ?>
    };

    var IS_NEW           = <?php echo $is_new ? 'true' : 'false'; ?>;
    var EDIT_URL_TPL     = <?php echo wp_json_encode($edit_url_template); ?>;
    var purchaseId       = <?php echo (int) $f_id; ?>;
    // Opciones del <select> de proveedor para las filas creadas vía JS.
    var SUPPLIER_OPTIONS = <?php echo wp_json_encode('<option value="">— Selecciona —</option>' . $supplier_options); ?>;

    function post(action, data, cb) {
        var form = new FormData();
        form.append('action', action);
        form.append(window.IEM_AJAX.field, window.IEM_AJAX.nonce);
        Object.keys(data).forEach(function(k){ form.append(k, data[k]); });
        fetch(window.IEM_AJAX.url, { method:'POST', credentials:'same-origin', body: form })
            .then(function(r){ return r.json().then(function(j){ return { ok:r.ok, j:j }; }); })
            .then(function(res){ cb(null, res); })
            .catch(function(e){ cb(e); });
    }

    function fmt(n){
        return Number(n).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
    }

    // ── Helpers de UI: banner + feedback del bloque agregar ─────────────
    var $idInput   = document.getElementById('iem-purchase-id');
    var $hCode     = document.getElementById('iem-h-code');
    var $hSupplier = document.getElementById('iem-h-supplier');
    var $hSucursal = document.getElementById('iem-h-sucursal');
    var $hDate     = document.getElementById('iem-h-date');
    var $hSave     = document.getElementById('iem-h-save');
    var $title     = document.getElementById('iem-form-title');
    var $stTxt     = document.getElementById('iem-status-text');
    var $stCount   = document.getElementById('iem-count-text');
    var $stTotal   = document.getElementById('iem-banner-total');

    var $sel       = null; // jQuery del Select2 — se inicializa abajo
    var $selDom    = document.getElementById('iem-pl-product');
    var $plSupp    = document.getElementById('iem-pl-supplier');
    var $qtyDom    = document.getElementById('iem-pl-qty');
    var $originDom = document.getElementById('iem-pl-origin'); // ahora = Subtotal (USD)
    var $addBtn    = document.getElementById('iem-pl-add');
    var $fb        = document.getElementById('iem-pl-feedback');

    function headerComplete(){
        // 2.1+: el proveedor de cabecera es opcional; basta la fecha para crear
        // el borrador (la sucursal es solo sugerencia desde 3.20).
        return !!$hDate.value;
    }

    function refreshAddState(){
        // Reglas del botón Agregar:
        //   - Si el header no está completo (en modo nuevo) → disabled.
        //   - Si no hay producto o no hay proveedor de línea → disabled.
        //   - Si todo OK → enabled.
        var pid = parseInt($selDom.value || '0', 10);
        if (purchaseId === 0 && !headerComplete()) {
            $addBtn.disabled = true;
            $fb.style.color = '#666';
            $fb.textContent = 'Completa la fecha para empezar.';
            return;
        }
        if (!pid) {
            $addBtn.disabled = true;
            $fb.style.color = '#666';
            $fb.textContent = 'Selecciona un producto para habilitar el botón.';
            return;
        }
        if (!$plSupp.value) {
            $addBtn.disabled = true;
            $fb.style.color = '#666';
            $fb.textContent = 'Elige el proveedor del producto.';
            return;
        }
        var subUsd = parseFloat($originDom.value) || 0;
        if (subUsd <= 0) {
            $addBtn.disabled = true;
            $fb.style.color = '#666';
            $fb.textContent = 'Ingresa el subtotal en USD de la línea.';
            return;
        }
        $addBtn.disabled = false;
        $fb.style.color = '#666';
        $fb.textContent = 'Listo. El costo Bs lo ajustas en la tabla. Pulsa Agregar.';
    }

    function refreshBanner(){
        var rows = document.querySelectorAll('#iem-purchase-lines tbody tr[data-line-id]');
        $stCount.textContent = rows.length;
        if (purchaseId === 0) {
            $stTxt.textContent = 'Sin guardar (agrega un producto para crear el borrador)';
        } else {
            $stTxt.textContent = 'Borrador';
        }
    }

    function updateTotal(v){
        var s = fmt(v);
        document.getElementById('iem-purchase-total').textContent = s;
        $stTotal.textContent = s;
    }

    // Sincroniza la pareja "costo origen unit (USD)" ↔ "subtotal origen (USD)" de una
    // fila, según qué se editó:
    //   - source 'sub'        → costo unit = subtotal ÷ cantidad (más exacto, 4 dec).
    //   - source 'qty'/'unit' → subtotal = cantidad × costo unit.
    // Luego refresca el Total origen (USD) del pie. Es un dato derivado en USD; no
    // toca el total en Bs ni la capa AJAX (lo que se persiste es el costo unit).
    function syncOriginRow(tr, source){
        if (!tr) return;
        var qEl = tr.querySelector('.iem-pl-edit-qty');
        var uEl = tr.querySelector('.iem-pl-edit-origin');
        var sEl = tr.querySelector('.iem-pl-edit-origin-sub');
        if (!qEl || !uEl || !sEl) return;
        var q = parseInt(qEl.value, 10) || 0;
        if (source === 'sub') {
            var s = parseFloat(sEl.value) || 0;
            uEl.value = (q > 0 ? (s / q) : 0).toFixed(4);
        } else {
            var u = parseFloat(uEl.value) || 0;
            sEl.value = (q * u).toFixed(2);
        }
        recalcOriginTotal();
    }
    // Total origen (USD) del pie = Σ de los subtotales (inputs) de cada fila.
    function recalcOriginTotal(){
        var rows = document.querySelectorAll('#iem-purchase-lines tbody tr[data-line-id]');
        var total = 0;
        rows.forEach(function(tr){
            total += parseFloat((tr.querySelector('.iem-pl-edit-origin-sub') || {}).value) || 0;
        });
        var foot = document.getElementById('iem-origin-total');
        if (foot) foot.textContent = fmt(total);
    }

    function escapeHtml(s){
        return String(s).replace(/[&<>"']/g, function(c){
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'}[c];
        });
    }

    function onHeaderChange(){ refreshAddState(); }
    $hSupplier.addEventListener('change', function(){
        // El proveedor por defecto de la cabecera pre-rellena el del bloque
        // Agregar si éste está vacío (no pisa una elección manual).
        if ($hSupplier.value && !$plSupp.value) { $plSupp.value = $hSupplier.value; }
        refreshAddState();
    });
    $hSucursal.addEventListener('change', onHeaderChange);
    $hDate.addEventListener('change',     onHeaderChange);
    $plSupp.addEventListener('change',    refreshAddState);
    $qtyDom.addEventListener('input',     refreshAddState);
    $originDom.addEventListener('input',  refreshAddState);
    // Sembrar el proveedor del bloque Agregar con el default de la cabecera.
    if ($hSupplier.value && !$plSupp.value) { $plSupp.value = $hSupplier.value; }

    // Prevenir submit del header en modo nuevo (no hay nada que guardar todavía).
    document.getElementById('iem-header-form').addEventListener('submit', function(e){
        if (purchaseId === 0) {
            e.preventDefault();
            alert('Primero agrega un producto para crear el borrador.');
        }
    });

    // ── Inicializar Select2 de WC ───────────────────────────────────────
    function initSelect2(){
        if (typeof jQuery === 'undefined' || !jQuery.fn.selectWoo) {
            return setTimeout(initSelect2, 50);
        }
        $sel = jQuery('#iem-pl-product');
        if (!$sel.data('select2')) {
            $sel.selectWoo({
                placeholder: $sel.data('placeholder'),
                minimumInputLength: 2,
                allowClear: true,
                ajax: {
                    url: ajaxurl || window.IEM_AJAX.url,
                    dataType: 'json',
                    delay: 250,
                    data: function(params){
                        return {
                            term:         params.term,
                            action:       'woocommerce_json_search_products',
                            security:     wc_enhanced_select_params && wc_enhanced_select_params.search_products_nonce,
                            exclude_type: 'variation,grouped,external'
                        };
                    },
                    processResults: function(data){
                        var terms = [];
                        if (data) jQuery.each(data, function(id, text){ terms.push({ id: id, text: text }); });
                        return { results: terms };
                    },
                    cache: true
                }
            });
        }
        $sel.on('change', refreshAddState);
    }
    initSelect2();
    refreshAddState();

    // ── Agregar producto (lazy: crea el draft si purchaseId===0) ────────
    $addBtn.addEventListener('click', function(){
        var pid = parseInt($selDom.value || '0', 10) || 0;
        if (!pid) return;
        var qty      = parseInt($qtyDom.value, 10) || 0;
        var subUsd   = parseFloat($originDom.value) || 0;
        // Costo unitario USD = subtotal ÷ cantidad (más exacto). El costo Bs baja
        // en 0 para ajustarse luego en la tabla.
        var origin   = (qty > 0 ? (subUsd / qty) : 0).toFixed(4);
        var cost     = '0';
        var supplier = $plSupp.value;
        if (!supplier || qty <= 0 || subUsd <= 0) { refreshAddState(); return; }
        $addBtn.disabled = true;
        $fb.style.color = '#666';

        if (purchaseId === 0) {
            // Paso 1 de 2: crear el draft con los datos de cabecera actuales.
            $fb.textContent = 'Creando borrador…';
            post('iem_create_draft_purchase', {
                code:            $hCode.value,
                supplier_id:     $hSupplier.value,
                sucursal_slug:   $hSucursal.value,
                purchase_date:   $hDate.value,
                invoice_number:  document.querySelector('input[name="invoice_number"]').value || '',
                package_count:   document.querySelector('input[name="package_count"]').value || '',
                gross_weight_kg: document.querySelector('input[name="gross_weight_kg"]').value || '',
                cbm_m3:          document.querySelector('input[name="cbm_m3"]').value || '',
                shipping_via:    document.querySelector('select[name="shipping_via"]').value || '',
                tracking_number: document.querySelector('input[name="tracking_number"]').value || '',
                eta_date:        document.querySelector('input[name="eta_date"]').value || '',
                notes:           document.querySelector('textarea[name="notes"]').value || ''
            }, function(err, res){
                if (err || !res.ok || !res.j.success) {
                    var m = (res && res.j && res.j.data && res.j.data.message) || 'No se pudo crear el borrador.';
                    $fb.style.color = '#a00';
                    $fb.textContent = '✗ ' + m;
                    refreshAddState();
                    return;
                }
                purchaseId = parseInt(res.j.data.id, 10) || 0;
                $idInput.value = purchaseId;
                $title.textContent = 'Compra ' + (res.j.data.code || '');
                if ($hSave) $hSave.style.display = '';
                // Actualizar la URL sin reload (queda en modo edit).
                try {
                    var newUrl = EDIT_URL_TPL.replace('__ID__', purchaseId);
                    window.history.replaceState(null, '', newUrl);
                } catch(e) {}
                refreshBanner();
                // Paso 2 de 2: agregar la línea.
                addLine(pid, qty, cost, origin, supplier);
            });
        } else {
            addLine(pid, qty, cost, origin, supplier);
        }
    });

    function addLine(productId, qty, cost, origin, supplier){
        $fb.textContent = 'Agregando producto…';
        post('iem_add_purchase_line', {
            purchase_id: purchaseId,
            product_id:  productId,
            qty:         qty,
            unit_cost:   cost,
            origin_cost: origin,
            supplier_id: supplier
        }, function(err, res){
            $addBtn.disabled = false;
            if (err || !res.ok || !res.j.success) {
                var m = (res && res.j && res.j.data && res.j.data.message) || 'Error agregando producto.';
                $fb.style.color = '#a00';
                $fb.textContent = '✗ ' + m;
                return;
            }
            appendLine(res.j.data.line);
            updateTotal(res.j.data.purchase_total);
            recalcOriginTotal();
            refreshBanner();
            // Reset captura.
            if ($sel) $sel.val(null).trigger('change');
            $qtyDom.value    = 1;
            $originDom.value = 0;
            $fb.style.color = '#107010';
            $fb.textContent = '✓ Producto agregado. Para confirmar la recepción, recarga.';
        });
    }

    function appendLine(line){
        var empty = document.getElementById('iem-empty-row');
        if (empty) empty.parentNode.removeChild(empty);
        var tbody = document.querySelector('#iem-purchase-lines tbody');
        var tr = document.createElement('tr');
        tr.dataset.lineId = line.id;
        var suppSel = '<select class="iem-pl-edit-supplier" style="width:100%;">' + SUPPLIER_OPTIONS + '</select>';
        tr.innerHTML =
            '<td style="font-family:monospace;">' + escapeHtml(line.sku) + '</td>' +
            '<td>' + escapeHtml(line.name || '') + '</td>' +
            '<td>' + suppSel + '</td>' +
            '<td style="text-align:right;"><input type="number" class="iem-pl-edit-qty" min="1" step="1" value="' +
                parseInt(line.qty,10) + '" style="width:80px;text-align:right;"></td>' +
            '<td style="text-align:right;"><input type="number" class="iem-pl-edit-origin" min="0" step="0.0001" value="' +
                Number(line.origin_cost || 0).toFixed(4) + '" style="width:120px;text-align:right;" title="Costo unitario USD. Se recalcula = subtotal ÷ cantidad."></td>' +
            '<td style="text-align:right;"><input type="number" class="iem-pl-edit-origin-sub" min="0" step="0.01" value="' +
                ((parseInt(line.qty,10) || 0) * (Number(line.origin_cost) || 0)).toFixed(2) + '" style="width:120px;text-align:right;font-family:monospace;"></td>' +
            '<td style="text-align:right;"><input type="number" class="iem-pl-edit-cost" min="0" step="0.0001" value="' +
                (Number(line.unit_cost) > 0 ? Number(line.unit_cost).toFixed(4) : '') +
                '" placeholder="0" title="Costo unitario en Bs. Ajústalo; el Subtotal (Bs) se recalcula." style="width:120px;text-align:right;"></td>' +
            '<td style="text-align:right;font-family:monospace;" class="iem-pl-subtotal">' +
                fmt(line.line_total) + '</td>' +
            '<td style="text-align:center;">' +
                '<span class="iem-savestate" data-line-id="' + line.id + '"></span> ' +
                '<button type="button" class="button button-small iem-pl-delete">Eliminar</button>' +
            '</td>';
        tbody.appendChild(tr);
        var ss = tr.querySelector('.iem-pl-edit-supplier');
        if (ss) ss.value = String(line.supplier_id || '');
    }

    // ── Autosave de líneas (qty / costo) ─────────────────────────────────
    var debouncers = new WeakMap();
    document.addEventListener('input', function(e){
        var isQty     = e.target.classList.contains('iem-pl-edit-qty');
        var isCost    = e.target.classList.contains('iem-pl-edit-cost');
        var isOrigin  = e.target.classList.contains('iem-pl-edit-origin');
        var isOrigSub = e.target.classList.contains('iem-pl-edit-origin-sub');
        if (!isQty && !isCost && !isOrigin && !isOrigSub) return;
        var input = e.target;
        var tr = input.closest('tr');
        var lineId = tr.dataset.lineId;

        // Sincroniza unit ↔ subtotal de origen (USD) según lo editado, ANTES de leer
        // `origin` para guardar (al editar el subtotal, el unit se recalcula = sub÷cant).
        if (isOrigSub)     syncOriginRow(tr, 'sub');
        else if (isQty)    syncOriginRow(tr, 'qty');
        else if (isOrigin) syncOriginRow(tr, 'unit');
        else               recalcOriginTotal();

        var qty    = tr.querySelector('.iem-pl-edit-qty').value;
        var cost   = tr.querySelector('.iem-pl-edit-cost').value;
        var origin = tr.querySelector('.iem-pl-edit-origin').value;
        var state = tr.querySelector('.iem-savestate');

        clearTimeout(debouncers.get(input));
        if (state) { state.className = 'iem-savestate saving'; state.textContent = 'guardando…'; }
        debouncers.set(input, setTimeout(function(){
            post('iem_save_purchase_line', {
                line_id: lineId, qty: qty, unit_cost: cost, origin_cost: origin
            }, function(err, res){
                if (err || !res.ok || !res.j.success) {
                    if (state) { state.className = 'iem-savestate error'; state.textContent = '✗'; }
                    return;
                }
                tr.querySelector('.iem-pl-subtotal').textContent = fmt(res.j.data.line_total);
                updateTotal(res.j.data.purchase_total);
                if (state) { state.className = 'iem-savestate saved'; state.textContent = '✓'; }
            });
        }, 400));
    });

    // ── Autosave del proveedor de la línea (select → change) ─────────────
    document.addEventListener('change', function(e){
        if (!e.target.classList || !e.target.classList.contains('iem-pl-edit-supplier')) return;
        var tr = e.target.closest('tr');
        var lineId = tr.dataset.lineId;
        var qty    = tr.querySelector('.iem-pl-edit-qty').value;
        var cost   = tr.querySelector('.iem-pl-edit-cost').value;
        var origin = tr.querySelector('.iem-pl-edit-origin').value;
        var state  = tr.querySelector('.iem-savestate');
        if (state) { state.className = 'iem-savestate saving'; state.textContent = 'guardando…'; }
        post('iem_save_purchase_line', {
            line_id: lineId, qty: qty, unit_cost: cost, origin_cost: origin, supplier_id: e.target.value
        }, function(err, res){
            if (err || !res.ok || !res.j.success) {
                if (state) { state.className = 'iem-savestate error'; state.textContent = '✗'; }
                return;
            }
            if (state) { state.className = 'iem-savestate saved'; state.textContent = '✓'; }
        });
    });

    // ── Eliminar producto ────────────────────────────────────────────────
    document.addEventListener('click', function(e){
        if (!e.target.classList.contains('iem-pl-delete')) return;
        if (!confirm('¿Eliminar este producto?')) return;
        var tr = e.target.closest('tr');
        var lineId = tr.dataset.lineId;
        post('iem_delete_purchase_line', { line_id: lineId }, function(err, res){
            if (err || !res.ok || !res.j.success) {
                alert((res && res.j && res.j.data && res.j.data.message) || 'No se pudo eliminar.');
                return;
            }
            tr.parentNode.removeChild(tr);
            updateTotal(res.j.data.purchase_total);
            recalcOriginTotal();
            refreshBanner();
            var tbody = document.querySelector('#iem-purchase-lines tbody');
            if (!tbody.querySelector('tr')) {
                var trEmpty = document.createElement('tr');
                trEmpty.id = 'iem-empty-row';
                trEmpty.innerHTML = '<td colspan="9"><em>Sin productos. Agrega el primero arriba.</em></td>';
                tbody.appendChild(trEmpty);
            }
        });
    });

    // ── Indicador de progreso para la recepción ─────────────────────────
    var $recvCount  = document.getElementById('iem-recv-count');
    var $recvTotal  = document.getElementById('iem-recv-total');
    var $recvProg   = document.getElementById('iem-recv-progress');
    var $recvSubmit = document.getElementById('iem-recv-submit');

    // Recalcula la suma asignada de una línea de color y pinta el contador.
    function recvColorSum(line){
        var box = document.querySelector('.iem-recv-colors[data-line="' + line + '"]');
        if (!box) return;
        var qty = parseInt(box.getAttribute('data-qty'), 10) || 0;
        var sum = 0;
        box.querySelectorAll('input.iem-recv-qty').forEach(function(inp){
            if (!inp.disabled) sum += (parseInt(inp.value, 10) || 0);
        });
        var span = document.querySelector('.iem-recv-asigned[data-line="' + line + '"]');
        if (span) {
            span.textContent = sum;
            span.style.color = (sum === qty) ? '#1d6e3d' : '#b32d2e';
        }
    }

    // Muestra el grupo de la sucursal elegida y deshabilita los demás (los
    // inputs deshabilitados no se envían → solo va la sucursal elegida).
    function recvPickSucursal(line, slug){
        document.querySelectorAll('.iem-recv-suc[data-line="' + line + '"]').forEach(function(g){
            var on = (g.getAttribute('data-suc') === slug && slug !== '');
            g.style.display = on ? '' : 'none';
            g.querySelectorAll('input.iem-recv-qty').forEach(function(inp){
                inp.disabled = !on;
                if (!on) inp.value = 0;
            });
        });
        recvColorSum(line);
    }

    var $recvSkip = document.getElementById('iem-recv-skip');

    function refreshRecvProgress(){
        if (!$recvCount) return;
        // Modo "sin afectar inventario": no hay destino que validar; submit libre.
        if ($recvSkip && $recvSkip.checked) {
            $recvProg.style.background = '#fff8e5';
            $recvProg.style.borderLeft = '4px solid #d68f00';
            $recvProg.style.color      = '#9a6700';
            $recvCount.textContent = '—';
            $recvTotal.textContent = document.querySelectorAll('tr.iem-recv-row').length;
            if ($recvSubmit) $recvSubmit.disabled = false;
            return;
        }
        var rows = document.querySelectorAll('tr.iem-recv-row');
        var total = rows.length, ready = 0;
        rows.forEach(function(row){ if (iemRecvRowReady(row)) ready++; });
        $recvCount.textContent = ready;
        $recvTotal.textContent = total;
        if (ready === total && total > 0) {
            $recvProg.style.background = '#edfaef';
            $recvProg.style.borderLeft = '4px solid #1d6e3d';
            $recvProg.style.color      = '#1d6e3d';
            if ($recvSubmit) $recvSubmit.disabled = false;
        } else {
            $recvProg.style.background = '#fcefef';
            $recvProg.style.borderLeft = '4px solid #b32d2e';
            $recvProg.style.color      = '#b32d2e';
            if ($recvSubmit) $recvSubmit.disabled = true;
        }
    }

    // Toggle "sin afectar inventario": al activarlo, los destinos dejan de ser
    // obligatorios (no se envían) y la tabla se atenúa; el submit se libera.
    if ($recvSkip) {
        $recvSkip.addEventListener('change', function(){
            var on = $recvSkip.checked;
            document.querySelectorAll('select.iem-recv-select').forEach(function(sel){
                sel.required = !on;
            });
            var destTable = document.querySelector('#iem-receive-form table');
            if (destTable) {
                destTable.style.opacity = on ? '0.45' : '';
                destTable.style.pointerEvents = on ? 'none' : '';
            }
            refreshRecvProgress();
        });
    }

    document.addEventListener('change', function(e){
        if (!e.target.classList) return;
        if (e.target.classList.contains('iem-recv-select')) {
            refreshRecvProgress();
        } else if (e.target.classList.contains('iem-recv-sucursal')) {
            recvPickSucursal(e.target.getAttribute('data-line'), e.target.value);
            refreshRecvProgress();
        }
    });
    document.addEventListener('input', function(e){
        if (e.target.classList && e.target.classList.contains('iem-recv-qty')) {
            recvColorSum(e.target.getAttribute('data-line'));
            refreshRecvProgress();
        }
    });

    // Aplica la sucursal sugerida pre-seleccionada (si la hubo) al cargar.
    document.querySelectorAll('select.iem-recv-sucursal').forEach(function(sel){
        if (sel.value) recvPickSucursal(sel.getAttribute('data-line'), sel.value);
    });
    refreshRecvProgress();

    // El panel de "Gastos de importación" (registro + validación + moneda dual)
    // vive en templates/partials/purchase-expenses.php con su propio <script>
    // autocontenido, reutilizado por el form y por la vista de compra recibida.
})();
</script>

<style>
/* Local: ajuste de altura de Select2 dentro del form. El resto
 * (.iem-savestate) viene de assets/css/admin.css (v3.25+). */
.iem-purchase-form .select2-container { min-height: 28px; }

/* Cabecera en 3 columnas: cada columna apila sus campos con separación
 * uniforme; los inputs ocupan el 100% del ancho para que queden alineados. */
.iem-purchase-form .iem-h-col   { display:flex; flex-direction:column; gap:14px; }
.iem-purchase-form .iem-h-field { display:block; margin:0; font-weight:600; }
.iem-purchase-form .iem-h-field input,
.iem-purchase-form .iem-h-field select,
.iem-purchase-form .iem-h-field textarea { display:block; width:100%; margin-top:4px; font-weight:400; box-sizing:border-box; }
/* Altura uniforme para que inputs y selects queden parejos (textarea aparte). */
.iem-purchase-form .iem-h-field input,
.iem-purchase-form .iem-h-field select { height:34px; line-height:normal; }
/* La 3ª columna sólo tiene CBM + Notas + botón; estiramos Notas para
 * que el bloque alcance la altura de las columnas 1 y 2. */
.iem-purchase-form .iem-h-notes { flex:1 1 auto; display:flex; flex-direction:column; }
.iem-purchase-form .iem-h-notes textarea { flex:1 1 auto; min-height:120px; }
@media (max-width: 900px) {
    .iem-purchase-form .iem-h-grid { grid-template-columns:1fr !important; }
}
</style>
