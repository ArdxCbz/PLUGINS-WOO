<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolución centralizada de permisos del plugin (v3.4+).
 *
 *  - "Admin" = administrator o shop_manager (acceso completo: inventario,
 *    histórico, mermas, configuración, cualquier sucursal).
 *  - "Contador" = usuario con user_meta `iem_sucursal_contador` apuntando al
 *    slug de una sucursal. Solo accede a "Mi Conteo" sobre SU sucursal.
 *
 * Patrón modelado sobre [[plugin-hpos-ardxoz-woo-demv]] (Permisos::can_access_caja).
 *
 * Todas las funciones aceptan $user_id opcional; si se omite usa el actual.
 */
class IEM_Permisos
{
    /** Meta-key donde se guarda el slug de sucursal asignado al contador. */
    const META_SUCURSAL = 'iem_sucursal_contador';

    private static function get_user($user_id = null)
    {
        if ($user_id) {
            $u = get_userdata((int) $user_id);
            return $u ?: null;
        }
        $u = wp_get_current_user();
        return ($u && $u->ID) ? $u : null;
    }

    private static function get_roles($user_id = null)
    {
        $u = self::get_user($user_id);
        return $u ? (array) $u->roles : [];
    }

    /** Administrator o shop_manager (acceso total). */
    public static function is_admin($user_id = null)
    {
        $roles = self::get_roles($user_id);
        return !empty(array_intersect(['administrator', 'shop_manager'], $roles));
    }

    /**
     * Acceso de administración del plugin: páginas Inventario, Histórico,
     * Mermas, Configuración. Excluye contadores puros.
     */
    public static function can_admin($user_id = null)
    {
        return self::is_admin($user_id);
    }

    /** Slug de la sucursal asignada al usuario (string vacía si no tiene). */
    public static function user_sucursal($user_id = null)
    {
        $uid = $user_id ?: get_current_user_id();
        if (!$uid) return '';
        return (string) get_user_meta((int) $uid, self::META_SUCURSAL, true);
    }

    /** Es contador (tiene sucursal asignada). */
    public static function is_contador($user_id = null)
    {
        return self::user_sucursal($user_id) !== '';
    }

    /**
     * ¿Puede acceder a "Mi Conteo"? Admin siempre; contadores asignados sí.
     * Usuarios sin sucursal ni admin: no.
     */
    public static function can_access_my_count($user_id = null)
    {
        return self::can_admin($user_id) || self::is_contador($user_id);
    }

    /**
     * ¿Puede contar/editar/cerrar una sesión de la sucursal indicada?
     * Admin sí; contador solo si la sesión es de SU sucursal asignada.
     */
    public static function can_count_sucursal($sucursal_slug, $user_id = null)
    {
        if (self::can_admin($user_id)) return true;
        return self::user_sucursal($user_id) === (string) $sucursal_slug;
    }

    /**
     * Lista de usuarios candidatos para asignación en la pantalla de
     * configuración. Incluye todos los usuarios EXCEPTO administradores
     * (ya tienen acceso total) y customers genéricos.
     *
     * @return WP_User[]
     */
    public static function assignable_users()
    {
        $users = get_users([
            'orderby' => 'display_name',
            'order'   => 'ASC',
            'fields'  => ['ID', 'display_name', 'user_login', 'user_email'],
        ]);
        $out = [];
        foreach ($users as $u) {
            $full = get_userdata($u->ID);
            if (!$full) continue;
            $roles = (array) $full->roles;
            if (in_array('administrator', $roles, true)) continue;
            if (in_array('shop_manager',   $roles, true)) continue;
            if (count($roles) === 1 && in_array('customer', $roles, true)) continue;
            $out[] = $full;
        }
        return $out;
    }
}
