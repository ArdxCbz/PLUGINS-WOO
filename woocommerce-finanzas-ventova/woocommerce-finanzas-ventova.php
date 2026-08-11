<?php
/*
Plugin Name: WooCommerce Finanzas VENTOVA
Description: Tesorería y contabilidad básica para Ventova. Menú top-level "Finanzas Ventova" con pestañas: Registro de Movimientos, Cuentas, Egresos de envío (courier), Reportes y Configuración. Gestiona cuentas bancarias y de efectivo, movimientos (ingreso/egreso/transferencia) con saldo corrido y validación de saldo, categorías contables y reportes (flujo de caja, gastos por categoría, estado de resultados).
Version: 2.22
Author: Ardx
Requires Plugins: woocommerce
*/

if (!defined('ABSPATH')) {
    exit;
}

define('FIN_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('FIN_PLUGIN_URL', plugin_dir_url(__FILE__));
define('FIN_VERSION', '2.22');

require_once FIN_PLUGIN_DIR . 'includes/class-fin-schema.php';
require_once FIN_PLUGIN_DIR . 'includes/class-fin-permisos.php';
require_once FIN_PLUGIN_DIR . 'includes/class-fin-currencies.php';
require_once FIN_PLUGIN_DIR . 'includes/class-fin-accounts.php';
require_once FIN_PLUGIN_DIR . 'includes/class-fin-groups.php';
require_once FIN_PLUGIN_DIR . 'includes/class-fin-categories.php';
require_once FIN_PLUGIN_DIR . 'includes/class-fin-movements.php';
require_once FIN_PLUGIN_DIR . 'includes/class-fin-reports.php';
require_once FIN_PLUGIN_DIR . 'includes/class-fin-inventory-costs.php';
require_once FIN_PLUGIN_DIR . 'includes/class-fin-orders.php';
require_once FIN_PLUGIN_DIR . 'includes/class-fin-traspasos.php';
require_once FIN_PLUGIN_DIR . 'includes/class-fin-rendicion.php';
require_once FIN_PLUGIN_DIR . 'includes/class-fin-csv.php';
require_once FIN_PLUGIN_DIR . 'includes/class-fin-admin.php';

// ── Instalación / upgrade del esquema propio ──
register_activation_hook(__FILE__, ['FIN_Schema', 'install']);
add_action('init', ['FIN_Schema', 'maybe_upgrade']);

register_deactivation_hook(__FILE__, function () {
    // No se borran las tablas propias: preservan el histórico de movimientos y
    // los saldos de las cuentas. La desinstalación (uninstall.php, futuro) sería
    // el lugar para limpieza opt-in.
});

add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) {
        return;
    }
    FIN_Admin::init();
    FIN_Orders::init();
});

/**
 * Formatea un monto para la UI con el símbolo de su moneda. Si no se pasa
 * moneda, usa la base (Bs/BOB). Ej: fin_money(1234.5) → "Bs 1,234.50";
 * fin_money(50, 'USD') → "$ 50.00". Negativos: "-Bs 50.00".
 *
 * @param float       $amount
 * @param string|null $currency  Código de moneda (null = base).
 */
function fin_money($amount, $currency = null)
{
    $amount = (float) $amount;
    $sign   = $amount < 0 ? '-' : '';
    $symbol = 'Bs';
    if (class_exists('FIN_Currencies')) {
        $code   = ($currency !== null && $currency !== '') ? $currency : FIN_Currencies::BASE_CODE;
        $symbol = FIN_Currencies::symbol($code);
    }
    return $sign . $symbol . ' ' . number_format(abs($amount), 2, '.', ',');
}

/**
 * Convierte un rango de fechas 'Y-m-d' (desde/hasta) en el par de timestamps UTC
 * que delimitan ESE rango en la ZONA HORARIA DEL SITIO: de la medianoche local
 * del día "desde" al último segundo local del día "hasta".
 *
 * Necesario para filtrar pedidos por `date_created`: WooCommerce compara contra
 * la fecha GMT del pedido, y `strtotime()` interpreta la fecha en UTC (WP fija el
 * timezone de PHP en UTC). En una zona con offset negativo (Bolivia, UTC−4) eso
 * corría la ventana ~4 h y reclasificaba los pedidos hechos cerca de medianoche.
 *
 * @param string $from 'Y-m-d'
 * @param string $to   'Y-m-d'
 * @return array{0:int|false,1:int|false} [from_ts, to_ts]; [false,false] si inválido.
 */
function fin_order_range_ts($from, $to)
{
    $tz = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
    $f  = date_create_immutable((string) $from . ' 00:00:00', $tz);
    $t  = date_create_immutable((string) $to   . ' 23:59:59', $tz);
    if (!$f || !$t) {
        return [false, false];
    }
    return [$f->getTimestamp(), $t->getTimestamp()];
}
