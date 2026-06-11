<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Fachada para la integración con el plugin de Inventario (costos de importación).
 *
 * Una compra de importación NO es un gasto: su costo capitaliza al inventario
 * (activo) y golpea el Estado de Resultados como CMV recién al vender. Pero los
 * componentes de ese costo (mercadería, flete, aduana, seguro…) sí son SALIDAS
 * REALES de caja. El plugin de Inventario los va adjuntando a cada compra en
 * borrador y, por cada uno, llama aquí para registrar el egreso en la caja que
 * el operador elige por gasto (puede ser una caja en otra moneda, p.ej. USD;
 * en ese caso el egreso lleva el tipo de cambio indicado).
 *
 * Estos egresos:
 *  - Usan la categoría de SISTEMA del grupo `compra_inventario` (auto, fuera del
 *    CRUD de motivos) → en el Estado de Resultados se muestran como **informativo**
 *    (no afectan la utilidad). En el Flujo de Caja sí cuentan (es efectivo real).
 *  - Llevan `ref_table='iem_purchase_cost'` + `ref_id` (id del gasto en Inventario)
 *    + `ref_code` (código de la compra) para trazar.
 *  - `skip_balance_check`: es un pago consumado; no se bloquea por saldo.
 *
 * El plugin de Inventario guarda el `movement_id` devuelto para poder reversar
 * (contrasiento) si el gasto se edita o elimina mientras la compra está en
 * borrador.
 */
class FIN_Inventory_Costs
{
    const REF_TABLE = 'iem_purchase_cost';

    /** ¿Está disponible la integración? (Finanzas cargado + caja válida). */
    public static function is_available()
    {
        return class_exists('FIN_Movements') && class_exists('FIN_Accounts');
    }

    /**
     * Cajas activas de Finanzas con su info de moneda, para que el plugin de
     * Inventario ofrezca elegir la caja (y su tipo de cambio) por gasto.
     *
     * @return array Lista de
     *   ['id','name','currency','symbol','is_base','default_rate'].
     */
    public static function accounts()
    {
        if (!self::is_available()) {
            return [];
        }
        $base = class_exists('FIN_Currencies') ? FIN_Currencies::BASE_CODE : 'BOB';
        $out  = [];
        foreach (FIN_Accounts::active_list() as $a) {
            $cur = (string) ($a['currency'] ?? $base);
            $out[] = [
                'id'           => (int) $a['id'],
                'name'         => (string) $a['name'],
                'currency'     => $cur,
                'symbol'       => class_exists('FIN_Currencies') ? FIN_Currencies::symbol($cur) : $cur,
                'is_base'      => ($cur === $base),
                'default_rate' => class_exists('FIN_Currencies') ? (float) FIN_Currencies::rate($cur) : 1.0,
            ];
        }
        return $out;
    }

    /**
     * Moneda de una caja: ['currency','symbol','is_base','default_rate'] o null
     * si la caja no existe / no está activa.
     */
    public static function account_currency($account_id)
    {
        if (!self::is_available()) {
            return null;
        }
        $acc = FIN_Accounts::get((int) $account_id);
        if (!$acc || (int) $acc['active'] !== 1) {
            return null;
        }
        $base = class_exists('FIN_Currencies') ? FIN_Currencies::BASE_CODE : 'BOB';
        $cur  = (string) ($acc['currency'] ?? $base);
        return [
            'currency'     => $cur,
            'symbol'       => class_exists('FIN_Currencies') ? FIN_Currencies::symbol($cur) : $cur,
            'is_base'      => ($cur === $base),
            'default_rate' => class_exists('FIN_Currencies') ? (float) FIN_Currencies::rate($cur) : 1.0,
        ];
    }

    /**
     * Registra un egreso de costo de importación contra una caja.
     *
     * @param int    $account_id   Caja destino (debe existir y estar activa).
     * @param float  $amount       Monto en la MONEDA de la caja (> 0).
     * @param string $description  Texto legible (incluye el motivo del gasto).
     * @param int    $ref_id       id del gasto en Inventario (iem_purchase_expenses).
     * @param string $ref_code     código de la compra (COMP-…).
     * @param string $movement_date 'Y-m-d' opcional; por defecto hoy.
     * @param float  $rate         Tipo de cambio (Bs por unidad) si la caja NO es
     *                             base; obligatorio en ese caso. Base → se ignora (1).
     * @return int|WP_Error id del movimiento creado.
     */
    public static function register($account_id, $amount, $description, $ref_id, $ref_code = '', $movement_date = '', $rate = 0)
    {
        $account_id = (int) $account_id;
        $amount     = round((float) $amount, 2);
        if ($amount <= 0) {
            return new WP_Error('fin_ic_amount', 'El monto del gasto debe ser mayor a 0.');
        }
        $acc = FIN_Accounts::get($account_id);
        if (!$acc || (int) $acc['active'] !== 1) {
            return new WP_Error('fin_ic_account', 'La caja elegida para el gasto no es válida o está inactiva.');
        }
        $category_id = FIN_Categories::system_import_costs_id();
        if ($category_id <= 0) {
            return new WP_Error('fin_ic_category', 'No se pudo preparar la categoría de costo de importación.');
        }

        // Moneda de la caja: si no es base, el egreso requiere tipo de cambio.
        $base    = class_exists('FIN_Currencies') ? FIN_Currencies::BASE_CODE : 'BOB';
        $currency = (string) ($acc['currency'] ?? $base);
        $is_base  = ($currency === $base);
        $rate     = $is_base ? 1.0 : round((float) $rate, 6);
        if (!$is_base && $rate <= 0) {
            return new WP_Error('fin_ic_rate', sprintf(
                'Indica el tipo de cambio del gasto (Bs por %s).',
                class_exists('FIN_Currencies') ? FIN_Currencies::symbol($currency) : $currency
            ));
        }

        $data = [
            'account_id'         => $account_id,
            'type'               => 'egreso',
            'amount'             => $amount,
            'category_id'        => $category_id,
            'description'        => (string) $description,
            'rate'               => $rate,
            'ref_table'          => self::REF_TABLE,
            'ref_id'             => (int) $ref_id,
            'ref_code'           => (string) $ref_code,
            'skip_balance_check' => true,
            'user_id'            => get_current_user_id(),
        ];
        $movement_date = trim((string) $movement_date);
        if ($movement_date !== '') {
            $data['movement_date'] = $movement_date;
        }

        return FIN_Movements::register($data);
    }

    /**
     * Reversa (contrasiento) un egreso de costo de importación por su id.
     * Idempotente vía FIN_Movements::reverse (si ya estaba anulado, devuelve error).
     *
     * @return int|WP_Error id del contrasiento.
     */
    public static function reverse($movement_id)
    {
        return FIN_Movements::reverse((int) $movement_id);
    }
}
