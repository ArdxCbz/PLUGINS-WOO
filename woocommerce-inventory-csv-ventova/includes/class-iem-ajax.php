<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Endpoints AJAX (admin-only) para:
 *  - Autosave de una línea de conteo.
 *  - Cerrar una sesión de conteo.
 *  - Registrar una merma (variante AJAX, usada desde el modal de la pantalla
 *    principal).
 *
 * Todos los endpoints requieren cap manage_woocommerce + nonce.
 */
class IEM_Ajax
{
    const NONCE_ACTION = 'iem_ajax_action';
    const NONCE_FIELD  = '_iem_ajax_nonce';

    public static function init()
    {
        add_action('wp_ajax_iem_save_line',             [__CLASS__, 'save_line']);
        add_action('wp_ajax_iem_close_session',         [__CLASS__, 'close_session']);
        add_action('wp_ajax_iem_register_merma',        [__CLASS__, 'register_merma']);
        add_action('wp_ajax_iem_update_merma_notes',    [__CLASS__, 'update_merma_notes']);
        add_action('wp_ajax_iem_resolve_sku',           [__CLASS__, 'resolve_sku']);
        add_action('wp_ajax_iem_add_extra_line',        [__CLASS__, 'add_extra_line']);
        add_action('wp_ajax_iem_delete_extra_line',     [__CLASS__, 'delete_extra_line']);

        // v3.15: compras (rediseño 3.18 — referencia padres, no SKUs).
        // v3.19: creación lazy del draft (un solo paso en la UI).
        add_action('wp_ajax_iem_create_draft_purchase', [__CLASS__, 'create_draft_purchase']);
        add_action('wp_ajax_iem_add_purchase_line',     [__CLASS__, 'add_purchase_line']);
        add_action('wp_ajax_iem_save_purchase_line',    [__CLASS__, 'save_purchase_line']);
        add_action('wp_ajax_iem_delete_purchase_line',  [__CLASS__, 'delete_purchase_line']);

        // Gastos de importación de una compra (3.36+).
        add_action('wp_ajax_iem_add_purchase_expense',      [__CLASS__, 'add_purchase_expense']);
        add_action('wp_ajax_iem_validate_purchase_expense', [__CLASS__, 'validate_purchase_expense']);
        add_action('wp_ajax_iem_update_purchase_expense',   [__CLASS__, 'update_purchase_expense']);
        add_action('wp_ajax_iem_delete_purchase_expense',   [__CLASS__, 'delete_purchase_expense']);
    }

    /** Acceso AJAX administrativo: admin / shop_manager. */
    private static function verify_or_die()
    {
        if (!IEM_Permisos::can_admin()) {
            wp_send_json_error(['message' => 'Sin permisos.'], 403);
        }
        check_ajax_referer(self::NONCE_ACTION, self::NONCE_FIELD);
    }

    /**
     * Acceso AJAX a una sesión: admin pasa siempre; contador solo si la
     * sesión pertenece a SU sucursal asignada.
     *
     * Devuelve la fila de la sesión validada (para evitar refetch en el caller).
     */
    private static function verify_session_or_die($session_id)
    {
        check_ajax_referer(self::NONCE_ACTION, self::NONCE_FIELD);
        $sid = (int) $session_id;
        if ($sid <= 0) {
            wp_send_json_error(['message' => 'Sesión inválida.'], 400);
        }
        $session = IEM_Counts::get_session($sid);
        if (!$session) {
            wp_send_json_error(['message' => 'Sesión no encontrada.'], 404);
        }
        if (!IEM_Permisos::can_count_sucursal($session['sucursal_slug'])) {
            wp_send_json_error(['message' => 'Sin permisos sobre esta sesión.'], 403);
        }
        return $session;
    }

    public static function save_line()
    {
        $session_id = isset($_POST['session_id']) ? (int) $_POST['session_id'] : 0;
        self::verify_session_or_die($session_id);

        $line_id = isset($_POST['line_id']) ? (int) $_POST['line_id'] : 0;
        $qty     = isset($_POST['qty'])     ? sanitize_text_field((string) wp_unslash($_POST['qty'])) : '';

        $r = IEM_Counts::save_line_by_id($session_id, $line_id, $qty);
        if (is_wp_error($r)) {
            wp_send_json_error(['message' => $r->get_error_message()], 400);
        }
        wp_send_json_success($r);
    }

