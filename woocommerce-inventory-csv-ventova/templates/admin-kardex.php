<?php
if (!defined('ABSPATH')) {
    exit;
}
/**
 * Pantalla principal del Kardex (3.24+):
 *  - Listado **carga directo** al entrar (sin guard search-first). El server
 *    aplica solo `sucursal_slug`; el resto de filtros es client-side.
 *  - **Búsqueda live**: input que filtra al tipear sobre nombre + SKU +
 *    nombre del padre. No requiere botón Filtrar (3.24).
 *  - **Sucursal con auto-submit**: cambiar el select recarga la página con
 *    el filtro EXISTS aplicado (los chips también se recalculan al ítem-set).
 *  - **Filtro por categoría**: chips client-side estilo "Mi Conteo", encima
 *    de la tabla. Único acceso (sin select de categoría desde 3.23).
 *  - Layout: el listado va ancho completo arriba; el form de movimiento
 *    manual va DEBAJO, también ancho completo (3.23) — las columnas del
 *    listado (nombre + categorías) son demasiado largas para una sidebar.
 *
 * @var array  $balances             Filas (cada una con `name`, `parent_id`).
 * @var array  $sucursales           slug => name.
 * @var array  $filter               ['sucursal_slug','search','category_id'].
 * @var array  $categories_by_item   item_id => string[] de categorías.
 * @var array  $all_categories       term_id => name (catálogo completo de product_cat).
 * @var string $nonce
 * @var string $ajax_nonce
 * @var string $flash_msg
 * @var string $flash_err
 */
$base_url = admin_url('admin-post.php');
$manual_url = add_query_arg([
    'action'               => 'iem_manual_movement',
    IEM_Admin::NONCE_FIELD => $nonce,
], $base_url);
$list_url = IEM_Admin::tab_url('kardex');

// Map item_id => stock WC para detectar drift. Un solo query batch.
$item_ids = array_map(function($b){ return (int) $b['item_id']; }, $balances);
$wc_stock_map = [];
if ($item_ids) {
    global $wpdb;
    $ids_sql = implode(',', array_map('intval', $item_ids));
    $rows = $wpdb->get_results(
        "SELECT post_id, meta_value FROM {$wpdb->postmeta}
         WHERE post_id IN ($ids_sql) AND meta_key = '_stock'", ARRAY_A
    );
    foreach ($rows as $r) {
        $wc_stock_map[(int) $r['post_id']] = (int) $r['meta_value'];
    }
}

// Map (producto de origen) => costo_de_origen ("Valor Exw USD"). El meta vive en
// el producto PADRE (o en el item si es simple), igual que la columna del listado
// de productos. Un solo query batch sobre los ids relevantes.
$origin_pids = [];
foreach ($balances as $b) {
    $pid = (int) ($b['parent_id'] ?? 0);
    $origin_pids[$pid > 0 ? $pid : (int) $b['item_id']] = true;
}
$origin_cost_by_pid = [];
if ($origin_pids) {
    global $wpdb;
    $opids_sql = implode(',', array_map('intval', array_keys($origin_pids)));
    $orows = $wpdb->get_results(
        "SELECT post_id, meta_value FROM {$wpdb->postmeta}
         WHERE post_id IN ($opids_sql) AND meta_key = 'costo_de_origen'", ARRAY_A
    );
    foreach ($orows as $r) {
        $origin_cost_by_pid[(int) $r['post_id']] = $r['meta_value'];
    }
}
$types_map = IEM_Kardex::get_types();

// Set único de categorías presentes en el result-set (para las chips).
$visible_cats = [];
foreach ($categories_by_item as $cats) {
    foreach ($cats as $c) {
        $c = trim($c);
        if ($c !== '') $visible_cats[$c] = true;
    }
}
$visible_cats = array_keys($visible_cats);
sort($visible_cats, SORT_NATURAL | SORT_FLAG_CASE);

