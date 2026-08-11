<?php
if (!defined('ABSPATH')) {
    exit;
}
/**
 * Pestaña "Egresos de envío": dos paneles —
 *  1) Courier por día (un egreso por pedido, fecha = creación del pedido).
 *  2) IBEX por mes. IBEX cobra el envío de los PEDIDOS y el de los TRASPASOS de
 *     stock entre sucursales, en una sola factura: se paga con UN egreso por
 *     mes·sucursal (pedidos + traspasos).
 *
 * El panel NO asienta: enlaza al formulario de Movimientos con el pago cargado,
 * para revisarlo (y ajustar el monto a la factura real) antes de tocar el ledger.
 * El egreso se fecha el día del PAGO, pero devenga en el MES QUE SE PAGA (es el
 * que generó el costo): esa es la fecha que usa el Estado de Resultados.
 *
 * @var bool   $ship_configured ¿Courier configurado (cuenta + categoría + métodos)?
 * @var array  $ship_methods    Métodos de envío courier permitidos (títulos).
 * @var array  $ship_days       Pedidos courier agrupados por día (ver FIN_Orders).
 * @var string $ship_from       'Y-m-d'
 * @var string $ship_to         'Y-m-d'
 * @var bool   $ibex_configured ¿IBEX configurado (cuenta + categoría + métodos)?
 * @var array  $ibex_methods    Métodos IBEX permitidos (títulos).
 * @var array  $ibex_view       Mes => [legacy…, ped_*, tp_*, sucursales[SUC => fila del pago]].
 * @var bool   $ibex_tp_available  ¿Está activo el plugin de Traspasos?
 * @var string $ibex_from       'Y-m-d'
 * @var string $ibex_to         'Y-m-d'
 * @var array|null $lock_account  Cuenta de caja chica (null si no está configurada).
 * @var array  $lock_state      Estado del cierre (date/by/at/balance/history).
 * @var string $lock_until      Fecha hasta la que la caja está cerrada ('' = abierta).
 * @var string $lock_cutoff     Corte propuesto para el próximo cierre.
 * @var array  $lock_blockers   Egresos pendientes que impiden cerrar a ese corte.
 * @var float  $lock_balance    Saldo de la caja al corte (= la deuda a recargar).
 * @var bool   $lock_can        ¿Se puede cerrar a ese corte?
 * @var string $nonce
 * @var string $base_url        admin-post.php
 * @var string $flash_msg
 * @var string $flash_err
 */
$ship_validate_url = add_query_arg([
    'action'              => 'fin_validate_shipping_day',
    FIN_Admin::NONCE_FIELD => $nonce,
], $base_url);

$close_cash_url = add_query_arg([
    'action'              => 'fin_close_cash',
    FIN_Admin::NONCE_FIELD => $nonce,
], $base_url);

