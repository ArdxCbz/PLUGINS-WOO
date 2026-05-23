<?php
if (!defined('ABSPATH')) {
    exit;
}
/**
 * "Mi Conteo": UI simplificada para contadores asignados (y admins).
 * Muestra exclusivamente la sesión del mes actual sobre la sucursal del usuario.
 *
 * Estados:
 *  1. Sin sucursal (admin que aún no eligió) → selector.
 *  2. Con sucursal pero sin sesión           → botón "Iniciar conteo".
 *  3. Sesión draft                            → tabla con autosave + botón cerrar.
 *  4. Sesión cerrada (lock-on-close)          → tabla read-only + aviso.
 *
 * @var array     $sucursales  Mapa slug => nombre.
 * @var bool      $is_admin    Si el usuario actual es admin del plugin.
 * @var string    $my_sucursal Slug de la sucursal (puede ser '' solo para admin).
 * @var string    $period      'YYYY-MM' del mes actual.
 * @var array|null $session    Fila de iem_count_sessions o null.
 * @var array     $lines       Líneas de la sesión (vacío si no hay sesión).
 * @var array|null $progress   ['total','contados','ok','revisar'] o null.
 * @var string    $nonce       Nonce admin-post.
 * @var string    $ajax_nonce  Nonce AJAX.
 * @var string    $base_url    admin-post.php.
 */
