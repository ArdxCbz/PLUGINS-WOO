<?php
namespace HPOS\Ardxoz\Woo\Orders;

defined('ABSPATH') || exit;

class Info_Column
{
    public static function register()
    {
        add_filter('woocommerce_shop_order_list_table_columns', [__CLASS__, 'add_column'], 35);
        add_action('woocommerce_shop_order_list_table_custom_column', [__CLASS__, 'render_hpos'], 35, 2);

        // Modal + JS de edición en el footer del admin.
        add_action('admin_footer', [__CLASS__, 'render_modal']);

        // Endpoint AJAX único: guarda todos los campos de la columna de una vez.
        add_action('wp_ajax_hawo_guardar_info', [__CLASS__, 'ajax_guardar_info']);
    }

    public static function add_column($columns)
    {
        $new_columns = [];
        foreach ($columns as $key => $column) {
            $new_columns[$key] = $column;
            if ($key === 'haw_order') {
                $new_columns['order_info'] = 'Información';
            }
        }
        return $new_columns;
    }

    public static function render_hpos($column, $order)
    {
        if ($column !== 'order_info') {
            return;
        }

        $is_admin = current_user_can('administrator');

        // Datos almacenados.
        $shipping_method = $order->get_shipping_method();
        $courier_cost    = Meta_Resolver::get($order, '_hpos_ardxoz_woo_costo_envio');
        $payment_id      = $order->get_payment_method();
        $payment_title   = $order->get_payment_method_title();
        $customer_note   = $order->get_customer_note();
        // Guía: principal en shipping_postcode (lo que busca Depósitos Express),
        // con fallback a la meta del courier _hpos_ardxoz_woo_numero_guia.
        $tracking = $order->get_shipping_postcode();
        if ($tracking === '' || $tracking === null) {
            $tracking = Meta_Resolver::get($order, '_hpos_ardxoz_woo_numero_guia');
        }

        // El contenedor lleva los datos en data-* para poblar el modal sin un AJAX extra.
        $attrs = '';
        if ($is_admin) {
            $cost_val = ($courier_cost !== '' && $courier_cost !== null) ? (float) $courier_cost : '';
            $attrs = ' data-order-id="' . esc_attr($order->get_id()) . '"'
                   . ' data-order-number="' . esc_attr($order->get_order_number()) . '"'
                   . ' data-envio="' . esc_attr($shipping_method) . '"'
                   . ' data-costo="' . esc_attr($cost_val) . '"'
                   . ' data-pago="' . esc_attr($payment_id) . '"'
                   . ' data-pago-title="' . esc_attr($payment_title) . '"'
                   . ' data-guia="' . esc_attr($tracking) . '"'
                   . ' data-notas="' . esc_attr($customer_note) . '"';
        }

        echo '<div class="hawo-info"' . $attrs . ($is_admin ? ' style="cursor:pointer;" title="Click para editar"' : '') . '>';
        self::render_display($shipping_method, $courier_cost, $payment_title, $customer_note);
        if ($is_admin) {
            echo '<div style="font-size:10px; color:#999; margin-top:4px;">✎ Click para editar</div>';
        }
        echo '</div>';
    }

    /**
     * Render de solo-lectura de los datos de la columna.
     */
    private static function render_display($shipping_method, $courier_cost, $payment_title, $customer_note)
    {
        echo '<div style="font-size: 13px; line-height: 1.5;">';

        // 1. Método de Envío con estilo visual.
        if ($shipping_method) {
            $is_pickup = (stripos($shipping_method, 'Recogida') !== false || stripos($shipping_method, 'Recojo') !== false);

            echo '<div style="margin-bottom:6px;">';
            if ($is_pickup) {
                $bg_color     = '#2980b9';
                $display_html = 'LOCAL';
                if (preg_match('/\((.*)\)/', $shipping_method, $matches)) {
                    $display_html .= ' (' . esc_html(trim($matches[1])) . ')';
                }
                echo '<strong>Recogida:</strong> ';
            } else {
                $bg_color     = self::get_shipping_color($shipping_method);
                $display_html = esc_html($shipping_method);
                echo '<strong>Forma de Envío:</strong> ';
            }

            echo sprintf(
                '<span style="background-color:%s; color:#fff; padding:3px 8px; border-radius:4px; font-weight:bold; font-size:11px; display:inline-block; text-transform:uppercase; line-height:1.1; text-align:center;">%s</span>',
                $bg_color,
                $display_html
            );
            echo '</div>';
        }

        // 2. Costo Envío.
        $has_cost = ($courier_cost !== '' && $courier_cost !== null);
        $formatted_cost = $has_cost
            ? get_woocommerce_currency_symbol() . number_format((float) $courier_cost, 2, ',', '.')
            : '<span style="color:#d63638; font-weight:bold;">-</span>';
        echo '<div style="margin-bottom:4px;"><strong>Costo Envío:</strong> ' . $formatted_cost . '</div>';

        // 3. Forma de Pago (solo si existe).
        if ($payment_title) {
            echo '<div style="margin-top:6px;"><strong>Forma de Pago:</strong> <span style="color:green; font-weight:bold;">' . esc_html($payment_title) . '</span></div>';
        }

        // 4. Notas del Cliente (solo si el pedido las tiene).
        if ($customer_note !== '') {
            echo '<div style="margin-top:6px;"><strong>Notas del Cliente:</strong> <span style="color:#555;">' . nl2br(esc_html($customer_note)) . '</span></div>';
        }

        echo '</div>';
    }

