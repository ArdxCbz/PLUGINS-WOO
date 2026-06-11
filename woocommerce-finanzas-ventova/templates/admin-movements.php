<?php
if (!defined('ABSPATH')) {
    exit;
}
/**
 * Pestaña "Registro de Movimientos": forms (ingreso/egreso + transferencia) +
 * listado filtrable con saldo corrido por cuenta.
 *
 * @var array  $movements      Filas de FIN_Movements::query().
 * @var array  $accounts       Cuentas activas (para selects).
 * @var array  $accounts_map   [id => name] todas las cuentas.
 * @var array  $categories_map [id => name] todas las categorías.
 * @var array  $cats_ingreso   Categorías activas nature=ingreso.
 * @var array  $cats_egreso    Categorías activas nature=egreso.
 * @var array  $currencies     Catálogo de monedas [code => ['symbol','name','rate']].
 * @var array  $filter         Filtros actuales.
 * @var string $nonce
 * @var string $base_url       admin-post.php
 * @var string $flash_msg
 * @var string $flash_err
 */
$list_url = FIN_Admin::tab_url('movimientos');

$mov_url = add_query_arg([
    'action'              => 'fin_save_movement',
    FIN_Admin::NONCE_FIELD => $nonce,
], $base_url);

$transfer_url = add_query_arg([
    'action'              => 'fin_transfer',
    FIN_Admin::NONCE_FIELD => $nonce,
], $base_url);

$today = wp_date('Y-m-d');

