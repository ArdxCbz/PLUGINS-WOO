<?php
// Evita acceso directo al archivo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clase para manejar el mapeo de sucursales.
 */
class WC_TP_Mappings
{

    /**
     * Inicializa la clase (sin hooks específicos por ahora).
     */
    public static function init()
    {
        // Futuros hooks pueden agregarse aquí
    }

    /**
     * Mapea un slug de sucursal a su nombre legible.
     *
     * @param string $slug Slug de la sucursal.
     * @return string Nombre legible de la sucursal o el slug escapado si no se encuentra.
     */
    public static function map_sucursal(string $slug): string
    {
        return WC_TP_Config::get_sucursal_name($slug);
    }
}