    /**
     * Modal único de edición (todos los campos) + JS delegado, en el footer del admin.
     */
    public static function render_modal()
    {
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'woocommerce_page_wc-orders') {
            return;
        }
        if (!current_user_can('administrator')) {
            return;
        }

        $nonce   = wp_create_nonce('hawo_info');
        $methods = ['IBEX', 'CBS', 'SUECIA', 'LOCAL', 'ENCOMIENDA'];
        ?>
        <div id="hawo-modal-info" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:100000; align-items:center; justify-content:center;">
            <div style="background:#fff; padding:20px 24px; border-radius:8px; min-width:340px; max-width:420px; box-shadow:0 4px 20px rgba(0,0,0,0.3);">
                <h3 style="margin-top:0;">Editar pedido #<span id="hawo-info-num"></span></h3>
                <input type="hidden" id="hawo-info-order-id" value="">

                <label style="display:block; font-weight:600; margin-bottom:2px;">Guía (Nº de seguimiento):</label>
                <input type="text" id="hawo-info-guia" maxlength="30" style="width:100%; padding:6px; margin-bottom:12px;" placeholder="Número de guía del courier">

                <label style="display:block; font-weight:600; margin-bottom:2px;">Forma de Envío:</label>
                <select id="hawo-info-envio" style="width:100%; padding:6px; margin-bottom:12px;">
                    <?php foreach ($methods as $m) {
                        echo '<option value="' . esc_attr($m) . '">' . esc_html($m) . '</option>';
                    } ?>
                </select>

                <label style="display:block; font-weight:600; margin-bottom:2px;">Costo de Envío:</label>
                <input type="number" step="0.01" min="0" id="hawo-info-costo" style="width:100%; padding:6px; margin-bottom:12px;">

                <label style="display:block; font-weight:600; margin-bottom:2px;">Forma de Pago:</label>
                <select id="hawo-info-pago" style="width:100%; padding:6px; margin-bottom:12px;">
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

                <label style="display:block; font-weight:600; margin-bottom:2px;">Notas del Cliente:</label>
                <textarea id="hawo-info-notas" rows="3" style="width:100%; padding:6px; margin-bottom:16px;"></textarea>

                <div style="text-align:right;">
                    <button id="hawo-info-cancelar" class="button" style="margin-right:8px;">Cancelar</button>
                    <button id="hawo-info-guardar" class="button button-primary">Guardar</button>
                </div>
            </div>
        </div>

