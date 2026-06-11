<?php
if (!defined('ABSPATH')) {
    exit;
}
/**
 * Detalle / pantalla de captura de una sesión de conteo.
 *
 * @var array  $session     Fila de iem_count_sessions.
 * @var array  $lines       Filas de iem_count_lines.
 * @var array  $progress    ['total','contados','ok','revisar']
 * @var array  $sucursales  Mapa slug => nombre.
 * @var string $nonce       Nonce admin-post.
 * @var string $ajax_nonce  Nonce AJAX (IEM_Ajax::NONCE_ACTION).
 * @var array  $tipos       Mapa de tipos de merma.
 * @var string $base_url    admin-post.php.
 */
$is_draft   = $session['status'] === 'draft';
$suc_name   = $sucursales[$session['sucursal_slug']] ?? $session['sucursal_slug'];
$back_url   = IEM_Admin::tab_url('historico');

$export_url = add_query_arg([
    'action'                 => 'iem_export_session',
    'session_id'             => (int) $session['id'],
    IEM_Admin::NONCE_FIELD   => $nonce,
], $base_url);

$reopen_url = add_query_arg([
    'action'                 => 'iem_reopen_session',
    'session_id'             => (int) $session['id'],
    IEM_Admin::NONCE_FIELD   => $nonce,
], $base_url);
?>
<div class="wrap">
    <h1 class="wp-heading-inline">
        Conteo <?php echo esc_html($session['period']); ?> — <?php echo esc_html($suc_name); ?>
    </h1>
    <a href="<?php echo esc_url($back_url); ?>" class="page-title-action">← Volver al histórico</a>
    <a href="<?php echo esc_url($export_url); ?>" class="page-title-action">Descargar CSV</a>
    <?php if (!$is_draft): ?>
        <a href="<?php echo esc_url($reopen_url); ?>" class="page-title-action">Reabrir conteo</a>
    <?php endif; ?>
    <hr class="wp-header-end">

    <div class="iem-meta iem-meta-historico">
        <span><strong>Estado:</strong>
            <?php if ($is_draft): ?>
                <span style="color:#a67c00;font-weight:600;">Borrador (autoguardado)</span>
            <?php else: ?>
                <span style="color:#107010;font-weight:600;">Cerrado</span>
            <?php endif; ?>
        </span>
        <span><strong>Progreso:</strong>
            <?php echo (int) $progress['contados']; ?> / <?php echo (int) $progress['total']; ?> contados
            · OK: <span style="color:#107010;font-weight:600;"><?php echo (int) $progress['ok']; ?></span>
            · Revisar: <span style="color:#a00;font-weight:600;"><?php echo (int) $progress['revisar']; ?></span>
        </span>
        <span><strong>Creado:</strong> <?php echo esc_html($session['created_at']); ?></span>
        <?php if ($session['closed_at']): ?>
            <span><strong>Cerrado:</strong> <?php echo esc_html($session['closed_at']); ?></span>
        <?php endif; ?>
    </div>

    <?php if ($is_draft): ?>
        <p style="margin:14px 0;">
            <button type="button" class="button button-primary" id="iem-close-session"
                    data-session-id="<?php echo (int) $session['id']; ?>">
                Cerrar conteo
            </button>
            <span style="color:#666;margin-left:10px;font-style:italic;">
                Los conteos se guardan automáticamente. Cerrar el conteo bloquea la edición.
            </span>
        </p>
    <?php endif; ?>

    <input type="search" id="iem-search" placeholder="Buscar SKU o producto…"
           style="min-width:280px;margin:8px 0;">

    <?php
    // Prime de cachés de posts (ítem + padre) y helper de miniatura.
    // Prioridad: imagen de la variación (item_id) → imagen del padre.
    $iem_h_pids = [];
    foreach ($lines as $L) {
        foreach ([(int)($L['item_id'] ?? 0), (int)($L['parent_id'] ?? 0)] as $id) {
            if ($id > 0) $iem_h_pids[$id] = true;
        }
    }
    if ($iem_h_pids && function_exists('_prime_post_caches')) {
        _prime_post_caches(array_keys($iem_h_pids), false, true);
    }
    $iem_h_thumb = function ($L) {
        $tid = 0;
        $iid = (int) ($L['item_id']   ?? 0);
        $pid = (int) ($L['parent_id'] ?? 0);
        if ($iid > 0)                           $tid = (int) get_post_thumbnail_id($iid);
        if (!$tid && $pid > 0 && $pid !== $iid) $tid = (int) get_post_thumbnail_id($pid);
        if ($tid) {
            return wp_get_attachment_image($tid, [40, 40], false, [
                'class' => 'iem-thumb-img',
                'alt'   => '',
            ]);
        }
        return '<span class="iem-thumb-ph" aria-hidden="true">—</span>';
    };
    ?>

    <table class="wp-list-table widefat fixed striped iem-table">
        <thead>
            <tr>
                <th style="width:6%">Imagen</th>
                <th style="width:12%">SKU</th>
                <th style="width:22%">Producto</th>
                <th style="width:9%">Stock al inicio</th>
                <th style="width:13%">Categoría</th>
                <th style="width:11%">Notas</th>
                <th style="width:11%">Conteo</th>
                <th style="width:8%">Estado</th>
                <th style="width:7%">Merma</th>
                <th style="width:5%">Kardex</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($lines as $l):
            $status   = $l['status_at_count'] ?: 'Sin conteo';
            $row_cls  = $status === 'OK' ? 'iem-row-ok' : ($status === 'Revisar' ? 'iem-row-revisar' : '');
            $is_extra = !empty($l['is_extra']);
            if ($is_extra) $row_cls .= ' iem-row-extra';
        ?>
            <tr class="<?php echo esc_attr(trim($row_cls)); ?>"
                data-sku="<?php echo esc_attr(strtolower((string) $l['sku'])); ?>"
                data-name="<?php echo esc_attr(strtolower((string) $l['name'])); ?>">
                <td class="iem-thumb"><?php echo $iem_h_thumb($l); ?></td>
                <td>
                    <code><?php echo esc_html($l['sku'] ?: '—'); ?></code>
                    <?php if ($is_extra): ?>
                        <br><span class="iem-badge-extra">EXTRA</span>
                    <?php endif; ?>
                </td>
                <td><?php echo esc_html($l['name']); ?></td>
                <td><?php echo (int) $l['stock_at_count']; ?></td>
                <td><?php echo esc_html($l['category']); ?></td>
                <td style="font-size:12px;color:#555;"><?php echo esc_html($l['notes'] ?? ''); ?></td>
                <td>
                    <input type="number" min="0" step="1"
                           class="iem-conteo small-text"
                           data-session-id="<?php echo (int) $session['id']; ?>"
                           data-line-id="<?php echo (int) $l['id']; ?>"
                           data-stock="<?php echo (int) $l['stock_at_count']; ?>"
                           value="<?php echo $l['counted_qty'] !== null ? (int) $l['counted_qty'] : ''; ?>"
                           <?php disabled(!$is_draft); ?>
                           style="width:100px;">
                    <span class="iem-savestate" aria-live="polite"></span>
                </td>
                <td class="iem-estado iem-estado-<?php
                    echo esc_attr(strtolower(str_replace(' ', '-', $status))); ?>">
                    <?php echo esc_html($status); ?>
                </td>
                <td>
                    <?php if ($is_extra || (int) $l['item_id'] === 0): ?>
                        <span class="iem-help" style="font-size:12px;">N/A</span>
                    <?php else: ?>
                        <button type="button" class="button button-small iem-merma-btn"
                                data-item-id="<?php echo (int) $l['item_id']; ?>"
                                data-name="<?php echo esc_attr((string) $l['name']); ?>"
                                data-sucursal="<?php echo esc_attr((string) $l['sucursal_slug']); ?>"
                                data-session-id="<?php echo (int) $session['id']; ?>">
                            Merma
                        </button>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($is_extra || (int) $l['item_id'] === 0): ?>
                        <span class="iem-help" style="font-size:12px;">—</span>
                    <?php else:
                        $kx_url = IEM_Admin::tab_url('kardex', ['item_id' => (int) $l['item_id']]);
                    ?>
                        <a href="<?php echo esc_url($kx_url); ?>"
                           class="button button-small"
                           title="Ver kardex de este ítem">📊</a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php // ── Modal merma ─────────────────────────────────────────────────── ?>
