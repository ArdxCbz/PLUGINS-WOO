<?php
namespace HPOS\Ardxoz\Woo\Orders;

defined('ABSPATH') || exit;

class Info_Column
{
    public static function register()
    {
        add_filter('woocommerce_shop_order_list_table_columns', [__CLASS__, 'add_column'], 35);
        add_action('woocommerce_shop_order_list_table_custom_column', [__CLASS__, 'render_hpos'], 35, 2);

        // Modal HTML + JS en el footer del admin
        add_action('admin_footer', [__CLASS__, 'render_modal']);

        // Endpoint AJAX para cambiar método de envío
        add_action('wp_ajax_hawo_cambiar_metodo_envio', [__CLASS__, 'ajax_cambiar_metodo_envio']);
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

        echo '<div style="font-size: 13px; line-height: 1.5;">';

        // 1. Método de Envío con Estilo Visual
        $shipping_method = $order->get_shipping_method();

        if ($shipping_method) {
            $is_pickup = (stripos($shipping_method, 'Recogida') !== false || stripos($shipping_method, 'Recojo') !== false);

            echo '<div style="margin-bottom:6px;">';
            if ($is_pickup) {
                $bg_color = '#2980b9';
                $display_html = 'LOCAL';

                if (preg_match('/\((.*)\)/', $shipping_method, $matches)) {
                    $location = trim($matches[1]);
                    $display_html .= ' (' . esc_html($location) . ')';
                }
                echo '<strong>Recogida:</strong> ';
            } else {
                $bg_color = self::get_shipping_color($shipping_method);
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

        // Botón editar método de envío (solo admin)
        if (current_user_can('administrator')) {
            echo '<a href="#" class="hawo-editar-envio" data-order-id="' . esc_attr($order->get_id()) . '" data-current-method="' . esc_attr($shipping_method) . '" style="font-size:11px; text-decoration:none; color:#2271b1;">Cambiar Método</a>';
        }

        // 2. Número de Guía (con fallback ACF y shipping_postcode legacy)
        $tracking = Meta_Resolver::get($order, '_hpos_ardxoz_woo_numero_guia');
        if (!$tracking) {
            $tracking = $order->get_shipping_postcode();
        }

        // 3. Costo Envío (con fallback ACF: costo_courier)
        $courier_cost = Meta_Resolver::get($order, '_hpos_ardxoz_woo_costo_envio');

        // 4. Método de Pago
        $payment_method = $order->get_payment_method_title();

        if ($tracking) {
            echo '<div style="margin-bottom:4px;"><strong>Guía:</strong> <code style="background:#eef; padding:2px 4px; border-radius:3px;">' . esc_html($tracking) . '</code></div>';
        }

        if ($courier_cost) {
            $formatted_cost = get_woocommerce_currency_symbol() . number_format((float) $courier_cost, 2, ',', '.');
            echo '<div style="margin-bottom:4px;"><strong>Costo Envío:</strong> ' . $formatted_cost . '</div>';
        }

        if ($payment_method) {
            echo '<div style="margin-top:6px;"><strong>Forma de Pago:</strong> <span style="color:green; font-weight:bold;">' . esc_html($payment_method) . '</span></div>';
        }

        echo '</div>';
    }

    /**
     * Renderiza el modal y JS inline en el footer del admin.
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

        $nonce = wp_create_nonce('hawo_cambiar_envio');
        ?>
        <div id="hawo-modal-envio" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:100000; align-items:center; justify-content:center;">
            <div style="background:#fff; padding:20px 24px; border-radius:8px; min-width:320px; max-width:400px; box-shadow:0 4px 20px rgba(0,0,0,0.3);">
                <h3 style="margin-top:0;">Cambiar Método de Envío</h3>
                <input type="hidden" id="hawo-envio-order-id" value="">

                <label for="hawo-envio-select" style="display:block; margin-bottom:6px; font-weight:600;">Nuevo Método:</label>
                <select id="hawo-envio-select" style="width:100%; padding:6px; margin-bottom:16px;">
                    <option value="IBEX">IBEX</option>
                    <option value="CBS">CBS</option>
                    <option value="SUECIA">SUECIA</option>
                    <option value="LOCAL">LOCAL</option>
                    <option value="ENCOMIENDA">ENCOMIENDA</option>
                </select>

                <div style="text-align:right;">
                    <button id="hawo-envio-cancelar" class="button" style="margin-right:8px;">Cancelar</button>
                    <button id="hawo-envio-guardar" class="button button-primary">Guardar</button>
                </div>
            </div>
        </div>

        <script>
        (function(){
            var modal = document.getElementById('hawo-modal-envio');
            var orderIdInput = document.getElementById('hawo-envio-order-id');
            var select = document.getElementById('hawo-envio-select');
            var btnGuardar = document.getElementById('hawo-envio-guardar');
            var btnCancelar = document.getElementById('hawo-envio-cancelar');

            // Abrir modal
            document.addEventListener('click', function(e) {
                var link = e.target.closest('.hawo-editar-envio');
                if (!link) return;
                e.preventDefault();
                orderIdInput.value = link.dataset.orderId;
                var current = link.dataset.currentMethod || '';
                // Seleccionar el valor actual
                for (var i = 0; i < select.options.length; i++) {
                    if (select.options[i].value === current) {
                        select.selectedIndex = i;
                        break;
                    }
                }
                modal.style.display = 'flex';
            });

            // Cerrar modal
            btnCancelar.addEventListener('click', function() {
                modal.style.display = 'none';
            });
            modal.addEventListener('click', function(e) {
                if (e.target === modal) modal.style.display = 'none';
            });

            // Guardar
            btnGuardar.addEventListener('click', function() {
                var orderId = orderIdInput.value;
                var newMethod = select.value;
                btnGuardar.disabled = true;
                btnGuardar.textContent = 'Guardando...';

                var data = new FormData();
                data.append('action', 'hawo_cambiar_metodo_envio');
                data.append('security', '<?php echo esc_js($nonce); ?>');
                data.append('order_id', orderId);
                data.append('new_method', newMethod);

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
     * AJAX: Cambiar método de envío (compatible HPOS).
     */
    public static function ajax_cambiar_metodo_envio()
    {
        check_ajax_referer('hawo_cambiar_envio', 'security');

        if (!current_user_can('administrator') || empty($_POST['order_id']) || empty($_POST['new_method'])) {
            wp_send_json_error(['message' => 'No autorizado o datos incompletos']);
        }

        $order_id = intval($_POST['order_id']);
        $new_method_title = sanitize_text_field($_POST['new_method']);

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(['message' => 'Pedido no encontrado']);
        }

        $shipping_items = $order->get_items('shipping');
        if (!empty($shipping_items)) {
            foreach ($shipping_items as $item) {
                $item->set_method_title($new_method_title);
                $item->save();
            }
        } else {
            $item = new \WC_Order_Item_Shipping();
            $item->set_method_title($new_method_title);
            $order->add_item($item);
        }

        $order->save();
        wp_send_json_success(['message' => 'Método de envío actualizado']);
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
