<?php
/**
 * Template: Caja inferior de Traspasos en la página de Depósitos.
 *
 * Reusa filtros existentes (año, mes, sucursal, método envío, búsqueda).
 * Dispone de su propia barra de acciones, tabla, modal y paginación.
 */
if (!defined('ABSPATH')) {
    exit;
}

$metodos_envio = class_exists('WC_TP_API') ? WC_TP_API::get_metodos_envio() : [];
?>

<div class="hawd-traspasos-card" id="hawd_traspasos_card">

    <div class="hawd-traspasos-header">
        <h3 class="hawd-traspasos-title">
            <span class="dashicons dashicons-randomize"></span>
            Traspasos entre sucursales
            <span class="hawd-traspasos-count" id="hawd_traspasos_count">— traspasos</span>
        </h3>

        <div class="hawd-traspasos-header-actions">
            <label class="hawd-tp-checkbox">
                <input type="checkbox" id="hawd_tp_sin_pago">
                Sin pago de envío
            </label>
        </div>
    </div>

    <div class="hawd-traspasos-actions-bar">
        <div class="hawd-actions-left">
            <span id="hawd_tp_summary" class="hawd-summary">Cargando...</span>
        </div>
        <div class="hawd-actions-right">
            <button id="hawd_tp_btn_pagar" class="button button-primary" disabled>
                Pagar Envío Traspasos
            </button>
        </div>
    </div>

    <div class="hawd-table-wrap">
        <table id="hawd_tp_table" class="widefat fixed striped hawd-orders-table hawd-traspasos-table">
            <thead>
                <tr>
                    <th class="hawd-col-check">
                        <input type="checkbox" id="hawd_tp_check_all" title="Seleccionar todos">
                    </th>
                    <th class="hawd-tp-col-date">Fecha</th>
                    <th class="hawd-tp-col-user">Usuario</th>
                    <th class="hawd-tp-col-route">Origen → Destino</th>
                    <th class="hawd-tp-col-guia">Guía</th>
                    <th class="hawd-tp-col-estado">Estado</th>
                    <th class="hawd-tp-col-metodo">Método Envío</th>
                    <th class="hawd-tp-col-cost">C. Envío</th>
                    <th class="hawd-tp-col-fpago">F. Pago Envío</th>
                </tr>
            </thead>
            <tbody id="hawd_tp_tbody">
                <tr>
                    <td colspan="9" class="hawd-loading">
                        <span class="hawd-spinner"></span> Cargando traspasos...
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="hawd-pagination" id="hawd_tp_pagination"></div>

    <!-- Datos hardcoded para el JS -->
    <script type="application/json" id="hawd_tp_metodos_envio">
        <?php echo wp_json_encode($metodos_envio); ?>
    </script>

    <!-- ═══ MODAL: PAGAR ENVÍO TRASPASOS ═══ -->
    <div id="hawd_tp_overlay" class="hawd-overlay"></div>
    <div id="hawd_tp_modal" class="hawd-modal">
        <div class="hawd-modal-header">
            <h3>Pagar Envío de Traspasos</h3>
            <button id="hawd_tp_modal_close_x" class="hawd-modal-close">&times;</button>
        </div>

        <div class="hawd-modal-body">
            <div class="hawd-modal-fields hawd-modal-fields-single">
                <div class="hawd-field">
                    <label for="hawd_tp_m_fecha">Fecha de Pago de Envío</label>
                    <input type="date" id="hawd_tp_m_fecha" class="widefat">
                </div>
            </div>

            <div class="hawd-modal-totals">
                <span id="hawd_tp_m_count">0 traspasos seleccionados</span>
                <span id="hawd_tp_m_total" class="hawd-total-display">0,00 Bs</span>
            </div>

            <div class="hawd-modal-detail-wrap">
                <table class="hawd-modal-detail-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Traspaso</th>
                            <th>Método</th>
                            <th>Costo Envío</th>
                        </tr>
                    </thead>
                    <tbody id="hawd_tp_m_detail"></tbody>
                </table>
            </div>

            <div id="hawd_tp_m_results" class="hawd-modal-results" style="display:none;"></div>
            <div id="hawd_tp_m_progress" class="hawd-progress" style="display:none;"></div>
        </div>

        <div class="hawd-modal-footer">
            <button id="hawd_tp_m_save" class="button button-primary">Confirmar Pago</button>
            <button id="hawd_tp_m_cancel" class="button">Cancelar</button>
        </div>
    </div>
</div>