<div id="iem-merma-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:9999;align-items:center;justify-content:center;">
    <div style="background:#fff;padding:20px;border-radius:6px;min-width:420px;max-width:90vw;">
        <h2 style="margin-top:0;">Registrar merma</h2>
        <p><strong>Producto:</strong> <span id="iem-m-name"></span></p>
        <p>
            <label>Cantidad:
                <input type="number" id="iem-m-qty" min="1" step="1" value="1" style="width:100px;">
            </label>
        </p>
        <p>
            <label>Tipo:
                <select id="iem-m-tipo">
                    <?php foreach ($tipos as $slug => $name): ?>
                        <option value="<?php echo esc_attr($slug); ?>"><?php echo esc_html($name); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </p>
        <p>
            <label>
                <input type="checkbox" id="iem-m-decrement">
                Descontar del stock de WooCommerce
            </label>
        </p>
        <p>
            <label>Nota / defecto encontrado:
                <textarea id="iem-m-notes" rows="3" style="width:100%;"
                          placeholder="Ej.: rayón en la tapa, costura abierta…"></textarea>
            </label>
        </p>
        <p id="iem-m-err" style="color:#a00;display:none;"></p>
        <p style="text-align:right;">
            <button type="button" class="button" id="iem-m-cancel">Cancelar</button>
            <button type="button" class="button button-primary" id="iem-m-save">Guardar merma</button>
        </p>
    </div>
</div>

<style>
/* Locales: meta con border-left e indicador "estado" textual. El resto de
 * utilidades (.iem-row-*, .iem-savestate, .iem-thumb-*, .iem-badge-extra)
 * vienen de assets/css/admin.css (v3.25+). */
