<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Template del submenú "Inventario Ventova".
 * Variables disponibles (definidas en IEM_Admin::render):
 *
 * @var string $sucursal_filter           Slug de sucursal actualmente filtrado, o ''.
 * @var array  $sucursales                Mapa slug → nombre.
 * @var array  $rows                      Filas de IEM_Collector::collect().
 * @var string $nonce                     Nonce para los endpoints de descarga.
 * @var string $base_url                  admin-post.php.
 * @var string $url_descargar_unificado   URL para descargar CSV unificado.
 * @var string $url_descargar_sucursal    URL para descargar CSV de la sucursal filtrada.
 */
?>
<div class="wrap">
    <h1 class="wp-heading-inline">Inventario Ventova</h1>
    <a href="<?php echo esc_url($url_descargar_unificado); ?>" class="page-title-action">Descargar Inventario Unificado</a>
    <?php if ($sucursal_filter !== ''): ?>
        <a href="<?php echo esc_url($url_descargar_sucursal); ?>" class="page-title-action">
            Descargar — <?php echo esc_html($sucursales[$sucursal_filter] ?? $sucursal_filter); ?>
        </a>
    <?php endif; ?>
    <hr class="wp-header-end">

    <?php // Banner del conteo persistido (v3.0) ─────────────────────────── ?>
    <?php if ($persist_state === null): ?>
        <div class="notice notice-info" style="margin:14px 0;">
            <p><strong>Conteo mensual persistido:</strong>
                selecciona una sucursal para iniciar o continuar el conteo del mes.</p>
        </div>
    <?php else:
        $ps_period   = $persist_state['period'];
        $ps_sucursal = $sucursales[$persist_state['sucursal']] ?? $persist_state['sucursal'];
        $ps_session  = $persist_state['session'];
    ?>
        <?php if (!$ps_session): ?>
            <div class="notice notice-info" style="margin:14px 0;">
                <p>
                    <strong>Conteo de <?php echo esc_html($ps_period); ?> — <?php echo esc_html($ps_sucursal); ?>:</strong>
                    aún no se ha iniciado.
                    <a class="button button-primary" style="margin-left:8px;"
                       href="<?php echo esc_url($persist_state['start_url']); ?>">
                        Iniciar conteo persistido
                    </a>
                </p>
                <p style="color:#666;font-style:italic;margin:6px 0 0;">
                    Iniciar tomará una "foto" del inventario actual y abrirá la pantalla de conteo;
                    los valores se autoguardan a medida que tipeas.
                </p>
            </div>
        <?php elseif ($ps_session['status'] === 'draft'): ?>
            <div class="notice notice-warning" style="margin:14px 0;">
                <p>
                    <strong>Conteo activo:</strong> <?php echo esc_html($ps_period); ?> — <?php echo esc_html($ps_sucursal); ?>
                    (borrador, última edición <?php echo esc_html($ps_session['created_at']); ?>).
                    <a class="button button-primary" style="margin-left:8px;"
                       href="<?php echo esc_url($persist_state['detail_url']); ?>">
                        Continuar conteo
                    </a>
                </p>
            </div>
        <?php else: ?>
            <div class="notice notice-success" style="margin:14px 0;">
                <p>
                    <strong>Conteo CERRADO:</strong> <?php echo esc_html($ps_period); ?> — <?php echo esc_html($ps_sucursal); ?>
                    (cerrado <?php echo esc_html($ps_session['closed_at']); ?>).
                    <a class="button" style="margin-left:8px;"
                       href="<?php echo esc_url($persist_state['detail_url']); ?>">
                        Ver detalle
                    </a>
                </p>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <form method="get" action="" style="margin: 14px 0; display:flex; gap:14px; align-items:center; flex-wrap:wrap;">
        <input type="hidden" name="post_type" value="product">
        <input type="hidden" name="page" value="<?php echo esc_attr(IEM_Admin::PAGE_SLUG); ?>">

        <label style="font-weight:600;">
            Sucursal:
            <select name="sucursal" onchange="this.form.submit()" style="margin-left:6px;">
                <option value="">— Todas —</option>
                <?php foreach ($sucursales as $slug => $name): ?>
                    <option value="<?php echo esc_attr($slug); ?>" <?php selected($sucursal_filter, $slug); ?>>
                        <?php echo esc_html($name); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <input type="search" id="iem-search" placeholder="Buscar SKU o producto…" style="min-width:240px;">

        <span style="color:#555;">Total: <strong><?php echo count($rows); ?></strong></span>
    </form>

    <form method="post" action="<?php echo esc_url($base_url); ?>" id="iem-conteo-form">
        <input type="hidden" name="action" value="iem_descargar_conteo">
        <input type="hidden" name="<?php echo esc_attr(IEM_Admin::NONCE_FIELD); ?>" value="<?php echo esc_attr($nonce); ?>">
        <input type="hidden" name="sucursal" value="<?php echo esc_attr($sucursal_filter); ?>">

        <p>
            <button type="submit" class="button button-primary">Descargar CSV con Conteo Físico</button>
            <span style="color:#666;margin-left:10px;font-style:italic;">
                El conteo no se guarda. Al descargar el CSV se incluye lo ingresado y luego se descarta al recargar.
            </span>
        </p>

        <?php if (empty($rows)): ?>
            <p>No hay productos con stock disponible para los filtros seleccionados.</p>
        <?php else:
            // Set único de categorías presentes en las filas mostradas (ordenado).
            $iem_cats = [];
            foreach ($rows as $r) {
                if (empty($r['categories'])) continue;
                foreach ($r['categories'] as $c) {
                    $c = trim((string) $c);
                    if ($c !== '') $iem_cats[$c] = true;
                }
            }
            $iem_cats = array_keys($iem_cats);
            sort($iem_cats, SORT_NATURAL | SORT_FLAG_CASE);
        ?>

            <div class="iem-cat-filters">
                <span class="iem-cat-label">Categoría:</span>
                <button type="button" class="button iem-cat-btn iem-cat-active" data-cat="">Todas</button>
                <?php foreach ($iem_cats as $cat): ?>
                    <button type="button" class="button iem-cat-btn"
                            data-cat="<?php echo esc_attr(strtolower($cat)); ?>">
                        <?php echo esc_html($cat); ?>
                    </button>
                <?php endforeach; ?>
            </div>

            <table class="wp-list-table widefat fixed striped iem-table">
                <thead>
                    <tr>
                        <th style="width:14%">SKU</th>
                        <th style="width:28%">Producto</th>
                        <th style="width:8%">Stock</th>
                        <th style="width:14%">Sucursal</th>
                        <th style="width:12%">Conteo</th>
                        <th style="width:10%">Estado</th>
                        <th style="width:14%">Categoría</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r):
                    $row_cats_lower = array_map('strtolower', array_map('trim', $r['categories'] ?? []));
                ?>
                    <tr data-sku="<?php echo esc_attr(strtolower($r['sku'])); ?>"
                        data-name="<?php echo esc_attr(strtolower($r['name'])); ?>"
                        data-categories="<?php echo esc_attr('|' . implode('|', $row_cats_lower) . '|'); ?>">
                        <td><code><?php echo esc_html($r['sku']); ?></code></td>
                        <td><?php echo esc_html($r['name']); ?></td>
                        <td><?php echo (int) $r['stock']; ?></td>
                        <td><?php echo esc_html($r['sucursal_name']); ?></td>
                        <td>
                            <input type="number" min="0" step="1"
                                   class="iem-conteo small-text"
                                   name="conteo[<?php echo (int) $r['item_id']; ?>]"
                                   data-stock="<?php echo (int) $r['stock']; ?>"
                                   style="width:100px;">
                        </td>
                        <td class="iem-estado">—</td>
                        <td><?php echo esc_html($r['category']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </form>
</div>

<style>
    .iem-table .iem-estado              { font-weight:600; }
    .iem-table .iem-estado.iem-ok       { color:#107010; }
    .iem-table .iem-estado.iem-revisar  { color:#a00; }
    .iem-table tbody tr.iem-row-ok      { background:#f1faf1 !important; }
    .iem-table tbody tr.iem-row-revisar { background:#fdecec !important; }
    .iem-cat-filters                    { margin:14px 0; display:flex; gap:6px; align-items:center; flex-wrap:wrap; }
    .iem-cat-filters .iem-cat-label     { font-weight:600; margin-right:4px; }
    .iem-cat-filters .iem-cat-btn       { padding:2px 12px; }
    .iem-cat-filters .iem-cat-active    { background:#2271b1 !important; color:#fff !important; border-color:#135e96 !important; }
</style>
<script>
(function(){
    var activeCat = '';

    function applyFilters(){
        var search = document.getElementById('iem-search');
        var q = (search ? search.value : '').trim().toLowerCase();
        document.querySelectorAll('.iem-table tbody tr').forEach(function(tr){
            var sku  = tr.dataset.sku        || '';
            var name = tr.dataset.name       || '';
            var cats = tr.dataset.categories || '';
            var matchSearch = (q === '' || sku.indexOf(q) >= 0 || name.indexOf(q) >= 0);
            var matchCat    = (activeCat === '' || cats.indexOf('|' + activeCat + '|') >= 0);
            tr.style.display = (matchSearch && matchCat) ? '' : 'none';
        });
    }

    document.addEventListener('input', function(e){
        if (!e.target.classList.contains('iem-conteo')) return;
        var input = e.target;
        var stock = parseInt(input.dataset.stock, 10);
        var raw   = input.value.trim();
        var row   = input.closest('tr');
        var cell  = row.querySelector('.iem-estado');

        row.classList.remove('iem-row-ok','iem-row-revisar');
        cell.classList.remove('iem-ok','iem-revisar');

        if (raw === '') {
            cell.textContent = '—';
            return;
        }
        var n = parseInt(raw, 10);
        if (n === stock) {
            cell.textContent = 'OK';
            cell.classList.add('iem-ok');
            row.classList.add('iem-row-ok');
        } else {
            cell.textContent = 'Revisar';
            cell.classList.add('iem-revisar');
            row.classList.add('iem-row-revisar');
        }
    });

    document.addEventListener('click', function(e){
        var btn = e.target.closest('.iem-cat-btn');
        if (!btn) return;
        activeCat = btn.dataset.cat || '';
        document.querySelectorAll('.iem-cat-btn').forEach(function(b){
            b.classList.toggle('iem-cat-active', b === btn);
        });
        applyFilters();
    });

    var search = document.getElementById('iem-search');
    if (search) {
        search.addEventListener('input', applyFilters);
    }
})();
</script>
