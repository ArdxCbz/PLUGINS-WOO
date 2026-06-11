<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Catálogo FIJO de grupos contables (v1.1).
 *
 * Cada Motivo (fila de fin_categories) cuelga de un grupo vía `group_key`. El
 * grupo define —de forma horneada, no editable por el usuario— cómo se comporta
 * ese dinero en el Estado de Resultados:
 *
 *   - nature           : 'ingreso' | 'egreso' | 'ambos' (qué movimientos lo usan).
 *   - affects_result   : ¿entra al Estado de Resultados? (false = patrimonial/activo).
 *   - pl_section       : sección del P&L ('ventas'|'otros_ingresos'|'cmv'|'gastos'|null).
 *   - pl_sign          : '+' suma a la utilidad, '-' resta. null si no aplica.
 *   - auto             : alimentado automáticamente (CMV se calcula del Kardex);
 *                        NO admite motivos manuales ni se ofrece en el CRUD.
 *   - sort_order       : orden de presentación.
 *
 * Regla de oro: el usuario nunca decide "¿esto afecta la utilidad?"; lo hereda
 * del grupo. Así es imposible clavar mal la contabilidad.
 */
class FIN_Groups
{
    // Claves estables (no renombrar: viven en fin_categories.group_key).
    const VENTAS             = 'ventas';
    const OTROS_INGRESOS     = 'otros_ingresos';
    const CMV                = 'cmv';
    const G_COMERCIALIZACION = 'g_comercializacion';
    const G_ADMINISTRATIVOS  = 'g_administrativos';
    const G_OPERATIVOS       = 'g_operativos';
    const G_FINANCIEROS      = 'g_financieros';
    const COMPRA_INVENTARIO  = 'compra_inventario';
    const PATRIMONIAL        = 'patrimonial';

    /** @return array<string, array> Catálogo completo indexado por key. */
    public static function all()
    {
        return [
            self::VENTAS => [
                'key' => self::VENTAS, 'label' => 'Ventas',
                'nature' => 'ingreso', 'affects_result' => true,
                'pl_section' => 'ventas', 'pl_sign' => '+', 'auto' => false, 'sort_order' => 10,
            ],
            self::OTROS_INGRESOS => [
                'key' => self::OTROS_INGRESOS, 'label' => 'Otros ingresos',
                'nature' => 'ingreso', 'affects_result' => true,
                'pl_section' => 'otros_ingresos', 'pl_sign' => '+', 'auto' => false, 'sort_order' => 20,
            ],
            self::CMV => [
                'key' => self::CMV, 'label' => 'Costo de Mercadería Vendida (CMV)',
                'nature' => 'egreso', 'affects_result' => true,
                'pl_section' => 'cmv', 'pl_sign' => '-', 'auto' => true, 'sort_order' => 30,
            ],
            self::G_COMERCIALIZACION => [
                'key' => self::G_COMERCIALIZACION, 'label' => 'Gastos de Comercialización',
                'nature' => 'egreso', 'affects_result' => true,
                'pl_section' => 'gastos', 'pl_sign' => '-', 'auto' => false, 'sort_order' => 40,
            ],
            self::G_ADMINISTRATIVOS => [
                'key' => self::G_ADMINISTRATIVOS, 'label' => 'Gastos Administrativos',
                'nature' => 'egreso', 'affects_result' => true,
                'pl_section' => 'gastos', 'pl_sign' => '-', 'auto' => false, 'sort_order' => 50,
            ],
            self::G_OPERATIVOS => [
                'key' => self::G_OPERATIVOS, 'label' => 'Gastos Operativos',
                'nature' => 'egreso', 'affects_result' => true,
                'pl_section' => 'gastos', 'pl_sign' => '-', 'auto' => false, 'sort_order' => 60,
            ],
            self::G_FINANCIEROS => [
                'key' => self::G_FINANCIEROS, 'label' => 'Gastos Financieros',
                'nature' => 'egreso', 'affects_result' => true,
                'pl_section' => 'gastos', 'pl_sign' => '-', 'auto' => false, 'sort_order' => 70,
            ],
            // Sistema (auto): alimentado por la integración de Compras del plugin de
            // Inventario (costos de importación que capitalizan al inventario). NO se
            // ofrece en el CRUD de motivos; no afecta la utilidad (se muestra como
            // informativo en el Estado de Resultados). Ver FIN_Inventory_Costs.
            self::COMPRA_INVENTARIO => [
                'key' => self::COMPRA_INVENTARIO, 'label' => 'Compras de Inventario',
                'nature' => 'egreso', 'affects_result' => false,
                'pl_section' => null, 'pl_sign' => null, 'auto' => true, 'sort_order' => 80,
            ],
            self::PATRIMONIAL => [
                'key' => self::PATRIMONIAL, 'label' => 'Movimientos Patrimoniales',
                'nature' => 'ambos', 'affects_result' => false,
                'pl_section' => null, 'pl_sign' => null, 'auto' => false, 'sort_order' => 90,
            ],
        ];
    }

    public static function get($key)
    {
        $all = self::all();
        return $all[(string) $key] ?? null;
    }

    public static function exists($key)
    {
        return self::get($key) !== null;
    }

    public static function label($key)
    {
        $g = self::get($key);
        return $g ? $g['label'] : (string) $key;
    }

    /** ¿El grupo afecta el Estado de Resultados? */
    public static function affects_result($key)
    {
        $g = self::get($key);
        return $g ? (bool) $g['affects_result'] : false;
    }

    /** ¿Es un grupo alimentado automáticamente (no admite motivos manuales)? */
    public static function is_auto($key)
    {
        $g = self::get($key);
        return $g ? (bool) $g['auto'] : false;
    }

    /**
     * Grupos seleccionables como Motivo en el CRUD de categorías, compatibles
     * con la naturaleza dada. Excluye los grupos `auto` (p.ej. CMV). Si no se
     * pasa naturaleza, devuelve todos los no-auto.
     *
     * @param string $nature 'ingreso'|'egreso'|''
     * @return array<string, array>
     */
    public static function selectable($nature = '')
    {
        $out = [];
        foreach (self::all() as $key => $g) {
            if ($g['auto']) {
                continue;
            }
            if ($nature !== '' && $g['nature'] !== 'ambos' && $g['nature'] !== $nature) {
                continue;
            }
            $out[$key] = $g;
        }
        return $out;
    }

    /** ¿Es coherente el grupo con la naturaleza del movimiento/categoría? */
    public static function nature_matches($key, $nature)
    {
        $g = self::get($key);
        if (!$g) {
            return false;
        }
        return $g['nature'] === 'ambos' || $g['nature'] === $nature;
    }
}