    public static function add_extra_line()
    {
        $session_id = isset($_POST['session_id']) ? (int) $_POST['session_id'] : 0;
        self::verify_session_or_die($session_id);

        $r = IEM_Counts::add_extra_line($session_id, [
            'name'  => isset($_POST['name'])  ? sanitize_text_field((string) wp_unslash($_POST['name']))  : '',
            'sku'   => isset($_POST['sku'])   ? sanitize_text_field((string) wp_unslash($_POST['sku']))   : '',
            'qty'   => isset($_POST['qty'])   ? sanitize_text_field((string) wp_unslash($_POST['qty']))   : '',
            'notes' => isset($_POST['notes']) ? sanitize_textarea_field((string) wp_unslash($_POST['notes'])) : '',
        ]);
        if (is_wp_error($r)) {
            wp_send_json_error(['message' => $r->get_error_message()], 400);
        }
        wp_send_json_success(['line' => $r]);
    }

    public static function delete_extra_line()
    {
        $session_id = isset($_POST['session_id']) ? (int) $_POST['session_id'] : 0;
        self::verify_session_or_die($session_id);

        $line_id = isset($_POST['line_id']) ? (int) $_POST['line_id'] : 0;
        $r = IEM_Counts::delete_extra_line($session_id, $line_id);
        if (is_wp_error($r)) {
            wp_send_json_error(['message' => $r->get_error_message()], 400);
        }
        wp_send_json_success(['deleted' => true]);
    }

    public static function close_session()
    {
        $session_id = isset($_POST['session_id']) ? (int) $_POST['session_id'] : 0;
        self::verify_session_or_die($session_id);

        $r = IEM_Counts::close($session_id, get_current_user_id());
        if (is_wp_error($r)) {
            wp_send_json_error(['message' => $r->get_error_message()], 400);
        }
        wp_send_json_success(['closed' => true]);
    }

    /**
     * Resuelve un SKU contra las reglas de merma (existe, no es variable padre).
     * Útil para validar antes de submit en el form de mermas.
     */
    public static function resolve_sku()
    {
        self::verify_or_die();
        $sku = isset($_POST['sku']) ? sanitize_text_field((string) wp_unslash($_POST['sku'])) : '';
        $r   = IEM_Mermas::resolve_for_merma($sku);
        if (is_wp_error($r)) {
            wp_send_json_error([
                'code'    => $r->get_error_code(),
                'message' => $r->get_error_message(),
            ], 200); // 200 con ok=false; la UI maneja el mensaje, no es un fallo de transporte.
        }
        wp_send_json_success($r);
    }

    // ── v3.15: endpoints de compras (rediseño 3.18 / 3.19) ────────────────

    /**
     * Crea un borrador de compra (lazy, 3.19+). Usado por el form unificado
     * cuando el usuario hace click en "Agregar producto" por primera vez:
     * no hay reload entre cabecera y líneas. Devuelve el id+code+status del
     * draft creado para que el JS actualice el hidden y la URL del navegador.
     */
    public static function create_draft_purchase()
    {
        self::verify_or_die();
        $r = IEM_Purchases::create_draft([
            'code'            => isset($_POST['code'])            ? (string) wp_unslash($_POST['code'])            : '',
            'supplier_id'     => isset($_POST['supplier_id'])     ? (int) $_POST['supplier_id']                    : 0,
            'sucursal_slug'   => isset($_POST['sucursal_slug'])   ? (string) wp_unslash($_POST['sucursal_slug'])   : '',
            'purchase_date'   => isset($_POST['purchase_date'])   ? (string) wp_unslash($_POST['purchase_date'])   : '',
            'invoice_number'  => isset($_POST['invoice_number'])  ? (string) wp_unslash($_POST['invoice_number'])  : '',
            'package_count'   => isset($_POST['package_count'])   ? (int) $_POST['package_count']                  : 0,
            'gross_weight_kg' => isset($_POST['gross_weight_kg']) ? (float) $_POST['gross_weight_kg']              : 0,
            'cbm_m3'          => isset($_POST['cbm_m3'])          ? (float) $_POST['cbm_m3']                       : 0,
            'shipping_via'    => isset($_POST['shipping_via'])    ? (string) wp_unslash($_POST['shipping_via'])    : '',
            'tracking_number' => isset($_POST['tracking_number']) ? (string) wp_unslash($_POST['tracking_number']) : '',
            'eta_date'        => isset($_POST['eta_date'])        ? (string) wp_unslash($_POST['eta_date'])        : '',
            'notes'           => isset($_POST['notes'])           ? (string) wp_unslash($_POST['notes'])           : '',
        ]);
        if (is_wp_error($r)) {
            wp_send_json_error(['message' => $r->get_error_message()], 400);
        }
        $id  = (int) $r;
        $row = IEM_Purchases::get($id);
        wp_send_json_success([
            'id'     => $id,
            'code'   => $row['code']   ?? '',
            'status' => $row['status'] ?? 'draft',
        ]);
    }

