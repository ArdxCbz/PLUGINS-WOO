<?php
if (!defined('ABSPATH')) {
    exit;
}
/**
 * Pestaña "Configuración": CRUD de categorías contables + automatizaciones de
 * pedidos (depósito → ingreso, costo de envío → egreso manual) + motivos
 * permitidos por cuenta.
 *
 * @var array  $categories    Filas de fin_categories.
 * @var array  $accounts      Cuentas activas (para los selects de cuenta).
 * @var array  $cats_ingreso  Categorías ingreso activas.
 * @var array  $cats_egreso   Categorías egreso activas.
 * @var array  $groups        Grupos contables seleccionables (FIN_Groups::selectable()).
 * @var array  $currencies    Catálogo de monedas [code => ['symbol','name','rate']].
 * @var string $editing_currency  Código de moneda en edición ('' si ninguna).
 * @var array  $editing_cat   Categoría en edición o null.
 * @var array  $cfg           Opciones de integración/automatización (ver render_config).
 * @var string $nonce
 * @var string $base_url      admin-post.php
 * @var string $flash_msg
 * @var string $flash_err
 */
$cat_save_url = add_query_arg([
    'action'               => 'fin_save_category',
    FIN_Admin::NONCE_FIELD => $nonce,
], $base_url);

$dep_save_url = add_query_arg([
    'action'               => 'fin_save_order_deposit',
    FIN_Admin::NONCE_FIELD => $nonce,
], $base_url);

$ship_save_url = add_query_arg([
    'action'               => 'fin_save_order_shipping',
    FIN_Admin::NONCE_FIELD => $nonce,
], $base_url);

$ibex_save_url = add_query_arg([
    'action'               => 'fin_save_order_ibex',
    FIN_Admin::NONCE_FIELD => $nonce,
], $base_url);

$accmot_save_url = add_query_arg([
    'action'               => 'fin_save_account_motivos',
    FIN_Admin::NONCE_FIELD => $nonce,
], $base_url);

$income_sales_save_url = add_query_arg([
    'action'               => 'fin_save_income_sales',
    FIN_Admin::NONCE_FIELD => $nonce,
], $base_url);

$currency_save_url = add_query_arg([
    'action'               => 'fin_save_currency',
    FIN_Admin::NONCE_FIELD => $nonce,
], $base_url);

$list_url = FIN_Admin::tab_url('config');

// Moneda en edición (o valores en blanco para el alta).
$cur_edit   = ($editing_currency !== '' && isset($currencies[$editing_currency])) ? $currencies[$editing_currency] : null;
$cur_code   = $cur_edit ? $editing_currency : '';
$cur_symbol = $cur_edit ? (string) $cur_edit['symbol'] : '';
$cur_name   = $cur_edit ? (string) $cur_edit['name']   : '';
$cur_rate   = $cur_edit ? (float)  $cur_edit['rate']   : 0;
$cur_is_base = ($cur_code === FIN_Currencies::BASE_CODE);

$is_edit  = !empty($editing_cat);
$c_id     = $is_edit ? (int)    $editing_cat['id']     : 0;
$c_name   = $is_edit ? (string) $editing_cat['name']   : '';
$c_nat    = $is_edit ? (string) $editing_cat['nature'] : 'egreso';
$c_group  = $is_edit ? (string) ($editing_cat['group_key'] ?? '') : '';
$c_reqd   = $is_edit ? (int)    ($editing_cat['requires_description'] ?? 0) : 0;
$c_act    = $is_edit ? (int)    $editing_cat['active'] : 1;

