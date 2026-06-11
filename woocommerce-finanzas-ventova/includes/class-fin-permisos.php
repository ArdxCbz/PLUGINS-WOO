<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Permisos del plugin de Finanzas (v1.0).
 *
 * Finanzas es solo-admin: "Admin" = administrator o shop_manager. No hay
 * roles parciales (a diferencia del "contador" de Inventario): la tesorería
 * y la contabilidad son sensibles y se restringen al equipo de administración.
 *
 * Modelado sobre IEM_Permisos::is_admin() / can_admin().
 */
class FIN_Permisos
{
    private static function get_roles($user_id = null)
    {
        if ($user_id) {
            $u = get_userdata((int) $user_id);
        } else {
            $u = wp_get_current_user();
            if (!$u || !$u->ID) {
                $u = null;
            }
        }
        return $u ? (array) $u->roles : [];
    }

    /** Administrator o shop_manager. */
    public static function is_admin($user_id = null)
    {
        $roles = self::get_roles($user_id);
        return !empty(array_intersect(['administrator', 'shop_manager'], $roles));
    }

    /** Acceso de administración del plugin (todas las pestañas). */
    public static function can_admin($user_id = null)
    {
        return self::is_admin($user_id);
    }
}
