<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolución de permisos del plugin.
 *
 * Deliberadamente se comprueba la PERTENENCIA AL ROL y no `current_user_can('vendedor')`,
 * que es lo que hace hoy el tema hijo (`admin-cleanup.php:15`). Ahí se pasa un
 * nombre de rol donde WordPress espera una capacidad: solo funciona si alguien
 * añadió al rol una capacidad literal llamada `vendedor`. Comprobar el rol es
 * equivalente cuando eso es cierto, y correcto cuando no lo es.
 *
 * Mismo criterio que HPOS_Ardxoz_Woo_DEMV_Permisos::is_vendedor().
 */
class VCV_Permisos
{
    /** Meta-key de la sucursal asignada (propiedad del plugin DEMV). */
    const META_SUCURSAL = 'hawd_sucursal_caja';

    private static function get_user($user_id = null)
    {
        if ($user_id) {
            $u = get_userdata((int) $user_id);
            return $u ?: null;
        }
        $u = wp_get_current_user();
        return ($u && $u->ID) ? $u : null;
    }

    private static function roles($user_id = null)
    {
        $u = self::get_user($user_id);
        return $u ? (array) $u->roles : [];
    }

    /** Administrador o gestor de tienda. */
    public static function is_admin_user($user_id = null)
    {
        return !empty(array_intersect(['administrator', 'shop_manager'], self::roles($user_id)));
    }

    public static function is_vendedor($user_id = null)
    {
        return in_array('vendedor', self::roles($user_id), true);
    }

    /**
     * Quién puede ver el catálogo.
     *
     * Se amplía respecto del tema hijo, cuya página hace `wp_die()` para
     * cualquiera que no sea vendedor — ni siquiera un administrador podía
     * abrirla, lo que impedía verificar lo que las vendedoras estaban viendo.
     */
    public static function can_view_catalogo($user_id = null)
    {
        return self::is_vendedor($user_id) || self::is_admin_user($user_id);
    }

    /**
     * Sucursal asignada al usuario, si la tiene.
     *
     * Delega en DEMV, que es el dueño del dato; si el plugin no está activo se
     * lee la misma meta directamente.
     *
     * @return string Slug de sucursal, o cadena vacía.
     */
    public static function user_sucursal($user_id = null)
    {
        if (class_exists('HPOS_Ardxoz_Woo_DEMV_Permisos')) {
            return (string) HPOS_Ardxoz_Woo_DEMV_Permisos::user_sucursal($user_id);
        }

        $u = self::get_user($user_id);
        if (!$u) {
            return '';
        }

        return (string) get_user_meta($u->ID, self::META_SUCURSAL, true);
    }

    /** True si el tema hijo todavía registra la página vieja. */
    public static function legacy_page_present()
    {
        return function_exists('mostrar_pagina_productos_personalizados');
    }

    /** True si el tema hijo todavía registra la limpieza de menús vieja. */
    public static function legacy_menu_present()
    {
        return function_exists('personalizar_menu_vendedor');
    }
}