    /**
     * Agrega una línea a una compra en draft. La selección de producto se
     * hace en el cliente con el Select2 `wc-product-search` de WooCommerce,
     * que devuelve un `product_id`. Aquí se valida server-side que sea un
     * padre (simple o variable) vía `IEM_Purchases::resolve_for_purchase`.
     */
    public static function add_purchase_line()
    {
        self::verify_or_die();
        $purchase_id = isset($_POST['purchase_id']) ? (int) $_POST['purchase_id'] : 0;
        $args = [
            'product_id'  => isset($_POST['product_id'])  ? (int) $_POST['product_id']  : 0,
            'qty'         => isset($_POST['qty'])         ? (int)   $_POST['qty']        : 0,
            'unit_cost'   => isset($_POST['unit_cost'])   ? (float) $_POST['unit_cost']  : 0,
            // 2.1+: proveedor por línea. Si no viene, add_line() cae al de cabecera.
            'supplier_id' => isset($_POST['supplier_id']) ? (int) $_POST['supplier_id'] : 0,
        ];
        // origin_cost es opcional: si no viene, add_line() siembra la sugerencia
        // del producto (meta `costo_de_origen` actual).
        if (isset($_POST['origin_cost'])) {
            $args['origin_cost'] = (float) $_POST['origin_cost'];
        }
        $r = IEM_Purchases::add_line($purchase_id, $args);
        if (is_wp_error($r)) {
            wp_send_json_error(['message' => $r->get_error_message()], 400);
        }
        // Adjuntar el total recalculado de la compra para que la UI lo refleje.
        $row    = IEM_Purchases::get($purchase_id);
        $total  = $row ? (float) $row['total'] : 0;
        wp_send_json_success(['line' => $r, 'purchase_total' => $total]);
    }

    public static function save_purchase_line()
    {
        self::verify_or_die();
        $line_id     = isset($_POST['line_id'])     ? (int)   $_POST['line_id']     : 0;
        $qty         = isset($_POST['qty'])         ? (int)   $_POST['qty']         : 0;
        $unit_cost   = isset($_POST['unit_cost'])   ? (float) $_POST['unit_cost']   : 0;
        // null = no tocar el costo de origen (compatibilidad con clientes viejos).
        $origin_cost = isset($_POST['origin_cost']) ? (float) $_POST['origin_cost'] : null;
        // null = no tocar el proveedor de la línea.
        $supplier_id = isset($_POST['supplier_id']) ? (int)   $_POST['supplier_id'] : null;
        $r = IEM_Purchases::save_line($line_id, $qty, $unit_cost, $origin_cost, $supplier_id);
        if (is_wp_error($r)) {
            wp_send_json_error(['message' => $r->get_error_message()], 400);
        }
        wp_send_json_success($r);
    }

    public static function delete_purchase_line()
    {
        self::verify_or_die();
        $line_id = isset($_POST['line_id']) ? (int) $_POST['line_id'] : 0;
        $r = IEM_Purchases::delete_line($line_id);
        if (is_wp_error($r)) {
            wp_send_json_error(['message' => $r->get_error_message()], 400);
        }
        wp_send_json_success($r);
    }

    // ── Gastos de importación de la compra (3.36+) ─────────────────────────

    /** Totales (informativos) de una compra —facturados o no—, junto a cada operación. */
    private static function expense_recon($purchase_id)
    {
        return [
            'totals' => IEM_Import_Costs::expense_grand_totals((int) $purchase_id),
        ];
    }

