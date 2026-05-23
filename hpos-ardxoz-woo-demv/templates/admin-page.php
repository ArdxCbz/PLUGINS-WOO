<?php
/**
 * Template: Página de Gestión de Depósitos
 *
 * Variables disponibles:
 *   $shipping_methods  - array de títulos de métodos de envío (últimos 6 meses)
 *   $payment_methods   - array de objetos {payment_method, payment_method_title} (últimos 6 meses, unificados)
 *   $billing_states    - array de objetos {code, name}
 *   $sucursales        - array de strings (sucursales disponibles)
 *   $order_statuses    - array asociativo slug => label
 *   $current_year      - año actual
 */
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="hawd-wrap">

    <!-- ═══ ESTADÍSTICAS ═══ -->
    <div class="hawd-stats-card" id="hawd_stats_card">
        <div class="hawd-stats-header">
            <h3 class="hawd-stats-title">
                <span class="dashicons dashicons-chart-bar"></span>
                Estadísticas
                <span class="hawd-stats-count" id="hawd_stats_count">— pedidos</span>
            </h3>
        </div>

        <div class="hawd-stats-grid">
            <div class="hawd-stat-tile hawd-stat-deposit">
                <span class="hawd-stat-label">Total Depositado</span>
                <span class="hawd-stat-value" id="hawd_stat_depositado">—</span>
            </div>
            <div class="hawd-stat-tile hawd-stat-total">
                <span class="hawd-stat-label">Total</span>
                <span class="hawd-stat-value" id="hawd_stat_total">—</span>
            </div>
            <div class="hawd-stat-tile hawd-stat-shipping">
                <span class="hawd-stat-label">Total Costo de Envío</span>
                <span class="hawd-stat-value" id="hawd_stat_envio">—</span>
                <span class="hawd-stat-sub" id="hawd_stat_envio_sub"></span>
            </div>
            <div class="hawd-stat-tile hawd-stat-ibex"
                 title="7% retenido por IBEX en pedidos con envío IBEX y pago Contra Entrega">
                <span class="hawd-stat-label">7% IBEX no depositado</span>
                <span class="hawd-stat-value" id="hawd_stat_ibex">—</span>
                <span class="hawd-stat-sub" id="hawd_stat_ibex_sub">Retención de envío IBEX y pago Pago Contra Entrega</span>
            </div>
            <div class="hawd-stat-tile hawd-stat-diff"
                 title="Diferencia: monto depositado − importe esperado (con descuento 7% en IBEX-COD), solo pedidos con depósito registrado. Los top-ups en curso se reportan aparte.">
                <span class="hawd-stat-label">Diferencia</span>
                <span class="hawd-stat-value" id="hawd_stat_diff">—</span>
                <div class="hawd-stat-breakdown" id="hawd_stat_diff_breakdown"></div>
            </div>
        </div>
    </div>

    <!-- ═══ FILTROS ═══ -->
    <div class="hawd-filters-card">
        <div class="hawd-filters-row">
            <!-- Año -->
            <div class="hawd-filter-group">
                <label for="hawd_year">Año</label>
                <select id="hawd_year" class="hawd-filter">
                    <?php for ($y = $current_year; $y >= $current_year - 5; $y--): ?>
                        <option value="<?php echo $y; ?>" <?php selected($y, $current_year); ?>>
                            <?php echo $y; ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>

            <!-- Mes (multi-select con checkboxes) -->
            <div class="hawd-filter-group">
                <label>Mes</label>
                <div class="hawd-multi" data-name="month" data-placeholder="Todos">
                    <button type="button" class="hawd-multi-toggle">Todos</button>
                    <div class="hawd-multi-panel" hidden>
                        <label class="hawd-multi-all-row">
                            <input type="checkbox" class="hawd-multi-all" checked>
                            <strong>Todos</strong>
                        </label>
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <label>
                                <input type="checkbox" class="hawd-multi-opt" value="<?php echo $m; ?>" checked>
                                <?php echo esc_html(date_i18n('F', mktime(0, 0, 0, $m, 1))); ?>
                            </label>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>

            <!-- Estado (multi-select con checkboxes) -->
            <div class="hawd-filter-group">
                <label>Estado</label>
                <div class="hawd-multi" data-name="status" data-placeholder="Todos">
                    <button type="button" class="hawd-multi-toggle">Todos</button>
                    <div class="hawd-multi-panel" hidden>
                        <label class="hawd-multi-all-row">
                            <input type="checkbox" class="hawd-multi-all" checked>
                            <strong>Todos</strong>
                        </label>
                        <?php foreach ($order_statuses as $slug => $label): ?>
                            <label>
                                <input type="checkbox" class="hawd-multi-opt" value="<?php echo esc_attr($slug); ?>" checked>
                                <?php echo esc_html($label); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Sucursal (atributo de producto pa_sucursal) — Origen del traspaso -->
            <div class="hawd-filter-group">
                <label for="hawd_sucursal">Sucursal</label>
                <select id="hawd_sucursal" class="hawd-filter">
                    <option value="all">Todas</option>
                    <?php foreach ($sucursales as $suc): ?>
                        <option value="<?php echo esc_attr($suc); ?>">
                            <?php echo esc_html($suc); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Departamento (multi-select con checkboxes) — Destino del traspaso -->
            <div class="hawd-filter-group">
                <label>Depto.</label>
                <div class="hawd-multi" data-name="billing_state" data-placeholder="Todos">
                    <button type="button" class="hawd-multi-toggle">Todos</button>
                    <div class="hawd-multi-panel" hidden>
                        <label class="hawd-multi-all-row">
                            <input type="checkbox" class="hawd-multi-all" checked>
                            <strong>Todos</strong>
                        </label>
                        <?php foreach ($billing_states as $st): ?>
                            <label>
                                <input type="checkbox" class="hawd-multi-opt" value="<?php echo esc_attr($st->code); ?>" checked>
                                <?php echo esc_html($st->name); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Método de envío (multi-select con checkboxes) -->
            <div class="hawd-filter-group">
                <label>Envío</label>
                <div class="hawd-multi" data-name="shipping_method" data-placeholder="Todos">
                    <button type="button" class="hawd-multi-toggle">Todos</button>
                    <div class="hawd-multi-panel" hidden>
                        <label class="hawd-multi-all-row">
                            <input type="checkbox" class="hawd-multi-all" checked>
                            <strong>Todos</strong>
                        </label>
                        <?php foreach ($shipping_methods as $method): ?>
                            <label>
                                <input type="checkbox" class="hawd-multi-opt" value="<?php echo esc_attr($method); ?>" checked>
                                <?php echo esc_html($method); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Método de pago -->
            <div class="hawd-filter-group">
                <label for="hawd_payment">Pago</label>
                <select id="hawd_payment" class="hawd-filter">
                    <option value="all">Todos</option>
                    <?php foreach ($payment_methods as $pm): ?>
                        <option value="<?php echo esc_attr($pm->payment_method); ?>">
                            <?php echo esc_html($pm->payment_method_title); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Depósito (se carga por AJAX cuando año+mes están seleccionados) -->
            <div class="hawd-filter-group">
                <label for="hawd_deposit">Depósito</label>
                <select id="hawd_deposit" class="hawd-filter" disabled
                        title="Selecciona año, mes y departamento">
                    <option value="all">Todos</option>
                </select>
            </div>

            <!-- Buscar por número de depósito (substring) -->
            <div class="hawd-filter-group hawd-filter-search">
                <label for="hawd_deposit_search">Buscar Dep.</label>
                <input type="text" id="hawd_deposit_search" class="hawd-filter"
                       placeholder="Nº depósito" autocomplete="off">
            </div>

            <!-- Sin depósito -->
            <div class="hawd-filter-group hawd-filter-checkbox">
                <label for="hawd_no_deposit">
                    <input type="checkbox" id="hawd_no_deposit">
                    Sin Depósito
                </label>
            </div>

            <!-- Búsqueda general (multi-término separado por espacios) -->
            <div class="hawd-filter-group hawd-filter-search">
                <label for="hawd_search">Buscar</label>
                <input type="text" id="hawd_search" class="hawd-filter"
                       placeholder="# Pedido o Guía (separa con espacio)"
                       title="Puedes buscar varios números de pedido o guías separados por espacio"
                       autocomplete="off">
            </div>

            <!-- Botón filtrar -->
            <div class="hawd-filter-group hawd-filter-actions">
                <button id="hawd_btn_filter" class="button button-primary">
                    Filtrar
                </button>
            </div>
        </div>
    </div>

    <!-- ═══ BARRA DE ACCIONES ═══ -->
    <div class="hawd-actions-bar">
        <div class="hawd-actions-summary-row">
            <span id="hawd_summary" class="hawd-summary">Cargando...</span>
        </div>
        <div class="hawd-actions-buttons-row">
            <button id="hawd_btn_deposit" class="button button-primary" disabled>
                Completar Depósito
            </button>

            <button id="hawd_btn_pago_envio" class="button button-secondary" disabled>
                Pagar Envíos Seleccionados
            </button>

            <button id="hawd_btn_pago_envio_all" class="button button-secondary"
                    title="Registra fecha de pago de envío para todos los pedidos que cumplan los filtros activos">
                Pagar Envíos Filtrados
            </button>

            <button id="hawd_btn_marcar_revision" class="button button-secondary" disabled
                    title="Marcar como revisados solo los pedidos seleccionados">
                Marcar Revisión Seleccionados
            </button>

            <button id="hawd_btn_marcar_revision_all" class="button button-secondary"
                    title="Marca el campo de Arqueo como Ok para todos los pedidos que cumplan los filtros activos">
                Marcar Revisión Filtrados
            </button>

            <form id="hawd_csv_form" method="post"
                  action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                  style="display:inline;">
                <?php wp_nonce_field('hawd_export_csv', 'hawd_csv_nonce'); ?>
                <input type="hidden" name="action" value="hawd_export_csv">
                <input type="hidden" name="year" id="hawd_csv_year">
                <input type="hidden" name="payment_method" id="hawd_csv_payment">
                <input type="hidden" name="sucursal" id="hawd_csv_sucursal">
                <input type="hidden" name="deposit" id="hawd_csv_deposit">
                <input type="hidden" name="deposit_search" id="hawd_csv_deposit_search">
                <input type="hidden" name="no_deposit" id="hawd_csv_no_deposit">
                <input type="hidden" name="search" id="hawd_csv_search">
                <!-- Filtros multi-valor: rellenado dinámico desde JS (month[], status[], shipping_method[], billing_state[]) -->
                <span id="hawd_csv_multi_inputs"></span>
                <button type="submit" class="button button-secondary">
                    Exportar CSV
                </button>
            </form>
        </div>
    </div>

    <!-- ═══ TABLA DE RESULTADOS ═══ -->
    <div class="hawd-table-wrap">
        <table id="hawd_table" class="widefat fixed striped hawd-orders-table">
            <thead>
                <tr>
                    <th class="hawd-col-check">
                        <input type="checkbox" id="hawd_check_all" title="Seleccionar todos">
                    </th>
                    <th class="hawd-col-date">Fecha</th>
                    <th class="hawd-col-id"># Pedido</th>
                    <th class="hawd-col-postcode">Guía</th>
                    <th class="hawd-col-status">Estado</th>
                    <th class="hawd-col-payment">Pago</th>
                    <th class="hawd-col-state">Depto.</th>
                    <th class="hawd-col-fdeposit">F. Dep.</th>
                    <th class="hawd-col-ndeposit">Nro. Dep.</th>
                    <th class="hawd-col-total">Total</th>
                    <th class="hawd-col-mdeposit">Monto Dep.</th>
                    <th class="hawd-col-arqueo">Revisión</th>
                    <th class="hawd-col-return">F. Retorno</th>
                    <th class="hawd-col-shipping">Envío</th>
                    <th class="hawd-col-cost">C. Envío</th>
                    <th class="hawd-col-fpago">F. Pago Envío</th>
                </tr>
            </thead>
            <tbody id="hawd_tbody">
                <tr>
                    <td colspan="16" class="hawd-loading">
                        <span class="hawd-spinner"></span> Cargando pedidos...
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- ═══ PAGINACIÓN ═══ -->
    <div class="hawd-pagination" id="hawd_pagination"></div>

    <div style="height:16px"></div>

    <!-- ═══ MODAL: COMPLETAR DEPÓSITO ═══ -->
    <div id="hawd_overlay" class="hawd-overlay"></div>
    <div id="hawd_modal" class="hawd-modal">
        <div class="hawd-modal-header">
            <h3 id="hawd_m_title">Completar Depósito Bancario</h3>
            <button id="hawd_modal_close_x" class="hawd-modal-close">&times;</button>
        </div>

        <div class="hawd-modal-body">
            <div class="hawd-modal-fields">
                <div class="hawd-field">
                    <label for="hawd_m_fecha">Fecha de Depósito</label>
                    <input type="date" id="hawd_m_fecha" class="widefat">
                </div>
                <div class="hawd-field">
                    <label for="hawd_m_comprobante">Nº Comprobante</label>
                    <input type="text" id="hawd_m_comprobante" class="widefat"
                           placeholder="Número de comprobante bancario">
                </div>
                <div class="hawd-field hawd-field-monto">
                    <label for="hawd_m_monto_real">Monto real depositado (Bs)</label>
                    <input type="number" step="0.01" min="0" id="hawd_m_monto_real" class="widefat"
                           placeholder="0,00" inputmode="decimal" autocomplete="off">
                </div>
            </div>

            <div class="hawd-modal-totals">
                <span id="hawd_m_count">0 pedidos seleccionados</span>
                <span id="hawd_m_total" class="hawd-total-display">0,00 Bs</span>
            </div>

            <div class="hawd-modal-diff" id="hawd_m_diff_box">
                <span class="hawd-diff-line">
                    <span class="hawd-diff-label">Esperado:</span>
                    <span class="hawd-diff-val" id="hawd_m_esperado">0,00 Bs</span>
                </span>
                <span class="hawd-diff-sep">·</span>
                <span class="hawd-diff-line">
                    <span class="hawd-diff-label">Diferencia:</span>
                    <span class="hawd-diff-val" id="hawd_m_diff_val">0,00 Bs</span>
                </span>
            </div>

            <div class="hawd-field hawd-field-absorber" id="hawd_m_absorber_box" style="display:none;">
                <label for="hawd_m_absorber">Aplicar diferencia a guía</label>
                <select id="hawd_m_absorber" class="widefat"></select>
                <small class="hawd-absorber-hint" id="hawd_m_absorber_hint"></small>
            </div>

            <div class="hawd-modal-detail-wrap">
                <table class="hawd-modal-detail-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Pedido</th>
                            <th>Importe</th>
                        </tr>
                    </thead>
                    <tbody id="hawd_m_detail"></tbody>
                </table>
            </div>

            <div id="hawd_m_results" class="hawd-modal-results" style="display:none;"></div>
            <div id="hawd_m_progress" class="hawd-progress" style="display:none;"></div>
        </div>

        <div class="hawd-modal-footer">
            <button id="hawd_m_save" class="button button-primary">Guardar</button>
            <button id="hawd_m_cancel" class="button">Cancelar</button>
        </div>
    </div>
</div>
