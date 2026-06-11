<?php
if (!defined('ABSPATH')) {
    exit;
}
/**
 * Pantalla de mermas: form de registro (sidebar) + listado filtrable + export.
 *
 * @var array  $mermas      Filas de iem_mermas.
 * @var array  $sucursales  Mapa slug => nombre.
 * @var array  $tipos       Mapa slug => label.
 * @var array  $filter      Filtros actuales.
 * @var string $nonce       Nonce admin-post.
 * @var string $ajax_nonce  Nonce AJAX (iem_ajax_action).
 * @var string $flash_msg   'ok' | 'sku_not_found' | ''
 * @var string $flash_err   mensaje de error (urlencoded).
 */
$base_url = admin_url('admin-post.php');

$register_url = add_query_arg([
    'action'                 => 'iem_register_merma',
    IEM_Admin::NONCE_FIELD   => $nonce,
], $base_url);

$export_url = add_query_arg(array_merge([
    'action'                 => 'iem_export_mermas',
    IEM_Admin::NONCE_FIELD   => $nonce,
], array_filter([
    'sucursal_slug' => $filter['sucursal_slug'],
    'tipo'          => $filter['tipo'],
    'from'          => $filter['from'],
    'to'            => $filter['to'],
])), $base_url);
?>
<div class="wrap">
    <h1 class="wp-heading-inline">Mermas / Defectuosos</h1>
    <a href="<?php echo esc_url($export_url); ?>" class="page-title-action">Descargar CSV</a>
    <hr class="wp-header-end">

    <?php if ($flash_msg === 'ok'): ?>
        <div class="notice notice-success is-dismissible"><p>Merma registrada.</p></div>
    <?php elseif ($flash_msg === 'returned'): ?>
        <div class="notice notice-success is-dismissible"><p>Merma retornada al inventario.</p></div>
    <?php elseif ($flash_msg === 'sku_not_found'): ?>
        <div class="notice notice-error is-dismissible"><p>No se encontró ningún producto con ese SKU.</p></div>
    <?php endif; ?>
    <?php if ($flash_err !== ''): ?>
        <div class="notice notice-error is-dismissible"><p><?php echo esc_html($flash_err); ?></p></div>
    <?php endif; ?>

    <?php // ── Form de nueva merma (arriba, ancho completo, campos en fila) ── ?>
    <div class="iem-card" style="margin-top:14px;">
        <h2 style="margin-top:0;">Registrar nueva merma</h2>
        <form method="post" action="<?php echo esc_url($register_url); ?>" id="iem-merma-form"
              style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-start;">

            <div style="flex:1 1 240px;min-width:220px;">
                <label><strong>SKU del producto:</strong>
                    <input type="text" name="sku" id="iem-merma-sku" required autocomplete="off"
                           class="iem-mono" style="width:100%;">
                </label>
                <div id="iem-merma-sku-feedback"
                     style="min-height:42px;margin:6px 0 0;padding:8px 10px;border-radius:3px;
                            font-size:12px;background:#f6f7f7;color:#666;border:1px solid #dcdcde;">
                    Ingresa un SKU para validarlo.
                </div>
            </div>

            <label style="flex:0 0 170px;"><strong>Sucursal:</strong>
                <select name="sucursal_slug" required style="width:100%;">
                    <option value="">— Selecciona —</option>
                    <?php foreach ($sucursales as $slug => $name): ?>
                        <option value="<?php echo esc_attr($slug); ?>"><?php echo esc_html($name); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label style="flex:0 0 90px;"><strong>Cantidad:</strong>
                <input type="number" name="qty" min="1" step="1" value="1" required style="width:100%;">
            </label>

            <label style="flex:0 0 150px;"><strong>Tipo:</strong>
                <select name="tipo" required style="width:100%;">
                    <?php foreach ($tipos as $slug => $name): ?>
                        <option value="<?php echo esc_attr($slug); ?>"><?php echo esc_html($name); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <div style="flex:1 1 240px;min-width:200px;">
                <label><strong>Nota / defecto encontrado:</strong>
                    <textarea name="notes" rows="2" style="width:100%;"
                              placeholder="Ej.: rayón en la tapa, costura abierta, falla eléctrica…"></textarea>
                </label>
            </div>

            <div style="flex:0 0 auto;display:flex;flex-direction:column;gap:8px;padding-top:22px;">
                <label style="white-space:nowrap;">
                    <input type="checkbox" name="decrement_wc" value="1">
                    Descontar de WC
                </label>
                <button type="submit" class="button button-primary" id="iem-merma-submit" disabled>
                    Registrar merma
                </button>
            </div>
        </form>
        <p class="iem-help" style="margin-bottom:0;">
            Si se marca <strong>Descontar de WC</strong>, el plugin llamará a
            <code>wc_update_product_stock</code> para decrementar la cantidad de la variación
            (o producto simple). Productos variables (padre) son rechazados.
        </p>
    </div>
        <script>
        (function () {
            var IEM_AJAX = {
                url:   <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>,
                nonce: <?php echo wp_json_encode($ajax_nonce); ?>,
                field: <?php echo wp_json_encode(IEM_Ajax::NONCE_FIELD); ?>
            };
            var input    = document.getElementById('iem-merma-sku');
            var feedback = document.getElementById('iem-merma-sku-feedback');
            var submit   = document.getElementById('iem-merma-submit');
            var sucSel   = document.querySelector('#iem-merma-form select[name="sucursal_slug"]');
            var debounce = null;
            var lastReq  = 0;

            function applySuggestedSucursal(slug) {
                if (!sucSel || !slug) return false;
                for (var i = 0; i < sucSel.options.length; i++) {
                    if (sucSel.options[i].value === slug) {
                        sucSel.value = slug;
                        // Marcar visualmente que fue auto-seteado.
                        sucSel.dataset.iemAuto = '1';
                        sucSel.style.background = '#eef7ee';
                        return true;
                    }
                }
                return false;
            }

            // Si el usuario cambia la sucursal manualmente, limpiar el "auto" tag.
            if (sucSel) {
                sucSel.addEventListener('change', function () {
                    sucSel.dataset.iemAuto = '';
                    sucSel.style.background = '';
                });
            }

            function paint(state, html) {
                var palette = {
                    idle: { bg: '#f6f7f7', fg: '#666',   bd: '#dcdcde' },
                    busy: { bg: '#fff8e5', fg: '#8a6d3b', bd: '#f0c87a' },
                    ok:   { bg: '#e8f5e9', fg: '#155724', bd: '#a3d9a5' },
                    err:  { bg: '#fdecec', fg: '#a00',    bd: '#e7a3a3' }
                };
                var p = palette[state] || palette.idle;
                feedback.style.background   = p.bg;
                feedback.style.color        = p.fg;
                feedback.style.borderColor  = p.bd;
                feedback.innerHTML          = html;
                submit.disabled = (state !== 'ok');
            }

            function validate(sku) {
                if (!sku) {
                    paint('idle', 'Ingresa un SKU para validarlo.');
                    return;
                }
                paint('busy', 'Buscando <code>' + escapeHtml(sku) + '</code>…');
                var reqId = ++lastReq;
                var body  = new URLSearchParams();
                body.append('action', 'iem_resolve_sku');
                body.append(IEM_AJAX.field, IEM_AJAX.nonce);
                body.append('sku', sku);

                fetch(IEM_AJAX.url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString()
                })
                .then(function (r) { return r.json(); })
                .then(function (json) {
                    if (reqId !== lastReq) return; // respuesta vieja, ignorar
                    if (json && json.success && json.data) {
                        var d = json.data;
                        var stockTxt = (d.current_stock === null || typeof d.current_stock === 'undefined')
                            ? '<em>sin gestión</em>'
                            : '<strong>' + d.current_stock + '</strong>';
                        var typeBadge = d.is_variation
                            ? '<span style="background:#dbe9f7;color:#1d3a5f;padding:1px 6px;border-radius:8px;font-size:11px;font-weight:600;">VARIACIÓN</span>'
                            : '<span style="background:#e5e5e5;color:#333;padding:1px 6px;border-radius:8px;font-size:11px;font-weight:600;">SIMPLE</span>';

                        // Auto-selección de sucursal si el SKU la trae implícita.
                        var sucLine = '';
                        if (d.suggested_sucursal_slug) {
                            var ok = applySuggestedSucursal(d.suggested_sucursal_slug);
                            if (ok) {
                                sucLine = '<br><span style="font-size:11px;">Sucursal: <strong>'
                                    + escapeHtml(d.suggested_sucursal_name || d.suggested_sucursal_slug)
                                    + '</strong> <span style="color:#107010;">(auto)</span></span>';
                            } else {
                                sucLine = '<br><span style="font-size:11px;color:#a00;">Sucursal sugerida (<code>'
                                    + escapeHtml(d.suggested_sucursal_slug)
                                    + '</code>) no está en la lista — seleccionar manualmente.</span>';
                            }
                        } else {
                            sucLine = '<br><span style="font-size:11px;color:#8a6d3b;">Este SKU no trae sucursal asignada — seleccionar manualmente.</span>';
                        }

                        paint('ok',
                            '✓ ' + typeBadge + ' <strong>' + escapeHtml(d.name) + '</strong>' +
                            '<br><span style="font-size:11px;">Stock actual: ' + stockTxt + '</span>' +
                            sucLine
                        );
                    } else {
                        var msg = (json && json.data && json.data.message) || 'Error desconocido.';
                        paint('err', '✗ ' + escapeHtml(msg));
                    }
                })
                .catch(function () {
                    if (reqId !== lastReq) return;
                    paint('err', '✗ Error de conexión.');
                });
            }

            function escapeHtml(s) {
                return String(s).replace(/[&<>"']/g, function (c) {
                    return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
                });
            }

            input.addEventListener('input', function () {
                clearTimeout(debounce);
                var v = input.value.trim();
                if (!v) { paint('idle', 'Ingresa un SKU para validarlo.'); return; }
                debounce = setTimeout(function () { validate(v); }, 300);
            });
            input.addEventListener('blur', function () {
                clearTimeout(debounce);
                var v = input.value.trim();
                if (v) validate(v);
            });
        })();
        </script>

        <?php // ── Listado (ancho completo) ─────────────────────────── ?>
        <div style="margin-top:16px;">
            <form method="get" action="" style="margin-bottom:14px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                <input type="hidden" name="post_type" value="product">
                <input type="hidden" name="page" value="<?php echo esc_attr(IEM_Admin::PAGE_SLUG); ?>">
                <input type="hidden" name="tab" value="mermas">

                <label>Sucursal:
                    <select name="f_sucursal">
                        <option value="">— Todas —</option>
                        <?php foreach ($sucursales as $slug => $name): ?>
                            <option value="<?php echo esc_attr($slug); ?>" <?php selected($filter['sucursal_slug'], $slug); ?>>
                                <?php echo esc_html($name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Tipo:
                    <select name="f_tipo">
                        <option value="">— Cualquiera —</option>
                        <?php foreach ($tipos as $slug => $name): ?>
                            <option value="<?php echo esc_attr($slug); ?>" <?php selected($filter['tipo'], $slug); ?>>
                                <?php echo esc_html($name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Desde:
                    <input type="date" name="f_from" value="<?php echo esc_attr($filter['from']); ?>">
                </label>
                <label>Hasta:
                    <input type="date" name="f_to" value="<?php echo esc_attr($filter['to']); ?>">
                </label>
                <button type="submit" class="button">Filtrar</button>
            </form>

            <?php if (empty($mermas)): ?>
                <p>No hay mermas registradas con los filtros actuales.</p>
            <?php else: ?>
                <table class="wp-list-table widefat striped">
                    <thead>
                        <tr>
                            <th style="width:10%">Fecha</th>
                            <th style="width:16%">Producto</th>
                            <th style="width:8%">SKU</th>
                            <th style="width:9%">Sucursal</th>
                            <th style="width:4%">Cant.</th>
                            <th style="width:7%">Costo u.</th>
                            <th style="width:7%">Subtotal</th>
                            <th style="width:7%">Tipo</th>
                            <th>Nota / defecto</th>
                            <th style="width:4%">WC</th>
                            <th style="width:8%">Estado</th>
                            <th style="width:4%">Kardex</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                    $total_qty       = 0;
                    $total_cost_all  = 0.0;
                    $total_cost_act  = 0.0; // solo no-retornadas

                    foreach ($mermas as $m):
                        $is_returned = !empty($m['returned_at']);
                        $qty         = (int) $m['qty'];
                        $unit_cost   = isset($m['cost_at_register']) && $m['cost_at_register'] !== null
                            ? (float) $m['cost_at_register'] : null;
                        $subtotal    = $unit_cost !== null ? $unit_cost * $qty : null;

                        $total_qty      += $qty;
                        if ($subtotal !== null) {
                            $total_cost_all += $subtotal;
                            if (!$is_returned) $total_cost_act += $subtotal;
                        }

                        $return_url = add_query_arg([
                            'action'                 => 'iem_return_merma',
                            'merma_id'               => (int) $m['id'],
                            IEM_Admin::NONCE_FIELD   => $nonce,
                        ], $base_url);
                        $row_style = $is_returned ? 'background:#f4f4f4;color:#666;' : '';
                        $confirm = !empty($m['decremented_wc'])
                            ? 'Esto incrementará '. $qty .' al stock de WC del producto. ¿Confirmar retorno al inventario?'
                            : 'Marcar esta merma como retornada (sin tocar stock de WC, porque originalmente no se decrementó). ¿Confirmar?';
                    ?>
                        <tr style="<?php echo $row_style; ?>">
                            <td><?php echo esc_html($m['created_at']); ?></td>
                            <td><?php echo esc_html($m['name']); ?></td>
                            <td><code><?php echo esc_html($m['sku']); ?></code></td>
                            <td><?php echo esc_html($sucursales[$m['sucursal_slug']] ?? $m['sucursal_slug']); ?></td>
                            <td><?php echo $qty; ?></td>
                            <td style="text-align:right;">
                                <?php echo $unit_cost !== null ? number_format($unit_cost, 2, '.', ',') : '<span style="color:#bbb;">—</span>'; ?>
                            </td>
                            <td style="text-align:right;font-weight:600;">
                                <?php echo $subtotal !== null ? number_format($subtotal, 2, '.', ',') : '<span style="color:#bbb;">—</span>'; ?>
                            </td>
                            <td><?php echo esc_html($tipos[$m['tipo']] ?? $m['tipo']); ?></td>
                            <td class="iem-merma-note-cell" data-id="<?php echo (int) $m['id']; ?>">
                                <span class="iem-merma-note-text<?php echo trim((string) ($m['notes'] ?? '')) === '' ? ' iem-help' : ''; ?>"><?php
                                    $note_txt = trim((string) ($m['notes'] ?? ''));
                                    echo $note_txt !== '' ? esc_html($note_txt) : '— sin nota —';
                                ?></span>
                                <a href="#" class="iem-merma-note-edit" style="margin-left:6px;font-size:11px;">✏️ editar</a>
                            </td>
                            <td>
                                <?php if (!empty($m['decremented_wc'])): ?>
                                    <span class="iem-status-ok">Sí</span>
                                <?php else: ?>
                                    <span class="iem-status-muted">No</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($is_returned): ?>
                                    <span title="<?php echo esc_attr('Retornada: ' . $m['returned_at']); ?>"
                                          style="display:inline-block;background:#dbe9f7;color:#1d3a5f;
                                                 padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;">
                                        ↩ Retornada
                                    </span>
                                <?php else: ?>
                                    <a class="button button-small"
                                       onclick="return confirm(<?php echo esc_attr(wp_json_encode($confirm)); ?>);"
                                       href="<?php echo esc_url($return_url); ?>">
                                        ↩ Retornar
                                    </a>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $kx_url = IEM_Admin::tab_url('kardex', ['item_id' => (int) $m['item_id']]);
                                ?>
                                <a href="<?php echo esc_url($kx_url); ?>"
                                   class="button button-small"
                                   title="Ver kardex de este ítem">📊</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="background:#f6f7f7;font-weight:700;">
                            <td colspan="4" style="text-align:right;">Totales (filtrados):</td>
                            <td><?php echo $total_qty; ?></td>
                            <td colspan="2" style="text-align:right;">
                                Costo total:
                                <span style="color:#107010;">
                                    Bs. <?php echo number_format($total_cost_all, 2, '.', ','); ?>
                                </span>
                            </td>
                            <td colspan="5" style="text-align:right;color:#666;font-weight:400;font-size:12px;">
                                Solo activas (sin retornadas):
                                <strong style="color:#a00;">Bs. <?php echo number_format($total_cost_act, 2, '.', ','); ?></strong>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            <?php endif; ?>
        </div>

    <?php // ── Edición inline de la nota/defecto por merma ───────────────── ?>
    <script>
    (function () {
        var AJAX = {
            url:   <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>,
            nonce: <?php echo wp_json_encode($ajax_nonce); ?>,
            field: <?php echo wp_json_encode(IEM_Ajax::NONCE_FIELD); ?>
        };
        function esc(s) {
            return String(s).replace(/[&<>"']/g, function (c) {
                return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
            });
        }
        document.addEventListener('click', function (e) {
            var editLink = e.target.closest('.iem-merma-note-edit');
            if (editLink) {
                e.preventDefault();
                var cell = editLink.closest('.iem-merma-note-cell');
                if (!cell || cell.querySelector('textarea')) return; // ya editando
                var span    = cell.querySelector('.iem-merma-note-text');
                var current = (span && !span.classList.contains('iem-help')) ? span.textContent : '';
                cell.dataset.prev = cell.innerHTML;
                cell.innerHTML =
                    '<textarea class="iem-merma-note-input" rows="2" style="width:100%;">' + esc(current) + '</textarea>' +
                    '<div style="margin-top:4px;">' +
                    '<button type="button" class="button button-small iem-merma-note-save">Guardar</button> ' +
                    '<a href="#" class="iem-merma-note-cancel" style="font-size:11px;">cancelar</a>' +
                    '<span class="iem-merma-note-msg" style="margin-left:8px;font-size:11px;color:#a00;"></span>' +
                    '</div>';
                var ta = cell.querySelector('textarea');
                ta.focus();
                return;
            }
            var cancelLink = e.target.closest('.iem-merma-note-cancel');
            if (cancelLink) {
                e.preventDefault();
                var c1 = cancelLink.closest('.iem-merma-note-cell');
                if (c1 && typeof c1.dataset.prev !== 'undefined') c1.innerHTML = c1.dataset.prev;
                return;
            }
            var saveBtn = e.target.closest('.iem-merma-note-save');
            if (saveBtn) {
                e.preventDefault();
                var cell2 = saveBtn.closest('.iem-merma-note-cell');
                var id    = cell2 ? (cell2.dataset.id || 0) : 0;
                var ta2   = cell2 ? cell2.querySelector('textarea') : null;
                var msg   = cell2 ? cell2.querySelector('.iem-merma-note-msg') : null;
                if (!cell2 || !ta2) return;
                var val = ta2.value;
                saveBtn.disabled = true;
                var body = new URLSearchParams();
                body.append('action', 'iem_update_merma_notes');
                body.append(AJAX.field, AJAX.nonce);
                body.append('merma_id', id);
                body.append('notes', val);
                fetch(AJAX.url, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString()
                })
                .then(function (r) { return r.json(); })
                .then(function (json) {
                    if (json && json.success) {
                        var txt = (json.data && typeof json.data.notes !== 'undefined') ? json.data.notes : val;
                        var empty = txt.trim() === '';
                        cell2.innerHTML =
                            '<span class="iem-merma-note-text' + (empty ? ' iem-help' : '') + '">' +
                            esc(empty ? '— sin nota —' : txt) + '</span>' +
                            '<a href="#" class="iem-merma-note-edit" style="margin-left:6px;font-size:11px;">✏️ editar</a>';
                    } else {
                        if (msg) msg.textContent = (json && json.data && json.data.message) || 'Error al guardar.';
                        saveBtn.disabled = false;
                    }
                })
                .catch(function () {
                    if (msg) msg.textContent = 'Error de conexión.';
                    saveBtn.disabled = false;
                });
            }
        });
    })();
    </script>
</div>