$flash_labels = [
    'ship_saved'     => 'Montos de envío guardados.',
    'ship_validated' => 'Egresos de envío registrados.',
    'cash_closed'    => 'Caja chica rendida. Ya puedes registrar la reposición con fecha posterior a la rendición.',
];
// Detalle de la validación de envíos (conteos) para el aviso.
$ship_n = isset($_GET['ship_n']) ? (int) $_GET['ship_n'] : null;
$ship_s = isset($_GET['ship_s']) ? (int) $_GET['ship_s'] : 0;
$ship_e = isset($_GET['ship_e']) ? (int) $_GET['ship_e'] : 0;
?>
<div class="wrap">
    <h1 class="wp-heading-inline">Egresos de costo de envío por día (courier)</h1>
    <hr class="wp-header-end">

    <?php if ($flash_msg !== '' && isset($flash_labels[$flash_msg])): ?>
        <div class="notice notice-success is-dismissible"><p>
            <?php echo esc_html($flash_labels[$flash_msg]); ?>
            <?php if ($flash_msg === 'ship_validated' && $ship_n !== null): ?>
                <?php printf(' %d egreso(s) registrado(s)', $ship_n); ?>
                <?php if ($ship_s > 0) { printf(', %d omitido(s)', $ship_s); } ?>
                <?php if ($ship_e > 0) { printf(', %d con error', $ship_e); } ?>.
            <?php endif; ?>
        </p></div>
    <?php endif; ?>
    <?php if ($flash_err !== ''): ?>
        <div class="notice notice-error is-dismissible"><p><?php echo esc_html($flash_err); ?></p></div>
    <?php endif; ?>

    <?php // ── Rendición de caja chica ──────────────────────────────────────────
          // Vive aquí, y no en una pestaña aparte, porque lo que impide rendir son
          // los egresos de envío sin validar que se listan más abajo: el bloqueo y
          // su solución quedan a la vista, sin navegar.
    ?>
    <?php if ($lock_account): ?>
        <?php
        $lc_cur     = (string) $lock_account['currency'];
        $lc_pending = (int) $lock_blockers['count'];
        $lc_debt    = $lock_balance < 0 ? -$lock_balance : 0.0;
        ?>
        <div class="fin-card fin-cashlock">
            <h2 class="fin-cashlock__title">
                Rendición de caja chica — <?php echo esc_html($lock_account['name']); ?>
            </h2>

            <div class="fin-cashlock__state">
                <?php if ($lock_until !== ''): ?>
                    <span class="fin-cashlock__badge is-closed">
                        🔒 Rendida hasta el <strong><?php echo esc_html(mysql2date('d/m/Y', $lock_until . ' 12:00:00')); ?></strong>
                    </span>
                    <span class="fin-help">
                        No se admiten movimientos con esa fecha o anterior.
                        Primera fecha válida: <strong><?php echo esc_html(mysql2date('d/m/Y', FIN_Rendicion::first_open_date() . ' 12:00:00')); ?></strong>.
                        <?php if (!empty($lock_state['at'])): ?>
                            Rendida el <?php echo esc_html(mysql2date('d/m/Y H:i', $lock_state['at'])); ?>
                            <?php $lc_user = get_userdata((int) $lock_state['by']); ?>
                            <?php if ($lc_user): ?>por <?php echo esc_html($lc_user->display_name); ?><?php endif; ?>,
                            con saldo <?php echo esc_html(fin_money((float) $lock_state['balance'], $lc_cur)); ?>.
                        <?php endif; ?>
                    </span>
                <?php else: ?>
                    <span class="fin-cashlock__badge is-open">Sin rendir</span>
                    <span class="fin-help">Todo el histórico admite movimientos.</span>
                <?php endif; ?>
            </div>

            <?php
            // La fecha de corte se elige por GET, no como un simple campo del POST:
            // los pendientes, el saldo y el "a reponer" DEPENDEN de esa fecha, así
            // que hay que recalcularlos en el servidor cuando cambia. Con la fecha
            // como campo suelto del POST, el panel seguía mostrando el cálculo de HOY
            // aunque eligieras ayer —y bloqueaba la rendición por los pendientes de
            // hoy, que quedaban fuera del corte—. Al recargar por GET, todo lo que se
            // ve corresponde a la fecha elegida.
            //
            // Los dos formularios son HERMANOS, no anidados (anidar <form> es HTML
            // inválido y el navegador descarta el interno).
            $lock_min = $lock_until !== '' ? FIN_Rendicion::first_open_date() : '';
            ?>
            <div class="fin-cashlock__grid">
                <form method="get" class="fin-cashlock__field">
                    <input type="hidden" name="page" value="<?php echo esc_attr(FIN_Admin::PAGE_SLUG); ?>">
                    <input type="hidden" name="tab"  value="envios">
                    <input type="hidden" name="ship_from" value="<?php echo esc_attr($ship_from); ?>">
                    <input type="hidden" name="ship_to"   value="<?php echo esc_attr($ship_to); ?>">
                    <span>Rendir hasta</span>
                    <span class="fin-cashlock__pick">
                        <input type="date" name="lock_cutoff" value="<?php echo esc_attr($lock_cutoff); ?>"
                               max="<?php echo esc_attr(wp_date('Y-m-d')); ?>"
                               <?php if ($lock_min !== ''): ?>min="<?php echo esc_attr($lock_min); ?>"<?php endif; ?>
                               onchange="this.form.submit();" required>
                        <button type="submit" class="button button-small">Calcular</button>
                    </span>
                    <span class="fin-help">Al cambiar la fecha se recalcula el saldo y los pendientes.</span>
                </form>
                <div class="fin-cashlock__field">
                    <span>Saldo de la caja a esa fecha</span>
                    <strong class="fin-cashlock__balance <?php echo $lock_balance < 0 ? 'fin-status-err' : 'fin-status-ok'; ?>">
                        <?php echo esc_html(fin_money($lock_balance, $lc_cur)); ?>
                    </strong>
                </div>
                <div class="fin-cashlock__field">
                    <span>A reponer</span>
                    <?php if ($lc_debt > 0): ?>
                        <strong class="fin-cashlock__balance"><?php echo esc_html(fin_money($lc_debt, $lc_cur)); ?></strong>
                    <?php else: ?>
                        <strong class="fin-cashlock__balance fin-status-ok">—</strong>
                        <span class="fin-help">la caja no está en rojo a esa fecha</span>
                    <?php endif; ?>
                </div>
            </div>

            <?php // Doble validación: la rendición es IRREVERSIBLE (no hay reabrir).
                  // 1) checkbox de reconocimiento, exigido también en el servidor;
                  // 2) confirmación con la fecha concreta que se va a rendir. ?>
            <form method="post" action="<?php echo esc_url($close_cash_url); ?>" class="fin-cashlock__form"
                  onsubmit="return confirm('Vas a RENDIR la caja chica hasta el <?php echo esc_js(mysql2date('d/m/Y', $lock_cutoff . ' 12:00:00')); ?>.\n\nEsto es IRREVERSIBLE: a partir de ahora no se podrán registrar ni anular movimientos con esa fecha o anterior.\n\n¿Confirmás?');">
                <input type="hidden" name="lock_cutoff" value="<?php echo esc_attr($lock_cutoff); ?>">

                <?php if ($lc_pending > 0): ?>
                    <p class="fin-cashlock__warn">
                        <strong>No se puede rendir hasta el <?php echo esc_html(mysql2date('d/m/Y', $lock_cutoff . ' 12:00:00')); ?>:
                        <?php echo (int) $lc_pending; ?> egreso(s) de envío sin validar</strong>
                        con esa fecha o anterior
                        <?php if ((float) $lock_blockers['total'] > 0): ?>
                            (<?php echo esc_html(fin_money((float) $lock_blockers['total'], $lc_cur)); ?> ya cargados)
                        <?php endif; ?>.
                        <?php // Los pendientes POSTERIORES al corte no bloquean: por eso rendir
                              // hasta ayer es válido aunque hoy haya pedidos sin validar. ?>
                        <span class="fin-help">
                            (Los pendientes con fecha posterior al corte no cuentan: podés rendir hasta una fecha
                            anterior y dejar los de después para más adelante.)
                        </span>
                        Esa plata ya salió de la caja pero todavía no está en el ledger: si rendís así, el saldo queda
                        inflado y <strong>repondrías un monto equivocado</strong>. Valídalos en los días de abajo.
                        <br>
                        <span class="fin-help">
                            Un pedido sin costo cargado también cuenta como pendiente: es la señal de que falta ponerle el
                            monto. Si de verdad no lleva costo de envío, cambiale el método de envío en el pedido y sale del panel.
                            <?php if (FIN_Orders::ship_hide_before() === ''): ?>
                                <br><strong>¿Son pedidos viejos, saldados fuera de Finanzas?</strong> No los valides:
                                fija el <em>arranque del panel</em> en
                                <a href="<?php echo esc_url(FIN_Admin::tab_url('config')); ?>">Configuración → Egreso de envío (courier)</a>
                                y dejarán de contar como pendientes (no se les registra ningún egreso).
                            <?php endif; ?>
                        </span>
                    </p>
                <?php endif; ?>

                <?php if ($lock_can): ?>
                    <p class="fin-cashlock__ack">
                        <label>
                            <input type="checkbox" name="confirm_rendir" value="1" required>
                            Entiendo que la rendición es <strong>irreversible</strong>: no se podrá deshacer
                            ni registrar o anular movimientos en la caja con esa fecha o anterior.
                        </label>
                    </p>
                <?php endif; ?>

                <p style="margin:12px 0 0;">
                    <button type="submit" class="button button-primary" <?php disabled(!$lock_can); ?>>
                        Rendir caja hasta esta fecha
                    </button>
                    <span class="fin-help fin-help-block">
                        Rendir solo fija la línea de partida: <strong>no registra la reposición</strong>.
                        Una vez rendida, registrá la reposición en
                        <a href="<?php echo esc_url(FIN_Admin::tab_url('movimientos')); ?>">Movimientos</a>
                        con fecha posterior a la rendición.
                    </span>
                </p>
            </form>
        </div>
    <?php endif; ?>

    <div class="fin-card fin-ship-panel">
        <?php if (!$ship_configured): ?>
            <div class="notice notice-warning inline" style="margin:8px 0;"><p>
                Configura la cuenta, la categoría y los métodos de envío permitidos en
                <a href="<?php echo esc_url(FIN_Admin::tab_url('config')); ?>">Configuración</a>
                para usar este panel.
            </p></div>
        <?php else: ?>
            <p class="fin-help">
                Métodos permitidos: <strong><?php echo esc_html(implode(', ', $ship_methods)); ?></strong>.
                Revisa el costo de envío de cada pedido (editable), guarda los ajustes y
                valida el día para registrar <strong>un egreso por pedido</strong>
                (fecha = creación del pedido). Lo no validado se acumula y puedes validarlo después.
            </p>

            <form method="get" class="fin-filter-bar" style="margin:6px 0 14px;">
                <input type="hidden" name="page" value="<?php echo esc_attr(FIN_Admin::PAGE_SLUG); ?>">
                <input type="hidden" name="tab" value="envios">
                <label>Desde <input type="date" name="ship_from" value="<?php echo esc_attr($ship_from); ?>"></label>
                <label>Hasta <input type="date" name="ship_to" value="<?php echo esc_attr($ship_to); ?>"></label>
                <button type="submit" class="button">Filtrar</button>
            </form>

            <?php if (empty($ship_days)): ?>
                <p><em>No hay pedidos con método de envío permitido en el rango elegido.</em></p>
            <?php else: foreach ($ship_days as $day => $info):
                $pending = (int) $info['pending_count'];
            ?>
                <div class="fin-ship-day">
                    <form method="post" action="<?php echo esc_url($ship_validate_url); ?>">
                        <input type="hidden" name="ship_from" value="<?php echo esc_attr($ship_from); ?>">
                        <input type="hidden" name="ship_to"   value="<?php echo esc_attr($ship_to); ?>">
                        <div class="fin-ship-day__head">
                            <strong><?php echo esc_html(wp_date('d/m/Y', strtotime($day . ' 12:00:00'))); ?></strong>
                            <span class="fin-help">
                                <?php echo (int) count($info['orders']); ?> pedido(s) ·
                                <?php echo $pending; ?> pendiente(s) ·
                                <?php echo (int) $info['validated_count']; ?> validado(s) ·
                                total pendiente <strong><?php echo esc_html(fin_money($info['pending_total'])); ?></strong>
                            </span>
                        </div>
                        <div class="fin-table-scroll">
                        <table class="widefat striped fin-ship-table">
                            <thead><tr>
                                <th>Pedido</th>
                                <th>Método</th>
                                <th>Estado</th>
                                <th style="width:170px;">Costo de envío (Bs)</th>
                                <th style="width:90px;">Validado</th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($info['orders'] as $o): ?>
                                <tr>
                                    <td>
                                        <a href="<?php echo esc_url($o['edit_url']); ?>" target="_blank" rel="noopener">#<?php echo esc_html($o['number']); ?></a>
                                    </td>
                                    <td><?php echo esc_html($o['method']); ?></td>
                                    <td><?php echo esc_html($o['status']); ?></td>
                                    <td>
                                        <?php if ($o['validated']): ?>
                                            <span class="fin-num-b"><?php echo esc_html(number_format($o['amount'], 2, '.', ',')); ?></span>
                                        <?php else: ?>
                                            <input type="number" step="0.01" min="0" class="fin-num"
                                                   name="amount[<?php echo (int) $o['id']; ?>]"
                                                   value="<?php echo esc_attr(number_format($o['amount'], 2, '.', '')); ?>"
                                                   style="width:150px;">
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($o['validated']): ?>
                                            <span class="fin-status-ok">✓ Sí</span>
                                        <?php else: ?>
                                            <span class="fin-status-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div><?php // /.fin-table-scroll ?>
                        <?php if ($pending > 0): ?>
                            <p class="fin-ship-day__actions">
                                <button type="submit" name="do" value="save" class="button">Guardar montos</button>
                                <button type="submit" name="do" value="validate" class="button button-primary"
                                    onclick="return confirm('¿Registrar el egreso de envío de los <?php echo $pending; ?> pedido(s) pendientes de este día? No se puede deshacer desde aquí (requiere anular el movimiento).');">
                                    Validar y registrar día
                                </button>
                            </p>
                        <?php else: ?>
                            <p class="fin-help" style="margin:8px 0 0;">Todos los pedidos de este día ya fueron validados.</p>
                        <?php endif; ?>
                    </form>
                </div>
            <?php endforeach; endif; ?>
        <?php endif; ?>
    </div><?php // /.fin-ship-panel ?>

    <?php // ════════ Panel IBEX (pago por MES) ════════ ?>
    <h2 style="margin-top:30px;">Pago de envío IBEX (por mes)</h2>
    <div class="fin-card fin-ship-panel">
        <?php if (!$ibex_configured): ?>
            <div class="notice notice-warning inline" style="margin:8px 0;"><p>
                Configura la cuenta, la categoría y el método de envío IBEX en
                <a href="<?php echo esc_url(FIN_Admin::tab_url('config')); ?>">Configuración</a>
                para usar este panel.
            </p></div>
        <?php else: ?>
            <p class="fin-help">
                Método(s) de pedidos: <strong><?php echo esc_html(implode(', ', $ibex_methods)); ?></strong>.
                IBEX cobra el envío de los <strong>pedidos</strong> y el de los <strong>traspasos</strong> de stock
                entre sucursales. Es una sola factura, así que se paga con <strong>un solo egreso</strong> por
                mes y sucursal: pedidos + traspasos.
                El botón <em>Registrar</em> no asienta nada: lleva al formulario de Movimientos con el pago cargado,
                para revisarlo y <strong>ajustar el monto a la factura real</strong> antes de confirmarlo.
                El egreso se fecha el <strong>día del pago</strong>, pero el Estado de Resultados lo carga al
                <strong>mes que se está pagando</strong> (es el mes que generó el costo).
                El costo de envío de cada pedido se administra en <strong>DEMV</strong>; el de cada traspaso, en la
                <strong>caja de Traspasos</strong>.
            </p>

            <?php if (!$ibex_tp_available): ?>
                <p class="fin-help">
                    <em>El plugin de Traspasos no está activo: el pago sale solo con el envío de los pedidos.</em>
                </p>
            <?php endif; ?>

            <form method="get" class="fin-filter-bar" style="margin:6px 0 14px;">
                <input type="hidden" name="page" value="<?php echo esc_attr(FIN_Admin::PAGE_SLUG); ?>">
                <input type="hidden" name="tab" value="envios">
                <label>Desde <input type="date" name="ibex_from" value="<?php echo esc_attr($ibex_from); ?>"></label>
                <label>Hasta <input type="date" name="ibex_to" value="<?php echo esc_attr($ibex_to); ?>"></label>
                <button type="submit" class="button">Filtrar</button>
            </form>

            <?php if (empty($ibex_view)): ?>
                <p><em>No hay pedidos ni traspasos con envío IBEX en el rango elegido.</em></p>
            <?php else: foreach ($ibex_view as $month => $info):
                $month_lbl  = wp_date('F Y', strtotime($month . '-01 12:00:00'));
                $is_legacy  = !empty($info['legacy']);
                $mes_total  = (float) $info['ped_total'] + (float) $info['tp_total'];
            ?>
                <div class="fin-ship-day">
                    <div class="fin-ship-day__head">
                        <strong style="text-transform:capitalize;"><?php echo esc_html($month_lbl); ?></strong>
                        <span class="fin-help">
                            <strong><?php echo (int) $info['ped_count']; ?></strong> pedido(s)
                            <?php echo esc_html(fin_money((float) $info['ped_total'])); ?>
                            <?php if ($ibex_tp_available): ?>
                                · <strong><?php echo (int) $info['tp_count']; ?></strong> traspaso(s)
                                <?php echo esc_html(fin_money((float) $info['tp_total'])); ?>
                            <?php endif; ?>
                            · factura del mes <strong><?php echo esc_html(fin_money($mes_total)); ?></strong>
                        </span>
                    </div>

                    <?php if ($is_legacy): ?>
                        <p class="fin-help" style="margin:8px 0 0;">
                            Este mes se pagó con el esquema anterior
                            (un solo egreso del mes<?php if ($info['legacy_amount'] !== null): ?>,
                            de <strong><?php echo esc_html(fin_money((float) $info['legacy_amount'])); ?></strong>
                            el <?php echo esc_html(mysql2date('d/m/Y', (string) $info['legacy_date'])); ?><?php endif; ?>).
                            Para re-dividirlo por sucursal, anula ese egreso en Registro de Movimientos.
                        </p>
                    <?php endif; ?>

                    <table class="widefat striped" style="margin-top:8px;">
                        <thead><tr>
                            <th>Sucursal</th>
                            <th style="width:150px;">Pedidos</th>
                            <th style="width:150px;">Traspasos</th>
                            <th style="width:150px;">A pagar</th>
                            <th style="width:320px;">Estado</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($info['sucursales'] as $suc => $s):
                            // UNA fila por sucursal: pedidos + traspasos = un solo egreso
                            // (una sola factura de IBEX, una sola salida de plata).
                            $mv      = $s['movement'];
                            $block   = (string) $s['block'];
                            $src_url = FIN_Admin::ibex_source_url($month, $suc);
                        ?>
                            <tr>
                                <td><strong><?php echo esc_html($suc); ?></strong></td>
                                <td>
                                    <?php echo (int) $s['ped_count']; ?> ·
                                    <?php echo esc_html(fin_money((float) $s['ped_total'])); ?>
                                </td>
                                <td>
                                    <?php if (!$ibex_tp_available): ?>
                                        <span class="fin-help">—</span>
                                    <?php else: ?>
                                        <?php echo (int) $s['tp_count']; ?> ·
                                        <?php echo esc_html(fin_money((float) $s['tp_total'])); ?>
                                        <?php if ((int) $s['tp_pending'] > 0): ?>
                                            <span class="fin-status-err">
                                                (<?php echo (int) $s['tp_pending']; ?> sin costo)
                                            </span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td><strong><?php echo esc_html(fin_money((float) $s['total'])); ?></strong></td>
                                <td>
                                    <?php if ($mv): ?>
                                        <span class="fin-status-ok">✓ pagado</span>
                                        (<?php echo esc_html(fin_money((float) $mv['amount'])); ?>
                                        el <?php echo esc_html(mysql2date('d/m/Y', (string) $mv['movement_date'])); ?>)
                                    <?php elseif ($block !== ''): ?>
                                        <span class="fin-status-err"><?php echo esc_html($block); ?></span>
                                    <?php else: ?>
                                        <a class="button button-primary button-small" href="<?php echo esc_url($src_url); ?>">
                                            Registrar en Movimientos
                                        </a>
                                        <span class="fin-help fin-help-block">
                                            Se abre el formulario con el pago cargado; ahí lo confirmás.
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; endif; ?>
        <?php endif; ?>
    </div><?php // /.fin-ship-panel IBEX ?>
</div>
