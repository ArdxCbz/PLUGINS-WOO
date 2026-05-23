<?php
if (!defined('ABSPATH')) {
    exit;
}

class WC_TP_Config
{

    // Slugs de sucursales - Centralizado para fácil edición
    const SUCURSAL_CBBA = 'sucursal-cbba-stock';
    const SUCURSAL_SCZ = 'sucursal-scz-stock';

    // Nombres legibles
    const NAME_CBBA = 'COCHABAMBA';
    const NAME_SCZ = 'SANTA CRUZ';

    // Métodos de envío permitidos para traspasos.
    // Hardcodeados por ahora; en el futuro reemplazar por CRUD.
    const METODOS_ENVIO = ['IBEX', 'ENCOMIENDA'];

    public static function get_sucursales()
    {
        return [
            self::SUCURSAL_CBBA => self::NAME_CBBA,
            self::SUCURSAL_SCZ => self::NAME_SCZ,
        ];
    }

    public static function get_sucursal_name($slug)
    {
        $sucursales = self::get_sucursales();
        return $sucursales[$slug] ?? $slug;
    }

    public static function get_metodos_envio()
    {
        return self::METODOS_ENVIO;
    }

    public static function is_metodo_envio_valido($valor)
    {
        return in_array((string) $valor, self::METODOS_ENVIO, true);
    }
}