        <script>
        (function(){
            var infoNonce = '<?php echo esc_js($nonce); ?>';
            var modal   = document.getElementById('hawo-modal-info');
            var elNum   = document.getElementById('hawo-info-num');
            var elId    = document.getElementById('hawo-info-order-id');
            var elGuia  = document.getElementById('hawo-info-guia');
            var elEnvio = document.getElementById('hawo-info-envio');
            var elCosto = document.getElementById('hawo-info-costo');
            var elPago  = document.getElementById('hawo-info-pago');
            var elNotas = document.getElementById('hawo-info-notas');
            var btnGuardar  = document.getElementById('hawo-info-guardar');
            var btnCancelar = document.getElementById('hawo-info-cancelar');

            function selectByContains(sel, raw) {
                var up = (raw || '').toUpperCase();
                for (var i = 0; i < sel.options.length; i++) {
                    if (up.indexOf(sel.options[i].value.toUpperCase()) !== -1) { sel.selectedIndex = i; return; }
                }
                sel.selectedIndex = 0;
            }
            function selectByValue(sel, val, fallbackLabel) {
                for (var i = 0; i < sel.options.length; i++) {
                    if (sel.options[i].value === val) { sel.selectedIndex = i; return; }
                }
                // La pasarela actual no está habilitada: la agrego para no perderla.
                if (val) {
                    var opt = document.createElement('option');
                    opt.value = val;
                    opt.textContent = (fallbackLabel || val) + ' (actual)';
                    opt.selected = true;
                    sel.appendChild(opt);
                }
            }
            function closeModal() { modal.style.display = 'none'; }

            // Abrir modal al hacer click en la celda.
            document.addEventListener('click', function(e) {
                var cell = e.target.closest('.hawo-info');
                if (!cell || !cell.dataset.orderId) return;
                e.preventDefault();

                elId.value    = cell.dataset.orderId;
                elNum.textContent = cell.dataset.orderNumber || cell.dataset.orderId;
                elGuia.value  = cell.dataset.guia || '';
                elCosto.value = cell.dataset.costo || '';
                elNotas.value = cell.dataset.notas || '';
                selectByContains(elEnvio, cell.dataset.envio);
                selectByValue(elPago, cell.dataset.pago || '', cell.dataset.pagoTitle);

                modal.style.display = 'flex';
            });

            btnCancelar.addEventListener('click', closeModal);
            modal.addEventListener('click', function(e) { if (e.target === modal) closeModal(); });

            btnGuardar.addEventListener('click', function() {
                btnGuardar.disabled = true;
                btnGuardar.textContent = 'Guardando…';

                var data = new FormData();
                data.append('action', 'hawo_guardar_info');
                data.append('security', infoNonce);
                data.append('order_id', elId.value);
                data.append('guia', elGuia.value);
                data.append('metodo_envio', elEnvio.value);
                data.append('costo', elCosto.value);
                data.append('forma_pago', elPago.value);
                data.append('notas', elNotas.value);

                fetch(ajaxurl, { method: 'POST', body: data })
                    .then(function(r) { return r.json(); })
                    .then(function(resp) {
                        if (resp.success) {
                            location.reload();
                        } else {
                            window.alert(resp.data && resp.data.message ? resp.data.message : 'Error al guardar');
                            btnGuardar.disabled = false;
                            btnGuardar.textContent = 'Guardar';
                        }
                    })
                    .catch(function() {
                        window.alert('Error de conexión');
                        btnGuardar.disabled = false;
                        btnGuardar.textContent = 'Guardar';
                    });
            });
        })();
        </script>
        <?php
    }

    /**
     * AJAX: guarda todos los campos de la columna Información (compatible HPOS).
     */
    public static function ajax_guardar_info()
    {
        check_ajax_referer('hawo_info', 'security');

        if (!current_user_can('administrator') || empty($_POST['order_id'])) {
            wp_send_json_error(['message' => 'No autorizado o datos incompletos']);
        }

        $order = wc_get_order(intval($_POST['order_id']));
        if (!$order) {
            wp_send_json_error(['message' => 'Pedido no encontrado']);
        }

        // 1. Forma de envío → method_title del/los items de shipping (o crea uno).
        if (isset($_POST['metodo_envio']) && $_POST['metodo_envio'] !== '') {
            $new_method = sanitize_text_field(wp_unslash($_POST['metodo_envio']));
            $shipping_items = $order->get_items('shipping');
            if (!empty($shipping_items)) {
                foreach ($shipping_items as $item) {
                    $item->set_method_title($new_method);
                    $item->save();
                }
            } else {
                $item = new \WC_Order_Item_Shipping();
                $item->set_method_title($new_method);
                $order->add_item($item);
            }
        }

        // 2. Costo de envío.
        if (isset($_POST['costo'])) {
            $costo = wc_format_decimal(wp_unslash($_POST['costo']));
            $order->update_meta_data('_hpos_ardxoz_woo_costo_envio', (float) $costo);
        }

        // 3. Forma de pago: solo si es una pasarela HABILITADA (si no, se ignora).
        if (isset($_POST['forma_pago']) && $_POST['forma_pago'] !== '') {
            $gw_id    = sanitize_text_field(wp_unslash($_POST['forma_pago']));
            $gateways = (function_exists('WC') && WC()->payment_gateways()) ? WC()->payment_gateways()->payment_gateways() : [];
            if (isset($gateways[$gw_id]) && $gateways[$gw_id]->enabled === 'yes') {
                $order->set_payment_method($gw_id);
                $order->set_payment_method_title($gateways[$gw_id]->get_title());
            }
        }

        // 4. Notas del cliente.
        if (isset($_POST['notas'])) {
            $order->set_customer_note(sanitize_textarea_field(wp_unslash($_POST['notas'])));
        }

        // 5. Guía → shipping_postcode (campo PRINCIPAL: es lo que busca Depósitos
        //    Express). Se escribe tal cual el valor del modal, que viene precargado
        //    desde el postcode actual: un guardado sin tocar la guía es no-op. NO se
        //    escribe _hpos_ardxoz_woo_numero_guia (queda solo como fallback de lectura).
        if (isset($_POST['guia'])) {
            $order->set_shipping_postcode(sanitize_text_field(wp_unslash($_POST['guia'])));
        }

        $order->save();
        wp_send_json_success(['message' => 'Información actualizada']);
    }

    private static function get_shipping_color($method)
    {
        $method_upper = strtoupper($method);

        if (strpos($method_upper, 'IBEX') !== false) {
            return '#8B4513'; // Marrón
        }
        if (strpos($method_upper, 'SUECIA') !== false) {
            return '#3498db'; // Celeste
        }
        if (strpos($method_upper, 'CBS') !== false) {
            return '#2ecc71'; // Verde
        }
        if (strpos($method_upper, 'ENCOMIENDA') !== false) {
            return '#9b59b6'; // Lila
        }

        return '#95a5a6'; // Gris por defecto
    }
}
