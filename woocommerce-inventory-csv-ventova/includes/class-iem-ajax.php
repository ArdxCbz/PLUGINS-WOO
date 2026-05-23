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
        add_action('wp_ajax_iem_save_line',          [__CLASS__, 'save_line']);
        add_action('wp_ajax_iem_close_session',      [__CLASS__, 'close_session']);
        add_action('wp_ajax_iem_register_merma',     [__CLASS__, 'register_merma']);
        add_action('wp_ajax_iem_resolve_sku',        [__CLASS__, 'resolve_sku']);
        add_action('wp_ajax_iem_add_extra_line',     [__CLASS__, 'add_extra_line']);
        add_action('wp_ajax_iem_delete_extra_line',  [__CLASS__, 'delete_extra_line']);
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
        ]);
        if (is_wp_error($r)) {
            wp_send_json_error(['message' => $r->get_error_message()], 400);
        }
        wp_send_json_success(['id' => $r]);
    }
}