$has_server_filter = ($filter['sucursal_slug'] !== '');
?>
<div class="wrap">
    <h1 class="wp-heading-inline">Kardex de Inventario</h1>
    <hr class="wp-header-end">

    <?php if ($flash_msg === 'manual_ok'): ?>
        <div class="notice notice-success is-dismissible"><p>Movimiento manual registrado.</p></div>
    <?php endif; ?>
    <?php if ($flash_err !== ''): ?>
        <div class="notice notice-error is-dismissible"><p><?php echo esc_html($flash_err); ?></p></div>
    <?php endif; ?>

    <?php // ── Listado de saldos (ancho completo, arriba) ─────────────── ?>
    <?php // Sucursal: server-side (auto-submit). Búsqueda: client-side instantáneo. ?>
    <form method="get" id="iem-kx-server-form" class="iem-filter-bar">
        <input type="hidden" name="post_type" value="product">
        <input type="hidden" name="page" value="<?php echo esc_attr(IEM_Admin::PAGE_SLUG); ?>">
        <input type="hidden" name="tab" value="kardex">
        <input type="search" id="iem-kx-search"
               placeholder="Filtrar por nombre o SKU…"
               style="min-width:260px;flex:1 1 260px;">
        <select name="sucursal_slug" id="iem-kx-sucursal" onchange="document.getElementById('iem-kx-server-form').submit();">
            <option value="">— Todas las sucursales —</option>
            <?php foreach ($sucursales as $slug => $name): ?>
                <option value="<?php echo esc_attr($slug); ?>"
                    <?php selected($filter['sucursal_slug'], $slug); ?>>
                    <?php echo esc_html($name); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php if ($has_server_filter): ?>
            <a href="<?php echo esc_url($list_url); ?>" class="button">Limpiar sucursal</a>
        <?php endif; ?>
        <?php
        $kx_export_args = ['action' => 'iem_export_kardex', IEM_Admin::NONCE_FIELD => $nonce];
        if ($filter['sucursal_slug'] !== '') $kx_export_args['sucursal_slug'] = $filter['sucursal_slug'];
        $kx_export_url = add_query_arg($kx_export_args, $base_url);
        ?>
        <a href="<?php echo esc_url($kx_export_url); ?>"
           class="button" style="margin-left:auto;">⬇ Exportar CSV</a>
        <span id="iem-kx-count" class="iem-help"></span>
    </form>

    <?php if (!empty($visible_cats)): ?>
        <div class="iem-chips">
            <span class="iem-chips__label">Categoría:</span>
            <button type="button" class="button iem-chip is-active" data-cat="">Todas</button>
            <?php foreach ($visible_cats as $cat): ?>
                <button type="button" class="button iem-chip"
                        data-cat="<?php echo esc_attr(strtolower($cat)); ?>">
                    <?php echo esc_html($cat); ?>
                </button>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <table class="widefat fixed striped" id="iem-kx-table">
        <thead>
            <tr>
                <th style="width:80px;">Item ID</th>
                <th style="width:130px;">SKU</th>
                <th>Nombre de Producto</th>
                <th style="width:90px;text-align:right;">Saldo kardex</th>
                <th style="width:90px;text-align:right;">Stock WC</th>
                <th style="width:110px;text-align:right;">Costo origen (USD)</th>
                <th style="width:130px;">Último mov.</th>
                <th style="width:140px;">Tipo</th>
                <th style="width:100px;">Acciones</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($balances)): ?>
            <tr id="iem-kx-empty"><td colspan="9">
                <em>No hay movimientos registrados<?php
                    echo $has_server_filter ? ' para esta sucursal.' : ' todavía.'; ?></em>
            </td></tr>
        <?php else: foreach ($balances as $b):
            $item_id   = (int) $b['item_id'];
            $kardex_b  = (int) $b['balance_after'];
            $wc_stock  = isset($wc_stock_map[$item_id]) ? (int) $wc_stock_map[$item_id] : null;
            $drift     = ($wc_stock !== null) && ($wc_stock !== $kardex_b);
            $type_lbl  = $types_map[(string) $b['type']] ?? $b['type'];
            $name      = trim((string) ($b['name']        ?? ''));
            $parent_nm = trim((string) ($b['parent_name'] ?? ''));
            $sku_val   = trim((string) ($b['sku']         ?? ''));
            // Para variaciones: mostrar "Padre — Variación".
            $display = $name !== '' ? $name : '(sin nombre)';
            if ($parent_nm !== '' && $parent_nm !== $name) {
                $display = $parent_nm . ' — ' . $name;
            }
            $cats = $categories_by_item[$item_id] ?? [];
            $cats_attr = '|' . implode('|', array_map(function($c){
                return strtolower(trim($c));
            }, $cats)) . '|';
            // Haystack para la búsqueda live (nombre + sku + parent).
            $haystack = strtolower(trim($display . ' ' . $sku_val));

            $movs_url  = IEM_Admin::tab_url('kardex', ['item_id' => $item_id]);
        ?>
            <tr data-categories="<?php echo esc_attr($cats_attr); ?>"
                data-haystack="<?php echo esc_attr($haystack); ?>"<?php
                echo $drift ? ' style="background:#fdecec !important;"' : ''; ?>>
                <td><?php echo (int) $item_id; ?></td>
                <td class="iem-mono" style="font-size:12px;">
                    <?php echo $sku_val !== '' ? esc_html($sku_val) : '<span style="color:#bbb;">—</span>'; ?>
                </td>
                <td>
                    <?php echo esc_html($display); ?>
                    <?php if (!empty($cats)): ?>
                        <br><small style="color:#666;"><?php
                            echo esc_html(implode(' · ', $cats));
                        ?></small>
                    <?php endif; ?>
                </td>
                <td class="iem-num-b">
                    <?php echo (int) $kardex_b; ?>
                </td>
                <td class="iem-num" style="<?php echo $drift ? 'color:#a00;' : ''; ?>">
                    <?php echo $wc_stock === null ? '—' : (int) $wc_stock; ?>
                </td>
                <td class="iem-num" style="text-align:right;">
                    <?php
                    $opid = (int) ($b['parent_id'] ?? 0);
                    $opid = $opid > 0 ? $opid : $item_id;
                    $ocost = isset($origin_cost_by_pid[$opid]) ? (float) $origin_cost_by_pid[$opid] : 0;
                    echo $ocost > 0
                        ? esc_html(number_format($ocost, 2, '.', ','))
                        : '<span style="color:#bbb;">—</span>';
                    ?>
                </td>
                <td><?php echo esc_html(substr($b['movement_date'], 0, 16)); ?></td>
                <td><?php echo esc_html($type_lbl); ?></td>
                <td>
                    <a href="<?php echo esc_url($movs_url); ?>" class="button button-small">Ver movs.</a>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        <tr id="iem-kx-no-match" style="display:none;">
            <td colspan="9"><em>Sin resultados para los filtros actuales.</em></td>
        </tr>
        </tbody>
    </table>
    <p class="iem-help" style="margin-top:8px;">
        Filas en rojo: el saldo del kardex no coincide con el stock actual de WooCommerce.
    </p>

    <?php // ── Movimiento manual (ancho completo, debajo del listado) ─── ?>
    <div class="iem-card" style="margin-top:28px;max-width:980px;">
        <h2 style="margin:0 0 12px;">Movimiento manual</h2>
        <form method="post" action="<?php echo esc_url($manual_url); ?>" id="iem-kx-form">
            <div style="display:grid;grid-template-columns:2fr 1fr 1fr;gap:14px;align-items:start;">
                <div>
                    <label style="display:block;font-weight:600;margin-bottom:4px;">SKU *</label>
                    <input type="text" name="sku" id="iem-kx-sku" required autocomplete="off"
                           class="iem-mono" style="width:100%;">
                    <div id="iem-kx-sku-feedback"
                         style="margin-top:6px;padding:6px 10px;border-radius:3px;
                                font-size:12px;background:#f6f7f7;color:#666;border:1px solid #dcdcde;">
                        Ingresa un SKU para validarlo.
                    </div>
                </div>
                <div>
                    <label style="display:block;font-weight:600;margin-bottom:4px;">Dirección *</label>
                    <select name="direction" required style="width:100%;">
                        <option value="I">Entrada (+) — suma stock</option>
                        <option value="O">Salida (-) — resta stock</option>
                    </select>
                </div>
                <div>
                    <label style="display:block;font-weight:600;margin-bottom:4px;">Cantidad *</label>
                    <input type="number" name="qty" min="1" step="1" value="1" required style="width:100%;">
                </div>
            </div>
            <p style="margin:14px 0 0;">
                <label style="display:block;font-weight:600;margin-bottom:4px;">Motivo / nota *</label>
                <textarea name="notes" rows="2" required style="width:100%;"
                          placeholder="Ej: ajuste por traspaso a sucursal X, devolución de cliente, corrección de conteo"></textarea>
            </p>
            <p style="margin-top:14px;">
                <button type="submit" class="button button-primary">Registrar movimiento</button>
                <span class="iem-help" style="margin-left:10px;">
                    Cambia el stock de WooCommerce y crea una fila en el kardex con tu usuario y la nota.
                </span>
            </p>
        </form>
    </div>