$flash_labels = [
    'mov_ok'         => 'Movimiento registrado.',
    'transfer_ok'    => 'Transferencia registrada.',
    'reversed'       => 'Movimiento anulado.',
];
?>
<div class="wrap">
    <h1 class="wp-heading-inline">Registro de Movimientos</h1>
    <hr class="wp-header-end">

    <?php if ($flash_msg !== '' && isset($flash_labels[$flash_msg])): ?>
        <div class="notice notice-success is-dismissible"><p><?php echo esc_html($flash_labels[$flash_msg]); ?></p></div>
    <?php endif; ?>
    <?php if ($flash_err !== ''): ?>
        <div class="notice notice-error is-dismissible"><p><?php echo esc_html($flash_err); ?></p></div>
    <?php endif; ?>

    <?php if (empty($accounts)): ?>
        <div class="notice notice-warning"><p>
            No hay cuentas activas. Primero crea una en
            <a href="<?php echo esc_url(FIN_Admin::tab_url('cuentas')); ?>">Cuentas</a>.
        </p></div>
    <?php else: ?>
        <?php // ── Saldos por cuenta + totales por moneda ─────────────────── ?>
        <div class="fin-cash-badges">
            <?php $fin_totals_by_cur = []; ?>
            <?php foreach ($accounts as $a):
                $bal = (float) $a['balance'];
                $cur = (string) $a['currency'];
                $fin_totals_by_cur[$cur] = ($fin_totals_by_cur[$cur] ?? 0.0) + $bal;
                $neg = $bal < 0;
            ?>
                <div class="fin-cash-badge<?php echo $neg ? ' is-neg' : ''; ?>">
                    <span class="fin-cash-badge__name"><?php echo esc_html($a['name']); ?></span>
                    <span class="fin-cash-badge__type"><?php echo esc_html(FIN_Accounts::type_label($a['type']) . ' · ' . $cur); ?></span>
                    <span class="fin-cash-badge__bal"><?php echo esc_html(fin_money($bal, $cur)); ?></span>
                </div>
            <?php endforeach; ?>
            <?php foreach ($fin_totals_by_cur as $cur => $sum): ?>
                <div class="fin-cash-badge is-total<?php echo $sum < 0 ? ' is-neg' : ''; ?>">
                    <span class="fin-cash-badge__name">TOTAL <?php echo esc_html($cur); ?></span>
                    <span class="fin-cash-badge__type"><?php echo esc_html(FIN_Currencies::name($cur)); ?></span>
                    <span class="fin-cash-badge__bal"><?php echo esc_html(fin_money($sum, $cur)); ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="fin-mov-forms">

        <?php // ── Form Ingreso / Egreso ──────────────────────────────── ?>
        <div class="fin-card fin-mov-form">
            <h2 style="margin-top:0;">Nuevo ingreso / egreso</h2>
            <form method="post" action="<?php echo esc_url($mov_url); ?>">
                <p>
                    <label><strong>Tipo *</strong>
                        <select name="type" id="fin-mov-type" required style="width:100%;">
                            <option value="ingreso">Ingreso</option>
                            <option value="egreso">Egreso</option>
                        </select>
                    </label>
                </p>
                <p>
                    <label><strong>Cuenta *</strong>
                        <select name="account_id" id="fin-mov-account" required style="width:100%;"
                                data-motivos="<?php echo esc_attr(wp_json_encode((object) array_map('array_values', $account_motivos))); ?>"
                                data-base="<?php echo esc_attr(FIN_Currencies::BASE_CODE); ?>">
                            <?php foreach ($accounts as $a):
                                $acur = (string) $a['currency'];
                            ?>
                                <option value="<?php echo (int) $a['id']; ?>"
                                        data-currency="<?php echo esc_attr($acur); ?>"
                                        data-symbol="<?php echo esc_attr(FIN_Currencies::symbol($acur)); ?>"
                                        data-rate="<?php echo esc_attr(number_format(FIN_Currencies::rate($acur), 6, '.', '')); ?>">
                                    <?php echo esc_html($a['name'] . ' — ' . fin_money($a['balance'], $acur)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </p>
                <p>
                    <label><strong>Motivo *</strong>
                        <select name="category_id" id="fin-mov-cat" required style="width:100%;">
                            <option value="" class="fin-cat-empty" disabled hidden>— Sin motivos permitidos para esta cuenta —</option>
                            <optgroup label="Ingreso" class="fin-cat-ingreso">
                                <?php foreach ($cats_ingreso as $c): ?>
                                    <option value="<?php echo (int) $c['id']; ?>" data-nature="ingreso"
                                            data-reqdesc="<?php echo !empty($c['requires_description']) ? 1 : 0; ?>">
                                        <?php echo esc_html($c['name'] . ' — ' . FIN_Groups::label($c['group_key'] ?? '')); ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                            <optgroup label="Egreso" class="fin-cat-egreso">
                                <?php foreach ($cats_egreso as $c): ?>
                                    <option value="<?php echo (int) $c['id']; ?>" data-nature="egreso"
                                            data-reqdesc="<?php echo !empty($c['requires_description']) ? 1 : 0; ?>">
                                        <?php echo esc_html($c['name'] . ' — ' . FIN_Groups::label($c['group_key'] ?? '')); ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        </select>
                    </label>
                    <span class="fin-help fin-help-block">Los motivos se filtran según el tipo. El motivo define el grupo contable.</span>
                </p>
                <p>
                    <label><strong>Monto (<span id="fin-mov-amount-cur">Bs</span>) *</strong>
                        <input type="number" name="amount" min="0.01" step="0.01" required style="width:100%;">
                    </label>
                </p>
                <p id="fin-mov-rate-wrap" style="display:none;">
                    <label><strong>Tipo de cambio (Bs por <span id="fin-mov-rate-cur">$</span>) *</strong>
                        <input type="number" name="rate" id="fin-mov-rate" min="0.000001" step="0.000001" style="width:100%;">
                    </label>
                    <span class="fin-help fin-help-block">Esta cuenta es en otra moneda: indica el tipo de cambio del movimiento (se guarda como referencia).</span>
                </p>
                <p>
                    <label>Fecha
                        <input type="date" name="movement_date" value="<?php echo esc_attr($today); ?>" style="width:100%;">
                    </label>
                </p>
                <p>
                    <label><span id="fin-mov-desc-label">Descripción</span>
                        <textarea name="description" id="fin-mov-desc" rows="2" style="width:100%;"></textarea>
                    </label>
                    <span class="fin-help fin-help-block" id="fin-mov-desc-hint" style="display:none;">
                        Este motivo ("Otros") requiere una descripción.
                    </span>
                </p>
                <p style="margin-top:14px;">
                    <button type="submit" id="fin-mov-submit" class="button button-primary" <?php disabled(empty($accounts)); ?>>
                        Registrar movimiento
                    </button>
                    <span class="fin-help fin-help-block" id="fin-mov-nomot" style="display:none;color:#a00;">
                        Esta cuenta no tiene motivos permitidos. Asígnalos en
                        <a href="<?php echo esc_url(FIN_Admin::tab_url('config')); ?>">Configuración → Motivos permitidos por cuenta</a>.
                    </span>
                </p>
            </form>
        </div>

        <?php // ── Form Transferencia ─────────────────────────────────── ?>
        <div class="fin-card fin-mov-form">
            <h2 style="margin-top:0;">Transferencia entre cuentas</h2>
            <form method="post" action="<?php echo esc_url($transfer_url); ?>" id="fin-tr-form"
                  data-base="<?php echo esc_attr(FIN_Currencies::BASE_CODE); ?>">
                <p>
                    <label><strong>Desde *</strong>
                        <select name="from_account_id" id="fin-tr-from" required style="width:100%;">
                            <?php foreach ($accounts as $a): $acur = (string) $a['currency']; ?>
                                <option value="<?php echo (int) $a['id']; ?>"
                                        data-currency="<?php echo esc_attr($acur); ?>"
                                        data-symbol="<?php echo esc_attr(FIN_Currencies::symbol($acur)); ?>"
                                        data-rate="<?php echo esc_attr(number_format(FIN_Currencies::rate($acur), 6, '.', '')); ?>">
                                    <?php echo esc_html($a['name'] . ' — ' . fin_money($a['balance'], $acur)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </p>
                <p>
                    <label><strong>Hacia *</strong>
                        <select name="to_account_id" id="fin-tr-to" required style="width:100%;">
                            <?php foreach ($accounts as $a): $acur = (string) $a['currency']; ?>
                                <option value="<?php echo (int) $a['id']; ?>"
                                        data-currency="<?php echo esc_attr($acur); ?>"
                                        data-symbol="<?php echo esc_attr(FIN_Currencies::symbol($acur)); ?>"
                                        data-rate="<?php echo esc_attr(number_format(FIN_Currencies::rate($acur), 6, '.', '')); ?>">
                                    <?php echo esc_html($a['name'] . ' (' . $acur . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </p>
                <p>
                    <label><strong>Monto (<span id="fin-tr-amount-cur">Bs</span>) *</strong>
                        <input type="number" name="amount" id="fin-tr-amount" min="0.01" step="0.01" required style="width:100%;">
                    </label>
                </p>
                <p id="fin-tr-rate-wrap" style="display:none;">
                    <label><strong>Tipo de cambio (Bs por <span id="fin-tr-rate-cur">$</span>) *</strong>
                        <input type="number" name="rate" id="fin-tr-rate" min="0.000001" step="0.000001" style="width:100%;">
                    </label>
                    <span class="fin-help fin-help-block" id="fin-tr-preview"></span>
                </p>
                <p>
                    <label>Fecha
                        <input type="date" name="movement_date" value="<?php echo esc_attr($today); ?>" style="width:100%;">
                    </label>
                </p>
                <p>
                    <label>Descripción
                        <textarea name="description" rows="2" style="width:100%;"></textarea>
                    </label>
                </p>
                <p style="margin-top:14px;">
                    <button type="submit" class="button" <?php disabled(count($accounts) < 2); ?>>
                        Registrar transferencia
                    </button>
                </p>
                <?php if (count($accounts) < 2): ?>
                    <span class="fin-help">Necesitas al menos 2 cuentas activas.</span>
                <?php endif; ?>
            </form>
        </div>
    </div><?php // /.fin-mov-forms ?>

    <?php // ── Listado + filtros (ancho completo, debajo de los forms) ── ?>
    <div class="fin-mov-list">
        <h2>Historial de movimientos</h2>
        <div style="min-width:0;">
            <form method="get" class="fin-filter-bar" style="margin:0 0 12px;">
                <input type="hidden" name="page" value="<?php echo esc_attr(FIN_Admin::PAGE_SLUG); ?>">
                <input type="hidden" name="tab" value="movimientos">
                <input type="hidden" name="orderby" value="<?php echo esc_attr($orderby); ?>">
                <input type="hidden" name="order" value="<?php echo esc_attr($order); ?>">
                <?php // Filtro por categoría: no tiene selector propio (llega por enlace,
                      // p.ej. desde el Estado de Resultados). Se preserva para que
                      // "Filtrar" no lo pierda; se quita con el aviso de abajo. ?>
                <?php if (!empty($filter['category_id'])): ?>
                    <input type="hidden" name="category_id" value="<?php echo (int) $filter['category_id']; ?>">
                <?php endif; ?>
                <select name="account_id">
                    <option value="0">— Todas las cuentas —</option>
                    <?php foreach ($accounts_map as $aid => $aname): ?>
                        <option value="<?php echo (int) $aid; ?>" <?php selected($filter['account_id'], $aid); ?>>
                            <?php echo esc_html($aname); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select name="type">
                    <option value="">— Tipo —</option>
                    <?php foreach (['ingreso', 'egreso', 'transfer_in', 'transfer_out', 'opening'] as $tp): ?>
                        <option value="<?php echo esc_attr($tp); ?>" <?php selected($filter['type'], $tp); ?>>
                            <?php echo esc_html(FIN_Movements::type_label($tp)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select name="currency">
                    <option value="">— Moneda —</option>
                    <?php foreach ($currencies as $code => $cur): ?>
                        <option value="<?php echo esc_attr($code); ?>" <?php selected($filter['currency'], $code); ?>>
                            <?php echo esc_html($code . ' (' . $cur['symbol'] . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="date" name="from" value="<?php echo esc_attr($filter['from']); ?>">
                <input type="date" name="to"   value="<?php echo esc_attr($filter['to']); ?>">
                <input type="search" name="s" placeholder="Buscar descripción / ref"
                       value="<?php echo esc_attr($filter['search']); ?>" style="min-width:180px;">
                <button type="submit" class="button">Filtrar</button>
                <a href="<?php echo esc_url($list_url); ?>" class="button">Limpiar</a>
                <?php
                $export_url = add_query_arg(array_merge(
                    ['action' => 'fin_export_movements', FIN_Admin::NONCE_FIELD => $nonce],
                    array_filter([
                        'account_id'  => $filter['account_id'] ?: null,
                        'category_id' => $filter['category_id'] ?: null,
                        'type'        => $filter['type'] ?: null,
                        'currency'    => $filter['currency'] ?: null,
                        'from'        => $filter['from'] ?: null,
                        'to'          => $filter['to'] ?: null,
                        's'           => $filter['search'] ?: null,
                        'orderby'     => $orderby,
                        'order'       => $order,
                    ])
                ), $base_url);
                ?>
                <a href="<?php echo esc_url($export_url); ?>" class="button">Exportar CSV</a>
            </form>

            <?php if (!empty($filter['category_id'])):
                $fin_cat_name = $categories_map[(int) $filter['category_id']] ?? ('#' . (int) $filter['category_id']);
            ?>
                <p class="fin-help" style="margin:0 0 10px;">
                    Filtrado por categoría: <strong><?php echo esc_html($fin_cat_name); ?></strong>
                    — <a href="<?php echo esc_url(remove_query_arg('category_id')); ?>">quitar</a>
                </p>
            <?php endif; ?>

            <?php
            // Control de saldos del historial: resumen del filtro + saldos al corte.
            $cutoff_label = $filter['to'] !== ''
                ? ('al ' . mysql2date('d/m/Y', $filter['to'] . ' 12:00:00'))
                : '(actual)';
            // SALDO GENERAL por moneda al corte (no se consolida entre monedas).
            $cutoff_by_cur = [];
            ?>
            <div class="fin-mov-control">
                <div class="fin-mov-summary">
                    <span><strong><?php echo (int) $filtered_totals['count']; ?></strong> movimiento(s) en el filtro</span>
                    <?php if (empty($filtered_totals['by_currency'])): ?>
                        <span class="fin-status-muted">Sin movimientos.</span>
                    <?php else: foreach ($filtered_totals['by_currency'] as $code => $tc): $neto_neg = $tc['neto'] < 0; ?>
                        <span class="fin-mov-summary__cur">
                            <strong><?php echo esc_html($code); ?></strong> ·
                            Ingresos: <strong class="fin-status-ok"><?php echo esc_html(fin_money($tc['ingresos'], $code)); ?></strong> ·
                            Egresos: <strong class="fin-status-err"><?php echo esc_html(fin_money($tc['egresos'], $code)); ?></strong> ·
                            Neto: <strong class="<?php echo $neto_neg ? 'fin-status-err' : 'fin-status-ok'; ?>"><?php echo esc_html(fin_money($tc['neto'], $code)); ?></strong>
                        </span>
                    <?php endforeach; endif; ?>
                </div>
                <div class="fin-cutoff">
                    <span class="fin-cutoff__label">Saldo por cuenta <?php echo esc_html($cutoff_label); ?>:</span>
                    <?php foreach ($accounts as $a):
                        $aid  = (int) $a['id'];
                        $acur = (string) $a['currency'];
                        $bal  = $balances_cutoff[$aid] ?? 0.0;
                        $cutoff_by_cur[$acur] = ($cutoff_by_cur[$acur] ?? 0.0) + $bal;
                    ?>
                        <span class="fin-cutoff__item">
                            <?php echo esc_html($a['name']); ?>:
                            <b class="<?php echo $bal < 0 ? 'fin-status-err' : ''; ?>"><?php echo esc_html(fin_money($bal, $acur)); ?></b>
                        </span>
                    <?php endforeach; ?>
                    <?php foreach ($cutoff_by_cur as $code => $sum): ?>
                        <span class="fin-cutoff__item fin-cutoff__item--total">
                            SALDO <?php echo esc_html($code); ?>:
                            <b class="<?php echo $sum < 0 ? 'fin-status-err' : ''; ?>"><?php echo esc_html(fin_money($sum, $code)); ?></b>
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php
            // Base de args (filtros actuales) para preservar al cambiar el orden.
            $sort_base = array_filter([
                'page'        => FIN_Admin::PAGE_SLUG,
                'tab'         => 'movimientos',
                'account_id'  => $filter['account_id'] ?: null,
                'type'        => $filter['type'] ?: null,
                'currency'    => $filter['currency'] ?: null,
                'from'        => $filter['from'] ?: null,
                'to'          => $filter['to'] ?: null,
                's'           => $filter['search'] ?: null,
            ]);
            // Link de una columna ordenable: alterna asc/desc si ya es la activa,
            // si no usa una dirección inicial sensata (fechas/montos DESC, id ASC).
            $sort_link = function ($col) use ($orderby, $order, $sort_base) {
                if ($orderby === $col) {
                    $next = ($order === 'ASC') ? 'DESC' : 'ASC';
                } else {
                    $next = in_array($col, ['movement_date', 'amount'], true) ? 'DESC' : 'ASC';
                }
                return esc_url(add_query_arg(
                    array_merge($sort_base, ['orderby' => $col, 'order' => $next]),
                    admin_url('admin.php')
                ));
            };
            // Flecha de estado: ▲/▼ en la columna activa, ↕ atenuado en las demás.
            $sort_arrow = function ($col) use ($orderby, $order) {
                if ($orderby === $col) {
                    return ' <span class="fin-sort-ind">' . ($order === 'ASC' ? '▲' : '▼') . '</span>';
                }
                return ' <span class="fin-sort-ind fin-sort-ind--idle">↕</span>';
            };
            ?>
            <div class="fin-table-scroll">
            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:52px;"><a class="fin-sort" href="<?php echo $sort_link('id'); ?>">ID<?php echo $sort_arrow('id'); ?></a></th>
                        <th style="width:150px;"><a class="fin-sort" href="<?php echo $sort_link('movement_date'); ?>">Fecha<?php echo $sort_arrow('movement_date'); ?></a></th>
                        <th>Cuenta</th>
                        <th>Tipo</th>
                        <th>Categoría / Descripción</th>
                        <th style="width:120px;" class="fin-num"><a class="fin-sort" href="<?php echo $sort_link('amount'); ?>">Monto<?php echo $sort_arrow('amount'); ?></a></th>
                        <th style="width:120px;" class="fin-num" title="Saldo de la cuenta tras este movimiento">Saldo cuenta</th>
                        <th style="width:120px;" class="fin-num" title="Saldo general (suma de todas las cuentas) tras este movimiento">Saldo general</th>
                        <th style="width:80px;">Acción</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($movements)): ?>
                    <tr><td colspan="9"><em>No hay movimientos que coincidan con el filtro.</em></td></tr>
                <?php else: foreach ($movements as $m):
                    $is_in     = $m['direction'] === 'I';
                    $reversed  = !empty($m['reversed_at']);
                    $is_rev    = !empty($m['reverses_id']);
                    $cat_name  = (!empty($m['category_id']) && isset($categories_map[(int) $m['category_id']]))
                        ? $categories_map[(int) $m['category_id']] : '';
                    $can_reverse = in_array($m['type'], ['ingreso', 'egreso'], true) && !$reversed && !$is_rev;
                    $reverse_url = add_query_arg([
                        'action'               => 'fin_reverse_movement',
                        'movement_id'          => (int) $m['id'],
                        FIN_Admin::NONCE_FIELD => $nonce,
                    ], $base_url);
                ?>
                    <tr<?php echo $reversed ? ' style="opacity:.55;"' : ''; ?>>
                        <td><?php echo (int) $m['id']; ?></td>
                        <td><?php echo esc_html(mysql2date('d/m/Y H:i', $m['movement_date'])); ?></td>
                        <td><?php echo esc_html($accounts_map[(int) $m['account_id']] ?? ('#' . (int) $m['account_id'])); ?></td>
                        <td>
                            <span class="<?php echo $is_in ? 'fin-status-ok' : 'fin-status-err'; ?>">
                                <?php echo esc_html(FIN_Movements::type_label($m['type'])); ?>
                            </span>
                            <?php if ($reversed): ?><br><span class="fin-status-muted">anulado</span><?php endif; ?>
                            <?php if ($is_rev): ?><br><span class="fin-status-muted">contrasiento</span><?php endif; ?>
                        </td>
                        <td>
                            <?php if ($cat_name !== ''): ?><strong><?php echo esc_html($cat_name); ?></strong><br><?php endif; ?>
                            <?php echo esc_html((string) $m['description']); ?>
                            <?php if (!empty($m['ref_code'])): ?>
                                <span class="fin-mono fin-status-muted">[<?php echo esc_html($m['ref_code']); ?>]</span>
                            <?php endif; ?>
                        </td>
                        <?php
                        $mcur     = (string) ($m['currency'] ?? FIN_Currencies::BASE_CODE);
                        $mrate    = (float) ($m['rate_to_base'] ?? 1);
                        $non_base = !FIN_Currencies::is_base($mcur);
                        ?>
                        <td class="fin-num <?php echo $is_in ? 'fin-status-ok' : 'fin-status-err'; ?>">
                            <?php echo ($is_in ? '+' : '−') . esc_html(fin_money($m['amount'], $mcur)); ?>
                            <?php if ($non_base && $mrate > 0): ?>
                                <br><span class="fin-help" title="Equivalente en Bs al TC del movimiento (informativo)">
                                    ≈ <?php echo esc_html(fin_money((float) $m['amount'] * $mrate)); ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="fin-num"><?php echo esc_html(fin_money($m['balance_after'], $mcur)); ?></td>
                        <td class="fin-num-b"><?php
                            $gen = $running_general[(int) $m['id']] ?? null;
                            echo $gen === null ? '—' : esc_html(fin_money($gen, $mcur));
                        ?></td>
                        <td>
                            <?php if ($can_reverse): ?>
                                <a href="<?php echo esc_url($reverse_url); ?>" class="button button-small"
                                   onclick="return confirm('¿Anular este movimiento con un contrasiento?');">Anular</a>
                            <?php else: ?>
                                <span class="fin-status-muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
            </div><?php // /.fin-table-scroll ?>
        </div>
    </div>
</div>
<?php // El JS del filtro de motivos y el guard anti-doble-submit viven en assets/js/admin.js ?>
