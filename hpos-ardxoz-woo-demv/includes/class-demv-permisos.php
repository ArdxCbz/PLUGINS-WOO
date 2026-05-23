<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolución centralizada de permisos del plugin.
 *
 * Reemplaza checks dispersos en Admin, Caja, Depositos_Express y Objetivos.
 * Las clases originales delegan aquí para mantener una sola fuente de verdad.
 *
 * Todas las funciones aceptan $user_id opcional; si se omite se usa el usuario actual.
 */
class HPOS_Ardxoz_Woo_DEMV_Permisos
{
    /** Meta-key donde se guarda la sucursal asignada al vendedor. */
    const META_SUCURSAL_CAJA = 'hawd_sucursal_caja';

    /**
     * Lista de sucursales válidas para acceso a Caja Efectivo.
     * Fuente única: HPOS_Ardxoz_Woo_DEMV_Config::SUCURSALES.
     */
    private static function sucursales_caja()
    {
        return class_exists('HPOS_Ardxoz_Woo_DEMV_Config')
            ? HPOS_Ardxoz_Woo_DEMV_Config::SUCURSALES
            : [];
    }

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

    /** Administrator o shop_manager. */
    public static function is_admin($user_id = null)
    {
        $roles = self::get_roles($user_id);
        return !empty(array_intersect(['administrator', 'shop_manager'], $roles));
    }

    public static function is_vendedor($user_id = null)
    {
        return in_array('vendedor', self::get_roles($user_id), true);
    }

    /** Sucursal asignada al usuario (string vacía si no tiene). */
    public static function user_sucursal($user_id = null)
    {
        $uid = $user_id ?: get_current_user_id();
        return (string) get_user_meta((int) $uid, self::META_SUCURSAL_CAJA, true);
    }

    /**
     * Acceso a "Caja Efectivo": admin/shop_manager, o vendedor con sucursal válida.
     */
    public static function can_access_caja($user_id = null)
    {
        if (self::is_admin($user_id)) {
            return true;
        }
        if (!self::is_vendedor($user_id)) {
            return false;
        }
        return in_array(self::user_sucursal($user_id), self::sucursales_caja(), true);
    }

    /**
     * Acceso a "Depósitos Express": admin/shop_manager, o usuario con
     * meta hawd_user_depositos_express = 1.
     */
    public static function can_access_express($user_id = null)
    {
        if (!$user_id && !is_user_logged_in()) {
            return false;
        }
        if (self::is_admin($user_id)) {
            return true;
        }
        $uid = $user_id ?: get_current_user_id();
        return (string) get_user_meta((int) $uid, HPOS_Ardxoz_Woo_DEMV_Config::META_KEY_EXPRESS, true) === '1';
    }

    /** True si el vendedor está marcado como visible para Objetivos. */
    public static function is_objetivos_visible($user_id)
    {
        return (string) get_user_meta((int) $user_id, HPOS_Ardxoz_Woo_DEMV_Objetivos::META_KEY_VISIBLE, true) === '1';
    }

    /**
     * Acceso a "Objetivos": admin/shop_manager, o vendedor con visibilidad activa.
     */
    public static function can_access_objetivos($user_id = null)
    {
        if (self::is_admin($user_id)) {
            return true;
        }
        if (!self::is_vendedor($user_id)) {
            return false;
        }
        $uid = $user_id ?: get_current_user_id();
        return self::is_objetivos_visible($uid);
    }
}