    public static function add_purchase_expense()
    {
        self::verify_or_die();
        $pid     = isset($_POST['purchase_id']) ? (int) $_POST['purchase_id'] : 0;
        $type_id = isset($_POST['type_id'])     ? (int) $_POST['type_id']     : 0;
        $acc     = isset($_POST['account_id'])  ? (int) $_POST['account_id']  : 0;
        $bob     = isset($_POST['amount_bob'])  ? (float) $_POST['amount_bob'] : 0;
        $usd     = isset($_POST['amount_usd'])  ? (float) $_POST['amount_usd'] : 0;
        $tc      = isset($_POST['tc'])          ? (float) $_POST['tc']         : 0;
        $r = IEM_Import_Costs::attach($pid, $type_id, $acc, $bob, $usd, $tc);
        if (is_wp_error($r)) {
            wp_send_json_error(['message' => $r->get_error_message()], 400);
        }
        wp_send_json_success(['expense' => $r, 'recon' => self::expense_recon($pid)]);
    }

    public static function validate_purchase_expense()
    {
        self::verify_or_die();
        $eid = isset($_POST['expense_id']) ? (int) $_POST['expense_id'] : 0;
        $r = IEM_Import_Costs::validate($eid);
        if (is_wp_error($r)) {
            wp_send_json_error(['message' => $r->get_error_message()], 400);
        }
        wp_send_json_success(['expense' => $r, 'recon' => self::expense_recon((int) $r['purchase_id'])]);
    }

    public static function update_purchase_expense()
    {
        self::verify_or_die();
        $eid    = isset($_POST['expense_id']) ? (int) $_POST['expense_id'] : 0;
        $acc    = isset($_POST['account_id']) ? (int) $_POST['account_id'] : 0;
        $bob    = isset($_POST['amount_bob']) ? (float) $_POST['amount_bob'] : 0;
        $usd    = isset($_POST['amount_usd']) ? (float) $_POST['amount_usd'] : 0;
        $tc     = isset($_POST['tc'])         ? (float) $_POST['tc']         : 0;
        $r = IEM_Import_Costs::update_expense($eid, $acc, $bob, $usd, $tc);
        if (is_wp_error($r)) {
            wp_send_json_error(['message' => $r->get_error_message()], 400);
        }
        wp_send_json_success(['expense' => $r, 'recon' => self::expense_recon((int) $r['purchase_id'])]);
    }

    public static function delete_purchase_expense()
    {
        self::verify_or_die();
        $eid = isset($_POST['expense_id']) ? (int) $_POST['expense_id'] : 0;
        // Necesitamos el purchase_id antes de borrar para el recon de respuesta.
        global $wpdb;
        $t   = IEM_Schema::table('purchase_expenses');
        $pid = (int) $wpdb->get_var($wpdb->prepare("SELECT purchase_id FROM $t WHERE id = %d", $eid));
        $r = IEM_Import_Costs::delete_expense($eid);
        if (is_wp_error($r)) {
            wp_send_json_error(['message' => $r->get_error_message()], 400);
        }
        wp_send_json_success(['recon' => self::expense_recon($pid)]);
    }

    public static function register_merma()
    {
        self::verify_or_die();
        $r = IEM_Mermas::register([
            'item_id'       => isset($_POST['item_id'])       ? (int) $_POST['item_id']       : 0,
            'sucursal_slug' => isset($_POST['sucursal_slug']) ? sanitize_text_field((string) wp_unslash($_POST['sucursal_slug'])) : '',
            'qty'           => isset($_POST['qty'])           ? (int) $_POST['qty']           : 0,
            'tipo'          => isset($_POST['tipo'])          ? sanitize_text_field((string) wp_unslash($_POST['tipo'])) : '',
            'session_id'    => !empty($_POST['session_id'])   ? (int) $_POST['session_id']    : null,
            'decrement_wc'  => !empty($_POST['decrement_wc']),
            'notes'         => isset($_POST['notes'])         ? sanitize_textarea_field((string) wp_unslash($_POST['notes'])) : '',
        ]);
        if (is_wp_error($r)) {
            wp_send_json_error(['message' => $r->get_error_message()], 400);
        }
        wp_send_json_success(['id' => $r]);
    }

    /** Edita la nota (defecto) de una merma existente. */
    public static function update_merma_notes()
    {
        self::verify_or_die();
        $id    = isset($_POST['merma_id']) ? (int) $_POST['merma_id'] : 0;
        $notes = isset($_POST['notes']) ? sanitize_textarea_field((string) wp_unslash($_POST['notes'])) : '';
        $r = IEM_Mermas::update_notes($id, $notes);
        if (is_wp_error($r)) {
            wp_send_json_error(['message' => $r->get_error_message()], 400);
        }
        wp_send_json_success(['id' => $id, 'notes' => $notes]);
    }
}
