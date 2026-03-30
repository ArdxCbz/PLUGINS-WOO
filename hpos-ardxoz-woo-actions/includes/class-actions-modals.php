<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Renderiza modales HTML en admin_footer para admin y vendedor.
 */
class HPOS_Ardxoz_Woo_Actions_Modals
{
    public static function init()
    {
        add_action('admin_footer', array(__CLASS__, 'render'));
    }

    public static function render()
    {
        if (!self::is_orders_screen()) {
            return;
        }

        // Modales admin
        if (current_user_can('administrator')) {
            self::render_admin_modals();
        }

        // Modales vendedor
        if (current_user_can('vendedor') || (current_user_can('administrator') && isset($_GET['simulate_vendedor']))) {
            self::render_vendedor_modals();
        }
    }

    private static function render_admin_modals()
    {
        ?>
        <!-- Modal Recibido (admin) -->
        <div id="hawa-modal-recibido" class="hawa-modal">
            <div class="hawa-modal-content">
                <span class="hawa-modal-close">&times;</span>
                <h3>Recibido</h3>
                <input type="hidden" name="order_id" value="">

                <label for="hawa_costo_envio">Costo de Envío:</label>
                <input id="hawa_costo_envio" name="costo_envio" type="text">

                <div id="hawa-recibido-guia-wrapper" style="display:none;">
                    <label for="hawa_recibido_postcode">Guía de Envío:</label>
                    <input id="hawa_recibido_postcode" name="recibido_postcode" type="text">
                </div>

                <label for="hawa_metodo_pago">Método de Pago:</label>
                <select id="hawa_metodo_pago" name="metodo_pago">
                    <?php
                    if (function_exists('WC') && WC()->payment_gateways()) {
                        foreach (WC()->payment_gateways()->payment_gateways() as $gw) {
                            if (isset($gw->enabled) && $gw->enabled === 'yes') {
                                echo '<option value="' . esc_attr($gw->id) . '">' . esc_html($gw->get_title()) . '</option>';
                            }
                        }
                    }
                    ?>
                </select>
                <div class="hawa-modal-footer">
                    <button id="hawa-save-recibido" class="button button-primary">Guardar</button>
                </div>
            </div>
        </div>

        <!-- Modal Retorno (admin) -->
        <div id="hawa-modal-retorno" class="hawa-modal">
            <div class="hawa-modal-content">
                <span class="hawa-modal-close">&times;</span>
                <h3>Retorno de Pedido</h3>
                <p style="font-size:12px; color:#666; margin-top:0;">El pedido será cancelado para devolver el stock al inventario.</p>
                <input type="hidden" name="order_id" value="">

                <label>Fecha de retorno:</label>
                <input type="date" name="fecha_retorno">

                <div class="hawa-modal-footer">
                    <button id="hawa-save-retorno" class="button button-primary">Guardar</button>
                </div>
            </div>
        </div>

        <!-- Modal En Curso (admin) -->
        <div id="hawa-modal-encurso" class="hawa-modal">
            <div class="hawa-modal-content">
                <span class="hawa-modal-close">&times;</span>
                <h3>Guía para: <span id="hawa-modal-customer-name" style="font-weight:bold; color:#2271b1;"></span></h3>
                <input type="hidden" name="order_id" value="">

                <label>Guía de Envío:</label>
                <input type="text" name="postcode">

                <label>Costo de Envío:</label>
                <input type="text" name="costo_courier" value="<?php echo esc_attr(HAWA_DEFAULT_COSTO_ENVIO); ?>">
                <div class="hawa-modal-footer">
                    <button id="hawa-save-encurso" class="button button-primary">Guardar</button>
                </div>
            </div>
        </div>

        <!-- Modal Cambiar Envío (admin) -->
        <div id="hawa-modal-cambiar-envio" class="hawa-modal">
            <div class="hawa-modal-content">
                <span class="hawa-modal-close">&times;</span>
                <h3>Cambiar Método de Envío</h3>
                <input type="hidden" name="order_id" value="">

                <label>Seleccionar Nuevo Método:</label>
                <select name="new_shipping_method" style="width:100%; margin-bottom:15px;">
                    <option value="IBEX">IBEX</option>
                    <option value="CBS">CBS</option>
                    <option value="SUECIA">SUECIA</option>
                    <option value="LOCAL">LOCAL</option>
                    <option value="ENCOMIENDA">ENCOMIENDA</option>
                </select>

                <div class="hawa-modal-footer">
                    <button id="hawa-save-cambiar-envio" class="button button-primary">Guardar</button>
                </div>
            </div>
        </div>
        <?php
    }

    private static function render_vendedor_modals()
    {
        ?>
        <!-- Modal Guía (vendedor) -->
        <div id="hawa-modal-guia" class="hawa-modal">
            <div class="hawa-modal-content">
                <span class="hawa-modal-close">&times;</span>
                <h3>Ingrese la Guía del Pedido</h3>
                <input type="hidden" id="hawa-guia-order-id">
                <input type="text" id="hawa-guia-postcode" placeholder="Nro de Guía">

                <label>Costo de Envío:</label>
                <input type="text" id="hawa-guia-costo" value="<?php echo esc_attr(HAWA_DEFAULT_COSTO_ENVIO); ?>">

                <div class="hawa-modal-footer">
                    <button id="hawa-save-guia" class="button button-primary">Guardar</button>
                </div>
            </div>
        </div>
        <?php
    }

    private static function is_orders_screen()
    {
        $screen = get_current_screen();
        if (!$screen) {
            return false;
        }
        return $screen->id === 'woocommerce_page_wc-orders' || $screen->id === 'edit-shop_order';
    }
}