$flash_labels = [
    'cat_created'      => 'Categoría creada.',
    'cat_updated'      => 'Categoría actualizada.',
    'cat_toggled'      => 'Estado de la categoría actualizado.',
    'config_saved'     => 'Configuración guardada.',
    'acc_motivos_saved' => 'Motivos permitidos de la cuenta actualizados.',
    'cur_saved'        => 'Moneda guardada.',
    'cur_deleted'      => 'Moneda eliminada.',
];
?>
<div class="wrap">
    <h1 class="wp-heading-inline">Configuración</h1>
    <hr class="wp-header-end">

    <?php if ($flash_msg !== '' && isset($flash_labels[$flash_msg])): ?>
        <div class="notice notice-success is-dismissible"><p><?php echo esc_html($flash_labels[$flash_msg]); ?></p></div>
    <?php endif; ?>
    <?php if ($flash_err !== ''): ?>
        <div class="notice notice-error is-dismissible"><p><?php echo esc_html($flash_err); ?></p></div>
    <?php endif; ?>

    <?php // ════════ Sección 0: Monedas ════════ ?>
    <div class="fin-config-section">
        <h2>Monedas</h2>
        <p class="fin-help" style="margin-top:0;">
            Monedas disponibles para las cuentas. El <strong>tipo de cambio por defecto</strong> (Bs por
            unidad) solo precarga el campo de TC en movimientos y traspasos. La moneda base
            (<strong><?php echo esc_html(FIN_Currencies::BASE_CODE . ' — ' . FIN_Currencies::symbol(FIN_Currencies::BASE_CODE)); ?></strong>)
            tiene TC = 1 y no se elimina.
        </p>
        <div style="display:flex;gap:24px;align-items:flex-start;flex-wrap:wrap;">
            <div class="fin-card" style="flex:0 0 320px;">
                <h2 style="margin-top:0;"><?php echo $cur_edit ? ('Editar moneda ' . esc_html($cur_code)) : 'Nueva moneda'; ?></h2>
                <form method="post" action="<?php echo esc_url($currency_save_url); ?>">
                    <p>
                        <label><strong>Código *</strong>
                            <input type="text" name="code" maxlength="5" required
                                   value="<?php echo esc_attr($cur_code); ?>"
                                   style="width:100%;text-transform:uppercase;"
                                   <?php echo $cur_edit ? 'readonly' : ''; ?>
                                   placeholder="USD, EUR…">
                        </label>
                        <span class="fin-help fin-help-block">2 a 5 letras (ISO). No se puede cambiar luego.</span>
                    </p>
                    <p>
                        <label><strong>Símbolo *</strong>
                            <input type="text" name="symbol" maxlength="8" required
                                   value="<?php echo esc_attr($cur_symbol); ?>" style="width:100%;" placeholder="$">
                        </label>
                    </p>
                    <p>
                        <label><strong>Nombre *</strong>
                            <input type="text" name="name" maxlength="60" required
                                   value="<?php echo esc_attr($cur_name); ?>" style="width:100%;" placeholder="Dólar estadounidense">
                        </label>
                    </p>
                    <p>
                        <label><strong>Tipo de cambio por defecto (Bs por unidad)<?php echo $cur_is_base ? '' : ' *'; ?></strong>
                            <input type="number" name="rate" min="0.000001" step="0.000001"
                                   value="<?php echo esc_attr($cur_is_base ? '1' : ($cur_rate ? number_format($cur_rate, 6, '.', '') : '')); ?>"
                                   style="width:100%;" <?php echo $cur_is_base ? 'readonly' : ''; ?>>
                        </label>
                        <?php if ($cur_is_base): ?>
                            <span class="fin-help fin-help-block">La moneda base siempre vale 1.</span>
                        <?php endif; ?>
                    </p>
                    <p style="margin-top:14px;">
                        <button type="submit" class="button button-primary"><?php echo $cur_edit ? 'Guardar' : 'Crear moneda'; ?></button>
                        <?php if ($cur_edit): ?>
                            <a href="<?php echo esc_url($list_url); ?>" class="button">Cancelar</a>
                        <?php endif; ?>
                    </p>
                </form>
            </div>

            <div style="flex:1 1 420px;min-width:0;">
                <table class="widefat fixed striped">
                    <thead><tr>
                        <th style="width:70px;">Código</th>
                        <th style="width:70px;">Símbolo</th>
                        <th>Nombre</th>
                        <th class="fin-num" style="width:120px;">TC por defecto</th>
                        <th style="width:150px;">Acciones</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($currencies as $code => $cur):
                        $is_base = ($code === FIN_Currencies::BASE_CODE);
                        $edit_url = FIN_Admin::tab_url('config', ['currency_code' => $code]);
                        $del_url  = add_query_arg([
                            'action'               => 'fin_delete_currency',
                            'code'                 => $code,
                            FIN_Admin::NONCE_FIELD => $nonce,
                        ], $base_url);
                    ?>
                        <tr>
                            <td><strong><?php echo esc_html($code); ?></strong><?php echo $is_base ? ' <span class="fin-help">(base)</span>' : ''; ?></td>
                            <td><?php echo esc_html($cur['symbol']); ?></td>
                            <td><?php echo esc_html($cur['name']); ?></td>
                            <td class="fin-num"><?php echo $is_base ? '1.000000' : esc_html(number_format((float) $cur['rate'], 6, '.', ',')); ?></td>
                            <td>
                                <a href="<?php echo esc_url($edit_url); ?>" class="button button-small">Editar</a>
                                <?php if (!$is_base): ?>
                                    <a href="<?php echo esc_url($del_url); ?>" class="button button-small"
                                       onclick="return confirm('¿Eliminar la moneda <?php echo esc_js($code); ?>? Solo si ninguna cuenta la usa.');">Eliminar</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php // ════════ Sección 1: Automatizaciones ════════ ?>
    <div class="fin-config-section">
        <h2>Automatizaciones</h2>
        <p class="fin-help" style="margin-top:0;">
            Reglas que registran movimientos por sí solas. Cada una se activa de
            forma independiente y necesita una cuenta y una categoría.
        </p>
        <div class="fin-config-autos">

        <?php // ── Pedido completado → ingreso por depósito ──────────────── ?>
        <div class="fin-card">
            <h2 style="margin-top:0;">Pedido completado → Ingreso (depósito)</h2>
            <p class="fin-help">
                Cuando un pedido pasa a <strong>completado</strong> se registra un
                ingreso por el monto del depósito del pedido, contra la cuenta elegida.
                Un solo registro por pedido (no se duplica aunque cambie de estado).
            </p>
            <form method="post" action="<?php echo esc_url($dep_save_url); ?>">
                <p>
                    <label>
                        <input type="checkbox" name="autopost" value="1" <?php checked($cfg['dep_autopost'], 1); ?>>
                        Registrar ingreso automático al completar un pedido
                    </label>
                </p>
                <p>
                    <label>Cuenta destino del ingreso
                        <select name="account" style="width:100%;">
                            <option value="0">— Ninguna (desactiva) —</option>
                            <?php foreach ($accounts as $a): ?>
                                <option value="<?php echo (int) $a['id']; ?>" <?php selected($cfg['dep_account'], (int) $a['id']); ?>>
                                    <?php echo esc_html($a['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </p>
                <p>
                    <label>Categoría del ingreso
                        <select name="category" style="width:100%;">
                            <option value="0">— Ninguna (desactiva) —</option>
                            <?php foreach ($cats_ingreso as $c): ?>
                                <option value="<?php echo (int) $c['id']; ?>" <?php selected($cfg['dep_category'], (int) $c['id']); ?>>
                                    <?php echo esc_html($c['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <span class="fin-help fin-help-block">
                        Solo categorías de naturaleza <em>ingreso</em>. Sin categoría, la automatización no registra nada.
                    </span>
                </p>
                <p class="fin-card-actions">
                    <button type="submit" class="button button-primary">Guardar configuración</button>
                </p>
            </form>
        </div>

        <?php // ── Costo de envío (courier) → egreso manual por día ─────── ?>
        <div class="fin-card">
            <h2 style="margin-top:0;">Costo de envío (courier) → Egreso (Caja Chica)</h2>
            <p class="fin-help">
                El egreso del costo de envío <strong>no es automático</strong>: se
                registra a mano desde el panel diario en <em>Egresos de envío (courier)</em>
                (el costo suele ajustarse durante el día). Aquí defines la cuenta, la
                categoría y qué <strong>métodos de envío</strong> entran al panel.
            </p>
            <form method="post" action="<?php echo esc_url($ship_save_url); ?>">
                <p>
                    <label>Cuenta de la que sale el egreso
                        <select name="account" style="width:100%;">
                            <option value="0">— Ninguna —</option>
                            <?php foreach ($accounts as $a): ?>
                                <option value="<?php echo (int) $a['id']; ?>" <?php selected($cfg['ship_account'], (int) $a['id']); ?>>
                                    <?php echo esc_html($a['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <span class="fin-help fin-help-block">
                        Elige aquí "Caja Chica" una vez creada.
                    </span>
                </p>
                <p>
                    <label>Categoría del egreso
                        <select name="category" style="width:100%;">
                            <option value="0">— Ninguna —</option>
                            <?php foreach ($cats_egreso as $c): ?>
                                <option value="<?php echo (int) $c['id']; ?>" <?php selected($cfg['ship_category'], (int) $c['id']); ?>>
                                    <?php echo esc_html($c['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <span class="fin-help fin-help-block">
                        Solo categorías de naturaleza <em>egreso</em>.
                    </span>
                </p>
                <p>
                    <strong>Métodos de envío permitidos</strong>
                    <span class="fin-help fin-help-block" style="margin-bottom:6px;">
                        Solo los pedidos con uno de estos métodos entran al panel diario.
                        Lista: métodos usados en los últimos 6 meses.
                    </span>
                    <span class="fin-ship-methods">
                        <?php if (empty($ship_methods)): ?>
                            <em class="fin-help">No se encontraron métodos de envío en los últimos 6 meses. Escribe uno manualmente abajo.</em>
                        <?php else: foreach ($ship_methods as $m): ?>
                            <label class="fin-ship-method">
                                <input type="checkbox" name="methods[]" value="<?php echo esc_attr($m); ?>"
                                       <?php checked(in_array($m, $cfg['ship_methods'], true)); ?>>
                                <?php echo esc_html($m); ?>
                            </label>
                        <?php endforeach; endif; ?>
                    </span>
                </p>
                <p>
                    <label>Agregar método manual (opcional)
                        <input type="text" name="methods[]" maxlength="150" style="width:100%;"
                               placeholder="Título exacto del método de envío">
                    </label>
                    <span class="fin-help fin-help-block">
                        Útil si el método no aparece en la lista. Debe coincidir
                        exactamente con el título del método de envío del pedido.
                    </span>
                </p>
                <p class="fin-card-actions">
                    <button type="submit" class="button button-primary">Guardar configuración</button>
                </p>
            </form>
        </div>

        <?php // ── Pago de envío IBEX → egreso manual por mes ───────────── ?>
        <div class="fin-card">
            <h2 style="margin-top:0;">Pago de envío IBEX → Egreso (por mes)</h2>
            <p class="fin-help">
                Para los pedidos con método de envío <strong>IBEX</strong>. Desde el panel
                <em>Egresos de envío</em> se valida y registra <strong>un egreso por sucursal</strong>
                cada mes = total del costo de envío de los pedidos de esa sucursal. Aquí defines
                la cuenta, una <strong>categoría por sucursal</strong> y el/los método(s) que cuentan como IBEX.
            </p>
            <form method="post" action="<?php echo esc_url($ibex_save_url); ?>">
                <p>
                    <label>Cuenta de la que sale el egreso
                        <select name="account" style="width:100%;">
                            <option value="0">— Ninguna —</option>
                            <?php foreach ($accounts as $a): ?>
                                <option value="<?php echo (int) $a['id']; ?>" <?php selected($cfg['ibex_account'], (int) $a['id']); ?>>
                                    <?php echo esc_html($a['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </p>
                <p>
                    <strong>Categoría de egreso por sucursal</strong>
                    <span class="fin-help fin-help-block" style="margin-bottom:6px;">
                        Cada sucursal registra su egreso IBEX en la categoría elegida. Solo
                        categorías de naturaleza <em>egreso</em>. Una sucursal sin categoría
                        no se puede validar.
                    </span>
                    <?php foreach ($ibex_sucursales as $suc):
                        $suc_cat = (int) ($cfg['ibex_categories'][$suc] ?? 0);
                    ?>
                        <label style="display:block;margin:4px 0;">
                            <span style="display:inline-block;min-width:130px;font-weight:600;"><?php echo esc_html($suc); ?></span>
                            <select name="categories[<?php echo esc_attr($suc); ?>]" style="width:60%;min-width:200px;">
                                <option value="0">— Ninguna —</option>
                                <?php foreach ($cats_egreso as $c): ?>
                                    <option value="<?php echo (int) $c['id']; ?>" <?php selected($suc_cat, (int) $c['id']); ?>>
                                        <?php echo esc_html($c['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    <?php endforeach; ?>
                </p>
                <p>
                    <strong>Método(s) de envío IBEX</strong>
                    <span class="fin-help fin-help-block" style="margin-bottom:6px;">
                        Solo los pedidos con uno de estos métodos entran al panel mensual.
                    </span>
                    <span class="fin-ship-methods">
                        <?php foreach ($ibex_methods_opts as $m): ?>
                            <label class="fin-ship-method">
                                <input type="checkbox" name="methods[]" value="<?php echo esc_attr($m); ?>"
                                       <?php checked(in_array($m, $cfg['ibex_methods'], true)); ?>>
                                <?php echo esc_html($m); ?>
                            </label>
                        <?php endforeach; ?>
                    </span>
                </p>
                <p>
                    <label>Agregar método manual (opcional)
                        <input type="text" name="methods[]" maxlength="150" style="width:100%;"
                               placeholder="Título exacto del método (ej: IBEX)">
                    </label>
                </p>
                <?php
                // Selector de corte: lista de meses con pedidos IBEX. Si el corte
                // guardado ya no figura entre los meses con datos, se agrega igual
                // para no perder la selección.
                $ibex_month_opts = $ibex_months_avail;
                if ($ibex_hide_before !== '' && !in_array($ibex_hide_before, $ibex_month_opts, true)) {
                    $ibex_month_opts[] = $ibex_hide_before;
                    sort($ibex_month_opts);
                }
                ?>
                <p>
                    <label>Mostrar pagos desde el mes
                        <select name="hide_before" style="width:100%;">
                            <option value="">Todos los meses</option>
                            <?php foreach ($ibex_month_opts as $m): ?>
                                <option value="<?php echo esc_attr($m); ?>" <?php selected($ibex_hide_before, $m); ?>>
                                    <?php echo esc_html(ucfirst(wp_date('F Y', strtotime($m . '-01 12:00:00')))); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <span class="fin-help fin-help-block">
                        El panel mensual mostrará este mes y los posteriores, y <strong>ocultará los anteriores</strong>
                        (meses ya cerrados fuera del sistema). Es solo un filtro de visualización: no crea ni elimina egresos.
                        <em>Todos los meses</em> = sin corte.
                    </span>
                </p>
                <p class="fin-card-actions">
                    <button type="submit" class="button button-primary">Guardar configuración</button>
                </p>
            </form>
        </div>

        </div><?php // /.fin-config-autos ?>
    </div><?php // /.fin-config-section (Automatizaciones) ?>

    <?php // ════════ Sección 2: Ventas del Estado de Resultados ════════ ?>
    <div class="fin-config-section">
        <h2>Ventas del Estado de Resultados</h2>
        <p class="fin-help" style="margin-top:0;">
            Las Ventas del Estado de Resultados se calculan desde los <strong>pedidos</strong>
            (base devengada): el total de cada pedido cuenta en el mes en que se creó,
            sin importar si está completado ni si tiene depósito. Aquí defines qué
            <strong>estados se excluyen</strong> (cancelado/retorno, no son venta) y cuáles
            cuentan como <strong>cobrado/completado</strong> (para el desglose “por cobrar”).
        </p>

        <div class="fin-card" style="max-width:680px;">
            <?php if (empty($order_statuses)): ?>
                <p><em>WooCommerce no devolvió estados de pedido.</em></p>
            <?php else: ?>
                <?php if (!$sales_configured): ?>
                    <div class="notice notice-warning inline" style="margin:0 0 12px;"><p>
                        Aún sin configurar: por ahora los estados se <strong>autodetectan por su nombre</strong>.
                        Revisa la selección y guarda para fijarla.
                    </p></div>
                <?php endif; ?>
                <form method="post" action="<?php echo esc_url($income_sales_save_url); ?>">
                    <div class="fin-accmot-cols">
                        <div>
                            <h4 style="margin:0 0 6px;">Excluir de Ventas (cancelado/retorno)</h4>
                            <?php foreach ($order_statuses as $slug => $label):
                                $s = preg_replace('/^wc-/', '', $slug);
                            ?>
                                <label class="fin-accmot-item">
                                    <input type="checkbox" name="excluded[]" value="<?php echo esc_attr($s); ?>"
                                           <?php checked(isset($sales_excluded[$s])); ?>>
                                    <?php echo esc_html($label); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <div>
                            <h4 style="margin:0 0 6px;">Cuenta como cobrado / completado</h4>
                            <?php foreach ($order_statuses as $slug => $label):
                                $s = preg_replace('/^wc-/', '', $slug);
                            ?>
                                <label class="fin-accmot-item">
                                    <input type="checkbox" name="collected[]" value="<?php echo esc_attr($s); ?>"
                                           <?php checked(isset($sales_collected[$s])); ?>>
                                    <?php echo esc_html($label); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <span class="fin-help fin-help-block">
                        “Por cobrar” = Ventas no marcadas como cobrado. Es una cuenta por cobrar
                        (activo), no afecta la utilidad.
                    </span>
                    <p style="margin-top:14px;">
                        <button type="submit" class="button button-primary">Guardar configuración de ventas</button>
                    </p>
                </form>
            <?php endif; ?>
        </div>
    </div><?php // /.fin-config-section (Ventas) ?>

    <?php // ════════ Sección 3: Motivos contables ════════ ?>
    <div class="fin-config-section">
        <h2>Motivos contables</h2>
        <p class="fin-help" style="margin-top:0;">
            Cada ingreso/egreso elige un motivo; el motivo determina el grupo contable.
        </p>
        <div class="fin-config-motivos">

        <?php // ── Form Motivo (categoría) ────────────────────────────── ?>
        <div class="fin-card fin-config-motivos__form">
            <h2 style="margin-top:0;">
                <?php echo $is_edit ? 'Editar motivo #' . (int) $c_id : 'Nuevo motivo'; ?>
            </h2>
            <form method="post" action="<?php echo esc_url($cat_save_url); ?>">
                <?php if ($is_edit): ?>
                    <input type="hidden" name="id" value="<?php echo (int) $c_id; ?>">
                <?php endif; ?>
                <p>
                    <label><strong>Nombre del motivo *</strong>
                        <input type="text" name="name" required maxlength="150"
                               value="<?php echo esc_attr($c_name); ?>" style="width:100%;"
                               placeholder="Ej: Alquiler, Publicidad, Otros…">
                    </label>
                </p>
                <p>
                    <label><strong>Grupo contable *</strong>
                        <select name="group_key" id="fin-cat-group" required style="width:100%;">
                            <option value="">— Elegir grupo —</option>
                            <?php foreach ($groups as $gk => $g): ?>
                                <option value="<?php echo esc_attr($gk); ?>"
                                        data-nature="<?php echo esc_attr($g['nature']); ?>"
                                        <?php selected($c_group, $gk); ?>>
                                    <?php echo esc_html($g['label']); ?>
                                    <?php echo $g['affects_result'] ? '' : ' (no afecta resultado)'; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <span class="fin-help fin-help-block">
                        El grupo define si el motivo es ingreso o egreso y cómo entra al Estado de Resultados.
                    </span>
                </p>
                <?php // Naturaleza: solo aplica al grupo "Patrimonial" (nature=ambos). ?>
                <p id="fin-cat-nature-wrap" style="display:none;">
                    <label>Naturaleza (solo grupo Patrimonial)
                        <select name="nature" style="width:100%;">
                            <option value="ingreso" <?php selected($c_nat, 'ingreso'); ?>>Ingreso</option>
                            <option value="egreso"  <?php selected($c_nat, 'egreso'); ?>>Egreso</option>
                        </select>
                    </label>
                </p>
                <p>
                    <label>
                        <input type="checkbox" name="requires_description" value="1" <?php checked($c_reqd, 1); ?>>
                        Requiere descripción (motivo tipo "Otros")
                    </label>
                </p>
                <p>
                    <label>
                        <input type="checkbox" name="active" value="1" <?php checked($c_act, 1); ?>>
                        Activo
                    </label>
                </p>
                <p style="margin-top:14px;">
                    <button type="submit" class="button button-primary">
                        <?php echo $is_edit ? 'Guardar' : 'Crear motivo'; ?>
                    </button>
                    <?php if ($is_edit): ?>
                        <a href="<?php echo esc_url($list_url); ?>" class="button">Cancelar</a>
                    <?php endif; ?>
                </p>
            </form>
        </div>

        <?php // ── Listado de motivos ─────────────────────────────────── ?>
        <div class="fin-config-motivos__list">
            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:36px;">#</th>
                        <th>Motivo</th>
                        <th style="width:80px;">Tipo</th>
                        <th style="width:70px;">Estado</th>
                        <th style="width:140px;">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($categories)): ?>
                    <tr><td colspan="5"><em>No hay motivos todavía. Crea al menos uno por grupo que uses.</em></td></tr>
                <?php else:
                    // Agrupar los motivos por grupo contable y, dentro, ordenarlos por #.
                    $cats_by_group = [];
                    foreach ($categories as $c) {
                        $cats_by_group[(string) ($c['group_key'] ?? '')][] = $c;
                    }
                    foreach ($cats_by_group as &$grp_list) {
                        usort($grp_list, function ($a, $b) { return (int) $a['id'] <=> (int) $b['id']; });
                    }
                    unset($grp_list);

                    // Orden de los grupos: el oficial de FIN_Groups; al final, los
                    // motivos sin grupo o con grupo desconocido (legado).
                    $group_order = array_keys(FIN_Groups::all());
                    foreach (array_keys($cats_by_group) as $gk) {
                        if (!in_array($gk, $group_order, true)) {
                            $group_order[] = $gk;
                        }
                    }

                    foreach ($group_order as $gk):
                        if (empty($cats_by_group[$gk])) {
                            continue;
                        }
                        $grp     = FIN_Groups::get($gk);
                        $g_label = $grp ? $grp['label'] : ($gk === '' ? 'Sin grupo' : $gk);
                        $affects = FIN_Groups::affects_result($gk);
                        $count   = count($cats_by_group[$gk]);
                ?>
                    <tr class="fin-group-row">
                        <th colspan="5">
                            <?php echo esc_html($g_label); ?>
                            <?php if ($grp && !$affects): ?>
                                <span class="fin-status-muted" style="font-weight:400;">· no afecta resultado</span>
                            <?php endif; ?>
                            <span class="fin-group-row__count"><?php echo (int) $count; ?> motivo<?php echo $count === 1 ? '' : 's'; ?></span>
                        </th>
                    </tr>
                    <?php foreach ($cats_by_group[$gk] as $c):
                        $active   = (int) $c['active'] === 1;
                        $edit_url = FIN_Admin::tab_url('config', ['category_id' => (int) $c['id']]);
                        $toggle_url = add_query_arg([
                            'action'               => 'fin_toggle_category',
                            'category_id'          => (int) $c['id'],
                            FIN_Admin::NONCE_FIELD => $nonce,
                        ], $base_url);
                        $is_ing    = $c['nature'] === 'ingreso';
                        $req_desc  = !empty($c['requires_description']);
                    ?>
                    <tr>
                        <td><?php echo (int) $c['id']; ?></td>
                        <td>
                            <strong><?php echo esc_html($c['name']); ?></strong>
                            <?php if ($req_desc): ?>
                                <br><span class="fin-help">requiere descripción</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="<?php echo $is_ing ? 'fin-status-ok' : 'fin-status-err'; ?>">
                                <?php echo esc_html(FIN_Categories::nature_label($c['nature'])); ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($active): ?>
                                <span class="fin-status-ok">Activo</span>
                            <?php else: ?>
                                <span class="fin-status-muted">Inactivo</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="<?php echo esc_url($edit_url); ?>" class="button button-small">Editar</a>
                            <a href="<?php echo esc_url($toggle_url); ?>" class="button button-small"
                               onclick="return confirm('<?php echo $active ? '¿Desactivar motivo?' : '¿Reactivar motivo?'; ?>');">
                                <?php echo $active ? 'Desactivar' : 'Activar'; ?>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        </div><?php // /.fin-config-motivos ?>
    </div><?php // /.fin-config-section (Motivos) ?>

    <?php // ════════ Sección 4: Motivos permitidos por cuenta ════════ ?>
    <div class="fin-config-section">
        <h2>Motivos permitidos por cuenta</h2>
        <p class="fin-help" style="margin-top:0;">
            Define qué motivos puede usar cada cuenta en <em>Registro de Movimientos</em>.
            <strong>Cada motivo pertenece a una sola cuenta</strong>: los ya asignados a otra
            cuenta aparecen deshabilitados aquí.
            <strong>Una cuenta sin motivos asignados no permite registrar movimientos manuales</strong>
            (las automatizaciones de depósito/envío no se ven afectadas).
        </p>

        <?php if (empty($accounts)): ?>
            <div class="fin-card">
                <p><em>No hay cuentas activas. Crea una en
                    <a href="<?php echo esc_url(FIN_Admin::tab_url('cuentas')); ?>">Cuentas</a>.</em></p>
            </div>
        <?php else: ?>
            <?php
            // Pinta un checkbox de motivo para la cuenta $aid: marcado si está en su
            // lista; deshabilitado si el motivo pertenece a OTRA cuenta (exclusividad).
            $accmot_checkbox = function ($c, $aid, $allowed) use ($motivo_owners, $accounts_name_map) {
                $cid      = (int) $c['id'];
                $owner_id = $motivo_owners[$cid] ?? 0;
                $taken    = $owner_id > 0 && $owner_id !== (int) $aid;
                $checked  = in_array($cid, $allowed, true);
                ?>
                <label class="fin-accmot-item<?php echo $taken ? ' is-taken' : ''; ?>">
                    <input type="checkbox" name="motivos[]" value="<?php echo $cid; ?>"
                           <?php checked($checked); ?> <?php disabled($taken); ?>>
                    <?php echo esc_html($c['name']); ?>
                    <?php if ($taken): ?>
                        <span class="fin-help">— en <?php echo esc_html($accounts_name_map[$owner_id] ?? ('#' . $owner_id)); ?></span>
                    <?php endif; ?>
                </label>
            <?php };
            ?>
            <div class="fin-accmot-grid">
            <?php foreach ($accounts as $a):
                $aid     = (int) $a['id'];
                $allowed = $account_motivos_map[$aid] ?? [];
                $cnt     = count($allowed);
            ?>
                <div class="fin-card fin-accmot-acc" id="fin-accmot-<?php echo $aid; ?>">
                    <h3 style="margin-top:0;">
                        <?php echo esc_html($a['name']); ?>
                        <span class="fin-help" style="font-weight:400;">
                            (<?php echo $cnt; ?> motivo<?php echo $cnt === 1 ? '' : 's'; ?>)
                        </span>
                    </h3>
                    <form method="post" action="<?php echo esc_url($accmot_save_url); ?>">
                        <input type="hidden" name="account_id" value="<?php echo $aid; ?>">
                        <div class="fin-accmot-cols">
                            <div>
                                <h4 style="margin:0 0 6px;">Motivos de ingreso</h4>
                                <?php if (empty($cats_ingreso)): ?>
                                    <p class="fin-help">No hay motivos de ingreso activos.</p>
                                <?php else: foreach ($cats_ingreso as $c) { $accmot_checkbox($c, $aid, $allowed); } endif; ?>
                            </div>
                            <div>
                                <h4 style="margin:0 0 6px;">Motivos de egreso</h4>
                                <?php if (empty($cats_egreso)): ?>
                                    <p class="fin-help">No hay motivos de egreso activos.</p>
                                <?php else: foreach ($cats_egreso as $c) { $accmot_checkbox($c, $aid, $allowed); } endif; ?>
                            </div>
                        </div>
                        <p style="margin-top:14px;">
                            <button type="submit" class="button button-primary">Guardar motivos de esta cuenta</button>
                        </p>
                    </form>
                </div>
            <?php endforeach; ?>
            </div><?php // /.fin-accmot-grid ?>
        <?php endif; ?>
    </div><?php // /.fin-config-section (Motivos por cuenta) ?>
</div><?php // /.wrap ?>
<?php // El toggle de naturaleza (grupo "ambos") vive en assets/js/admin.js ?>