$page_url = admin_url('admin.php?page=' . IEM_Admin::PAGE_MY_COUNT);
?>
<div class="wrap">
    <h1 class="wp-heading-inline">Mi Conteo de Inventario</h1>
    <hr class="wp-header-end">

    <?php // ── Estado 1: admin sin sucursal elegida ───────────────────── ?>
    <?php if ($my_sucursal === '' && $is_admin): ?>
        <div class="notice notice-info" style="margin:14px 0;max-width:680px;">
            <p>Eres administrador. Para usar "Mi Conteo" elige sobre qué sucursal trabajar:</p>
            <form method="get" action="" style="margin:6px 0 0;">
                <input type="hidden" name="page" value="<?php echo esc_attr(IEM_Admin::PAGE_MY_COUNT); ?>">
                <select name="sucursal" required style="min-width:240px;">
                    <option value="">— Selecciona sucursal —</option>
                    <?php foreach ($sucursales as $slug => $name): ?>
                        <option value="<?php echo esc_attr($slug); ?>"><?php echo esc_html($name); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="button button-primary">Ir</button>
            </form>
        </div>
    <?php
        echo '</div>'; // wrap
        return;
    endif; ?>

    <?php
    $suc_name = $sucursales[$my_sucursal] ?? $my_sucursal;
    ?>
    <p style="margin:14px 0 6px;">
        <strong>Período:</strong> <?php echo esc_html($period); ?>
        &nbsp;·&nbsp;
        <strong>Sucursal:</strong> <?php echo esc_html($suc_name); ?>
        <?php if ($is_admin): ?>
            &nbsp;<a href="<?php echo esc_url($page_url); ?>" class="button button-small">Cambiar</a>
        <?php endif; ?>
    </p>

    <?php // ── Estado 2: sin sesión iniciada ──────────────────────────── ?>
    <?php if (!$session):
        $start_url = add_query_arg([
            'action'               => 'iem_start_session',
            'sucursal'             => $my_sucursal,
            IEM_Admin::NONCE_FIELD => $nonce,
        ], $base_url);
    ?>
        <div class="notice notice-info" style="margin:14px 0;max-width:680px;">
            <p>
                Aún no se ha iniciado el conteo de <strong><?php echo esc_html($suc_name); ?></strong>
                para <?php echo esc_html($period); ?>.
            </p>
            <p style="margin:6px 0 0;">
                <a class="button button-primary button-hero" href="<?php echo esc_url($start_url); ?>">
                    Iniciar conteo
                </a>
            </p>
            <p style="color:#666;font-style:italic;font-size:12px;margin:8px 0 0;">
                Al iniciar se tomará una foto del inventario actual. Llenarás los valores físicos y
                <strong>al finalizar la sesión queda bloqueada</strong>. Solo un administrador puede reabrirla.
            </p>
        </div>
    <?php
        echo '</div>'; // wrap
        return;
    endif; ?>

    <?php
    $is_draft  = $session['status'] === 'draft';
    $is_closed = $session['status'] === 'closed';
    ?>

    <?php // ── Estado 3 y 4: sesión existente (draft o closed) ────────── ?>
    <?php if ($is_closed): ?>
        <div class="notice notice-success" style="margin:14px 0;max-width:680px;">
            <p>
                <strong>Conteo finalizado</strong> el <?php echo esc_html($session['closed_at']); ?>.
                La sesión está bloqueada. Si necesitas correcciones, contacta al administrador.
            </p>
        </div>
    <?php endif; ?>

    <?php if ($progress): ?>
        <p style="margin:8px 0;">
            Progreso:
            <strong><?php echo (int) $progress['contados']; ?></strong>
            de
            <strong><?php echo (int) $progress['total']; ?></strong>
            (<span style="color:#107010;">OK <?php echo (int) $progress['ok']; ?></span>
            · <span style="color:#a00;">Revisar <?php echo (int) $progress['revisar']; ?></span>)
        </p>
    <?php endif; ?>

    <?php
    // Partir las líneas en originales (snapshot) vs extras (ad-hoc del contador).
    $lines_main  = array_filter($lines, function ($L) { return empty($L['is_extra']); });
    $lines_extra = array_filter($lines, function ($L) { return !empty($L['is_extra']); });
    ?>

    <table class="wp-list-table widefat fixed striped" id="iem-mc-table" style="max-width:980px;">
        <thead>
            <tr>
                <th style="width:16%">SKU</th>
                <th style="width:38%">Producto</th>
                <th style="width:18%">Categoría</th>
                <th style="width:14%">Tu conteo</th>
                <th style="width:8%">Estado</th>
                <th style="width:6%">Auto</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($lines_main as $L):
            $status = (string) $L['status_at_count'];
            $row_cls = '';
            if ($status === 'OK')      $row_cls = 'iem-row-ok';
            if ($status === 'Revisar') $row_cls = 'iem-row-revisar';
        ?>
            <tr class="<?php echo esc_attr($row_cls); ?>" data-line-id="<?php echo (int) $L['id']; ?>">
                <td><code><?php echo esc_html($L['sku']); ?></code></td>
                <td><?php echo esc_html($L['name']); ?></td>
                <td><?php echo esc_html($L['category']); ?></td>
                <td>
                    <input type="number" min="0" step="1"
                           class="iem-mc-input small-text"
                           value="<?php echo $L['counted_qty'] === null ? '' : (int) $L['counted_qty']; ?>"
                           <?php if ($is_closed) echo 'disabled'; ?>
                           style="width:90px;">
                </td>
                <td class="iem-mc-status">
                    <?php if ($status === 'OK'):      echo '<span style="color:#107010;font-weight:600;">OK</span>'; ?>
                    <?php elseif ($status === 'Revisar'): echo '<span style="color:#a00;font-weight:600;">Revisar</span>'; ?>
                    <?php else: echo '<span style="color:#888;">—</span>'; ?>
                    <?php endif; ?>
                </td>
                <td class="iem-mc-save"><span style="color:#bbb;">—</span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php // ── Filas adicionales (productos fuera del listado) ────────────── ?>
    <h2 style="margin:28px 0 10px;font-size:16px;">Filas adicionales</h2>

    <table class="wp-list-table widefat fixed striped" id="iem-mc-extra-table" style="max-width:980px;">
        <thead>
            <tr>
                <th style="width:14%">SKU</th>
                <th style="width:28%">Producto</th>
                <th style="width:24%">Notas</th>
                <th style="width:12%">Tu conteo</th>
                <th style="width:8%">Estado</th>
                <th style="width:6%">Auto</th>
                <th style="width:8%"><?php echo $is_draft ? 'Acción' : ''; ?></th>
            </tr>
        </thead>
        <tbody id="iem-mc-extra-tbody">
        <?php if (empty($lines_extra)): ?>
            <tr id="iem-mc-extra-empty">
                <td colspan="7" style="color:#888;font-style:italic;text-align:center;padding:14px;">
                    No hay filas adicionales todavía.
                </td>
            </tr>
        <?php else:
            foreach ($lines_extra as $L):
                $status = (string) $L['status_at_count'];
                $row_cls = '';
                if ($status === 'OK')      $row_cls = 'iem-row-ok';
                if ($status === 'Revisar') $row_cls = 'iem-row-revisar';
        ?>
            <tr class="iem-mc-extra-row <?php echo esc_attr($row_cls); ?>" data-line-id="<?php echo (int) $L['id']; ?>">
                <td><code><?php echo esc_html($L['sku'] ?: '—'); ?></code></td>
                <td><?php echo esc_html($L['name']); ?></td>
                <td style="font-size:12px;color:#555;"><?php echo esc_html($L['notes'] ?? ''); ?></td>
                <td>
                    <input type="number" min="0" step="1"
                           class="iem-mc-input small-text"
                           value="<?php echo $L['counted_qty'] === null ? '' : (int) $L['counted_qty']; ?>"
                           <?php if ($is_closed) echo 'disabled'; ?>
                           style="width:90px;">
                </td>
                <td class="iem-mc-status">
                    <?php if ($status === 'OK'):      echo '<span style="color:#107010;font-weight:600;">OK</span>'; ?>
                    <?php elseif ($status === 'Revisar'): echo '<span style="color:#a00;font-weight:600;">Revisar</span>'; ?>
                    <?php else: echo '<span style="color:#888;">—</span>'; ?>
                    <?php endif; ?>
                </td>
                <td class="iem-mc-save"><span style="color:#bbb;">—</span></td>
                <td>
                    <?php if ($is_draft): ?>
                        <button type="button" class="button button-small iem-mc-extra-del"
                                title="Eliminar fila adicional">✕</button>
                    <?php endif; ?>
                </td>
            </tr>
        <?php
            endforeach;
        endif; ?>
        </tbody>
    </table>

    <?php if ($is_draft): ?>
        <div id="iem-mc-extra-form" style="margin:14px 0;padding:14px;background:#fff;border:1px solid #c3c4c7;border-radius:4px;max-width:980px;">
            <h3 style="margin:0 0 8px;font-size:14px;">Agregar producto fuera del listado</h3>
            <div style="display:grid;grid-template-columns:1fr 1fr 90px;gap:10px;align-items:end;">
                <p style="margin:0;">
                    <label style="display:block;font-weight:600;font-size:12px;">
                        Nombre del producto <span style="color:#a00;">*</span>
                    </label>
                    <input type="text" id="iem-mc-extra-name" required style="width:100%;" autocomplete="off">
                </p>
                <p style="margin:0;">
                    <label style="display:block;font-weight:600;font-size:12px;">
                        SKU (opcional)
                    </label>
                    <input type="text" id="iem-mc-extra-sku" style="width:100%;font-family:monospace;" autocomplete="off">
                </p>
                <p style="margin:0;">
                    <label style="display:block;font-weight:600;font-size:12px;">
                        Cantidad <span style="color:#a00;">*</span>
                    </label>
                    <input type="number" id="iem-mc-extra-qty" min="0" step="1" value="1" required style="width:100%;">
                </p>
            </div>
            <p style="margin:10px 0 0;">
                <label style="display:block;font-weight:600;font-size:12px;">Notas (opcional)</label>
                <textarea id="iem-mc-extra-notes" rows="2" style="width:100%;resize:vertical;"
                          placeholder="Ej: estaba en estante de SCZ pero la etiqueta dice CBBA"></textarea>
            </p>
            <p style="margin:10px 0 0;">
                <button type="button" id="iem-mc-extra-add" class="button button-primary">
                    + Agregar fila adicional
                </button>
                <span id="iem-mc-extra-msg" style="margin-left:10px;font-size:12px;"></span>
            </p>
        </div>

        <p style="margin:18px 0;">
            <button type="button" id="iem-mc-close" class="button button-primary"
                    onclick="return iemMcCloseConfirm(this);">
                ✓ Finalizar conteo (bloquea la sesión)
            </button>
            <span style="color:#666;font-style:italic;margin-left:8px;">
                Una vez finalizado, solo un administrador puede reabrir.
            </span>
        </p>
    <?php endif; ?>