.iem-meta-historico { border-left: 4px solid #2271b1; }
.iem-table .iem-estado            { font-weight: 600; }
.iem-table .iem-estado-ok         { color: #107010; }
.iem-table .iem-estado-revisar    { color: #a00; }
.iem-table .iem-estado-sin-conteo { color: #888; }
.iem-savestate { margin-left: 6px; min-width: 60px; }
</style>

<script>
(function(){
    window.IEM_AJAX = {
        url:   <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>,
        nonce: <?php echo wp_json_encode($ajax_nonce); ?>,
        field: <?php echo wp_json_encode(IEM_Ajax::NONCE_FIELD); ?>
    };

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

    // ── Búsqueda en cliente ─────────────────────────────────────────────
    var search = document.getElementById('iem-search');
    if (search) {
        search.addEventListener('input', function(){
            var q = search.value.trim().toLowerCase();
            document.querySelectorAll('.iem-table tbody tr').forEach(function(tr){
                var hit = q === '' || (tr.dataset.sku||'').indexOf(q) >= 0
                       || (tr.dataset.name||'').indexOf(q) >= 0;
                tr.style.display = hit ? '' : 'none';
            });
        });
    }

    // ── Autosave del conteo ─────────────────────────────────────────────
    var debouncers = new WeakMap();
    document.addEventListener('input', function(e){
        if (!e.target.classList.contains('iem-conteo')) return;
        var input = e.target;
        if (input.disabled) return;
        clearTimeout(debouncers.get(input));
        var state = input.parentNode.querySelector('.iem-savestate');
        if (state) { state.className = 'iem-savestate saving'; state.textContent = 'guardando…'; }
        debouncers.set(input, setTimeout(function(){
            post('iem_save_line', {
                session_id: input.dataset.sessionId,
                line_id:    input.dataset.lineId,
                qty:        input.value
            }, function(err, res){
                var row = input.closest('tr');
                var estCell = row.querySelector('.iem-estado');
                row.classList.remove('iem-row-ok','iem-row-revisar');
                estCell.classList.remove('iem-estado-ok','iem-estado-revisar','iem-estado-sin-conteo');
                if (err || !res.ok || !res.j.success) {
                    if (state) { state.className = 'iem-savestate error'; state.textContent = '✗ error'; }
                    return;
                }
                var status = res.j.data.status;
                estCell.textContent = status;
                if (status === 'OK')      { row.classList.add('iem-row-ok');      estCell.classList.add('iem-estado-ok'); }
                if (status === 'Revisar') { row.classList.add('iem-row-revisar'); estCell.classList.add('iem-estado-revisar'); }
                if (status === 'Sin conteo') { estCell.classList.add('iem-estado-sin-conteo'); }
                if (state) { state.className = 'iem-savestate saved'; state.textContent = '✓ guardado'; }
            });
        }, 400));
    });

    // ── Cerrar sesión ───────────────────────────────────────────────────
    var closeBtn = document.getElementById('iem-close-session');
    if (closeBtn) {
        closeBtn.addEventListener('click', function(){
            if (!confirm('¿Cerrar el conteo? Bloqueará la edición. Podrás reabrirlo más tarde si hace falta.')) return;
            post('iem_close_session', { session_id: closeBtn.dataset.sessionId }, function(err, res){
                if (err || !res.ok || !res.j.success) {
                    alert('No se pudo cerrar: ' + (res && res.j && res.j.data && res.j.data.message || 'error'));
                    return;
                }
                location.reload();
            });
        });
    }

    // ── Modal merma ─────────────────────────────────────────────────────
    var modal = document.getElementById('iem-merma-modal');
    var current = null;
    document.addEventListener('click', function(e){
        var btn = e.target.closest('.iem-merma-btn');
        if (btn) {
            current = {
                item_id:       btn.dataset.itemId,
                sucursal_slug: btn.dataset.sucursal,
                session_id:    btn.dataset.sessionId
            };
            document.getElementById('iem-m-name').textContent = btn.dataset.name;
            document.getElementById('iem-m-qty').value = 1;
            document.getElementById('iem-m-decrement').checked = false;
            document.getElementById('iem-m-notes').value = '';
            document.getElementById('iem-m-err').style.display = 'none';
            modal.style.display = 'flex';
            return;
        }
        if (e.target.id === 'iem-m-cancel') {
            modal.style.display = 'none';
            return;
        }
        if (e.target.id === 'iem-m-save' && current) {
            var err = document.getElementById('iem-m-err');
            err.style.display = 'none';
            post('iem_register_merma', {
                item_id:       current.item_id,
                sucursal_slug: current.sucursal_slug,
                session_id:    current.session_id,
                qty:           document.getElementById('iem-m-qty').value,
                tipo:          document.getElementById('iem-m-tipo').value,
                decrement_wc:  document.getElementById('iem-m-decrement').checked ? 1 : 0,
                notes:         document.getElementById('iem-m-notes').value
            }, function(e2, res){
                if (e2 || !res.ok || !res.j.success) {
                    err.textContent = (res && res.j && res.j.data && res.j.data.message) || 'Error guardando merma.';
                    err.style.display = 'block';
                    return;
                }
                modal.style.display = 'none';
                current = null;
            });
        }
    });
})();
</script>