</div>

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

    // ── Resolver SKU del form manual ────────────────────────────────────
    var sku = document.getElementById('iem-kx-sku');
    var fb  = document.getElementById('iem-kx-sku-feedback');
    var deb;
    sku.addEventListener('input', function(){
        clearTimeout(deb);
        var v = sku.value.trim();
        if (v === '') { fb.textContent = 'Ingresa un SKU para validarlo.'; fb.style.color = '#666'; return; }
        fb.style.color = '#666'; fb.textContent = 'Buscando…';
        deb = setTimeout(function(){
            post('iem_resolve_sku', { sku: v }, function(err, res){
                if (err || !res.ok || !res.j.success) {
                    fb.style.color = '#a00';
                    fb.textContent = '✗ ' + ((res && res.j && res.j.data && res.j.data.message) || 'Error.');
                    return;
                }
                var d = res.j.data;
                fb.style.color = '#107010';
                fb.textContent = '✓ ' + d.name + (d.current_stock !== null
                    ? ' (stock actual: ' + d.current_stock + ')' : '');
            });
        }, 350);
    });

    // ── Filtros client-side combinados: texto + chip de categoría ───────
    (function(){
        var table   = document.getElementById('iem-kx-table');
        var search  = document.getElementById('iem-kx-search');
        var counter = document.getElementById('iem-kx-count');
        var noMatch = document.getElementById('iem-kx-no-match');
        var empty   = document.getElementById('iem-kx-empty');
        if (!table) return;

        var activeCat = '';

        function applyFilters(){
            var q = (search && search.value ? search.value : '').trim().toLowerCase();
            var rows = table.querySelectorAll('tbody tr[data-haystack]');
            var total = rows.length, shown = 0;

            rows.forEach(function(tr){
                var hay  = tr.dataset.haystack  || '';
                var cats = tr.dataset.categories || '';
                var matchText = (q === '' || hay.indexOf(q) >= 0);
                var matchCat  = (activeCat === '' || cats.indexOf('|' + activeCat + '|') >= 0);
                var visible   = matchText && matchCat;
                tr.style.display = visible ? '' : 'none';
                if (visible) shown++;
            });

            if (counter) {
                if (total === 0) {
                    counter.textContent = '';
                } else if (shown === total) {
                    counter.textContent = 'Mostrando ' + total + ' ítems.';
                } else {
                    counter.textContent = 'Mostrando ' + shown + ' de ' + total + ' ítems.';
                }
            }
            if (noMatch) {
                noMatch.style.display = (total > 0 && shown === 0) ? '' : 'none';
            }
        }

        if (search) {
            search.addEventListener('input', applyFilters);
            // Evitar que Enter dispare submit del form (Sucursal).
            search.addEventListener('keydown', function(e){
                if (e.key === 'Enter') { e.preventDefault(); }
            });
        }

        document.addEventListener('click', function(e){
            var btn = e.target.closest('.iem-chip');
            if (!btn) return;
            activeCat = btn.dataset.cat || '';
            document.querySelectorAll('.iem-chip').forEach(function(b){
                b.classList.toggle('is-active', b === btn);
            });
            applyFilters();
        });

        applyFilters();
    })();
})();
</script>