</div>

<style>
.iem-row-ok      { background: #f1faf1 !important; }
.iem-row-revisar { background: #fdecec !important; }
.iem-mc-save     { font-size: 11px; }
</style>

<script>
(function () {
    var IEM_AJAX = {
        url:   <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>,
        nonce: <?php echo wp_json_encode($ajax_nonce); ?>,
        field: <?php echo wp_json_encode(IEM_Ajax::NONCE_FIELD); ?>,
        sid:   <?php echo (int) $session['id']; ?>,
        draft: <?php echo $is_draft ? 'true' : 'false'; ?>,
        page:  <?php echo wp_json_encode($page_url); ?>
    };

    if (!IEM_AJAX.draft) return; // closed → solo visualización

    function paintStatus(row, status) {
        var cell = row.querySelector('.iem-mc-status');
        row.classList.remove('iem-row-ok','iem-row-revisar');
        if (status === 'OK') {
            cell.innerHTML = '<span style="color:#107010;font-weight:600;">OK</span>';
            row.classList.add('iem-row-ok');
        } else if (status === 'Revisar') {
            cell.innerHTML = '<span style="color:#a00;font-weight:600;">Revisar</span>';
            row.classList.add('iem-row-revisar');
        } else {
            cell.innerHTML = '<span style="color:#888;">—</span>';
        }
    }

    function setSave(row, kind, txt) {
        var cell = row.querySelector('.iem-mc-save');
        var color = { ok: '#107010', err: '#a00', busy: '#a67c00', idle: '#bbb' }[kind] || '#888';
        cell.innerHTML = '<span style="color:' + color + ';">' + txt + '</span>';
    }

    var debouncers = new WeakMap();

    document.addEventListener('input', function (e) {
        if (!e.target.classList.contains('iem-mc-input')) return;
        var input = e.target;
        var row   = input.closest('tr');
        if (debouncers.has(input)) clearTimeout(debouncers.get(input));
        setSave(row, 'busy', '…');
        var t = setTimeout(function () { save(row, input); }, 400);
        debouncers.set(input, t);
    });

    function save(row, input) {
        var raw = input.value.trim();
        var body = new URLSearchParams();
        body.append('action', 'iem_save_line');
        body.append(IEM_AJAX.field, IEM_AJAX.nonce);
        body.append('session_id', IEM_AJAX.sid);
        body.append('line_id', row.dataset.lineId);
        body.append('qty', raw);

        fetch(IEM_AJAX.url, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        })
        .then(function (r) { return r.json(); })
        .then(function (json) {
            if (json && json.success) {
                paintStatus(row, json.data && json.data.status);
                setSave(row, 'ok', '✓');
            } else {
                var msg = (json && json.data && json.data.message) || 'Error';
                setSave(row, 'err', '✗ ' + msg);
            }
        })
        .catch(function () { setSave(row, 'err', '✗ red'); });
    }

    // ── Filas adicionales: agregar y eliminar ──────────────────────────────
    var extraBtn   = document.getElementById('iem-mc-extra-add');
    var extraMsg   = document.getElementById('iem-mc-extra-msg');
    var extraTbody = document.getElementById('iem-mc-extra-tbody');

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
        });
    }

    function setExtraMsg(kind, txt) {
        if (!extraMsg) return;
        var color = { ok:'#107010', err:'#a00', busy:'#a67c00' }[kind] || '#666';
        extraMsg.style.color   = color;
        extraMsg.textContent   = txt || '';
    }

    function renderExtraRow(L) {
        var status = String(L.status_at_count || '');
        var rowCls = '';
        if (status === 'OK')      rowCls = 'iem-row-ok';
        if (status === 'Revisar') rowCls = 'iem-row-revisar';
        var statusCell = status === 'OK'
            ? '<span style="color:#107010;font-weight:600;">OK</span>'
            : (status === 'Revisar'
                ? '<span style="color:#a00;font-weight:600;">Revisar</span>'
                : '<span style="color:#888;">—</span>');
        var qty = (L.counted_qty === null || typeof L.counted_qty === 'undefined') ? '' : parseInt(L.counted_qty, 10);

        var tr = document.createElement('tr');
        tr.className = 'iem-mc-extra-row ' + rowCls;
        tr.dataset.lineId = L.id;
        tr.innerHTML =
            '<td><code>' + escapeHtml(L.sku || '—') + '</code></td>' +
            '<td>' + escapeHtml(L.name) + '</td>' +
            '<td style="font-size:12px;color:#555;">' + escapeHtml(L.notes || '') + '</td>' +
            '<td><input type="number" min="0" step="1" class="iem-mc-input small-text" value="' + qty + '" style="width:90px;"></td>' +
            '<td class="iem-mc-status">' + statusCell + '</td>' +
            '<td class="iem-mc-save"><span style="color:#bbb;">—</span></td>' +
            '<td><button type="button" class="button button-small iem-mc-extra-del" title="Eliminar fila adicional">✕</button></td>';
        return tr;
    }

    if (extraBtn) {
        extraBtn.addEventListener('click', function () {
            var name  = document.getElementById('iem-mc-extra-name').value.trim();
            var sku   = document.getElementById('iem-mc-extra-sku').value.trim();
            var qty   = document.getElementById('iem-mc-extra-qty').value.trim();
            var notes = document.getElementById('iem-mc-extra-notes').value.trim();

            if (!name) { setExtraMsg('err', 'Falta el nombre del producto.'); return; }
            if (qty === '' || isNaN(parseInt(qty, 10)) || parseInt(qty, 10) < 0) {
                setExtraMsg('err', 'Cantidad inválida.'); return;
            }

            extraBtn.disabled = true;
            setExtraMsg('busy', 'Guardando…');

            var body = new URLSearchParams();
            body.append('action', 'iem_add_extra_line');
            body.append(IEM_AJAX.field, IEM_AJAX.nonce);
            body.append('session_id', IEM_AJAX.sid);
            body.append('name',  name);
            body.append('sku',   sku);
            body.append('qty',   qty);
            body.append('notes', notes);

            fetch(IEM_AJAX.url, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            })
            .then(function (r) { return r.json(); })
            .then(function (json) {
                extraBtn.disabled = false;
                if (json && json.success && json.data && json.data.line) {
                    var empty = document.getElementById('iem-mc-extra-empty');
                    if (empty) empty.remove();
                    extraTbody.appendChild(renderExtraRow(json.data.line));
                    document.getElementById('iem-mc-extra-name').value  = '';
                    document.getElementById('iem-mc-extra-sku').value   = '';
                    document.getElementById('iem-mc-extra-qty').value   = '1';
                    document.getElementById('iem-mc-extra-notes').value = '';
                    setExtraMsg('ok', '✓ Agregada.');
                    setTimeout(function () { setExtraMsg('', ''); }, 2500);
                } else {
                    var msg = (json && json.data && json.data.message) || 'Error desconocido.';
                    setExtraMsg('err', '✗ ' + msg);
                }
            })
            .catch(function () {
                extraBtn.disabled = false;
                setExtraMsg('err', '✗ Error de conexión.');
            });
        });
    }

    // Delegación para eliminar filas extra (también las recién renderizadas).
    document.addEventListener('click', function (e) {
        if (!e.target.classList.contains('iem-mc-extra-del')) return;
        var row = e.target.closest('tr');
        if (!row) return;
        if (!confirm('¿Eliminar esta fila adicional?')) return;
        e.target.disabled = true;

        var body = new URLSearchParams();
        body.append('action', 'iem_delete_extra_line');
        body.append(IEM_AJAX.field, IEM_AJAX.nonce);
        body.append('session_id', IEM_AJAX.sid);
        body.append('line_id', row.dataset.lineId);

        fetch(IEM_AJAX.url, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        })
        .then(function (r) { return r.json(); })
        .then(function (json) {
            if (json && json.success) {
                row.remove();
                if (extraTbody && !extraTbody.querySelector('tr')) {
                    var tr = document.createElement('tr');
                    tr.id = 'iem-mc-extra-empty';
                    tr.innerHTML = '<td colspan="7" style="color:#888;font-style:italic;text-align:center;padding:14px;">No hay filas adicionales todavía.</td>';
                    extraTbody.appendChild(tr);
                }
            } else {
                e.target.disabled = false;
                alert((json && json.data && json.data.message) || 'No se pudo eliminar.');
            }
        })
        .catch(function () {
            e.target.disabled = false;
            alert('Error de conexión.');
        });
    });

    // Cerrar sesión.
    window.iemMcCloseConfirm = function (btn) {
        if (!confirm('¿Finalizar el conteo? Una vez bloqueada, solo un administrador podrá reabrirla.')) {
            return false;
        }
        btn.disabled = true;
        var body = new URLSearchParams();
        body.append('action', 'iem_close_session');
        body.append(IEM_AJAX.field, IEM_AJAX.nonce);
        body.append('session_id', IEM_AJAX.sid);
        fetch(IEM_AJAX.url, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        })
        .then(function (r) { return r.json(); })
        .then(function (json) {
            if (json && json.success) {
                window.location.href = IEM_AJAX.page;
            } else {
                btn.disabled = false;
                alert((json && json.data && json.data.message) || 'No se pudo cerrar.');
            }
        })
        .catch(function () {
            btn.disabled = false;
            alert('Error de conexión.');
        });
        return false;
    };
})();
</script>
