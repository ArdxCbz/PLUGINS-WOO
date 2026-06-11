<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Esquema de tablas propias del plugin de Finanzas (v1.0+).
 *
 * Tres tablas:
 *  - {prefix}fin_accounts:    cuentas bancarias y cajas de efectivo.
 *  - {prefix}fin_categories:  categorías contables (ingreso / egreso).
 *  - {prefix}fin_movements:   ledger central de movimientos (running balance).
 *
 * Estrategia de versionado idéntica al plugin de Inventario: si la opción
 * `fin_db_version` difiere de DB_VERSION se ejecuta install() (dbDelta,
 * idempotente) y se sube la opción. Las tablas NO se borran al desactivar:
 * preservan el histórico de movimientos y los saldos.
 */
class FIN_Schema
{
    const DB_VERSION = '1.2';
    const OPTION_KEY = 'fin_db_version';

    public static function table($name)
    {
        global $wpdb;
        return $wpdb->prefix . 'fin_' . $name;
    }

    public static function maybe_upgrade()
    {
        $current = (string) get_option(self::OPTION_KEY, '');
        if ($current === self::DB_VERSION) {
            return;
        }
        self::install();

        // Migración 1.0 → 1.1: las categorías ganan `group_key` (grupo contable)
        // y `requires_description` (motivos tipo "Otros"). dbDelta agrega las
        // columnas; este backfill asigna un grupo razonable a las filas viejas
        // para que el Estado de Resultados las clasifique sin intervención.
        if ($current !== '' && version_compare($current, '1.1', '<')) {
            self::backfill_category_groups_1_1();
        }

        // Migración 1.1 → 1.2: multi-moneda. dbDelta agrega `currency` a cuentas
        // y `currency` + `rate_to_base` a movimientos. No requiere backfill: el
        // DEFAULT 'BOB' / 1 deja las filas viejas en la moneda base (Bs), que es
        // el comportamiento mono-moneda previo. Sin acción adicional aquí.

        update_option(self::OPTION_KEY, self::DB_VERSION, false);
    }

    /**
     * Asigna `group_key` a las categorías que quedaron en '' tras el ALTER 1.1:
     *  - egreso con nombre tipo "compras inventario" → Compra de Inventario.
     *  - resto de egresos → Gastos Operativos (grupo por defecto editable).
     *  - ingresos → Ventas.
     * Idempotente: solo toca filas con group_key vacío.
     */
    public static function backfill_category_groups_1_1()
    {
        global $wpdb;
        $t = self::table('categories');
        if ($wpdb->get_var("SHOW TABLES LIKE '$t'") !== $t) {
            return;
        }
        $cols = $wpdb->get_col("SHOW COLUMNS FROM $t", 0);
        if (!in_array('group_key', $cols, true)) {
            return;
        }

        // Compras de inventario por nombre.
        $wpdb->query($wpdb->prepare(
            "UPDATE $t SET group_key = %s
             WHERE group_key = '' AND nature = 'egreso' AND LOWER(name) LIKE %s",
            FIN_Groups::COMPRA_INVENTARIO, '%compras inventario%'
        ));
        // Resto de egresos → Gastos Operativos.
        $wpdb->query($wpdb->prepare(
            "UPDATE $t SET group_key = %s WHERE group_key = '' AND nature = 'egreso'",
            FIN_Groups::G_OPERATIVOS
        ));
        // Ingresos → Ventas.
        $wpdb->query($wpdb->prepare(
            "UPDATE $t SET group_key = %s WHERE group_key = '' AND nature = 'ingreso'",
            FIN_Groups::VENTAS
        ));
    }

    public static function install()
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        global $wpdb;
        $charset    = $wpdb->get_charset_collate();
        $accounts   = self::table('accounts');
        $categories = self::table('categories');
        $movements  = self::table('movements');

        // dbDelta es muy estricto con la sintaxis: doble espacio antes de los
        // paréntesis de PRIMARY KEY, mayúsculas en tipos, una definición por
        // línea. No reformatear.

        // ── Cuentas y cajas ──────────────────────────────────────────────────
        // `balance` es el saldo actual denormalizado (running): lo mueve cada
        // movimiento. `opening_balance` es fijo tras crear (cualquier ajuste
        // posterior se hace con un movimiento manual). `active=0` es
        // soft-disable: la cuenta sale de los selects pero su histórico se
        // conserva. `allow_negative` autoriza sobregiro (salta la validación
        // de saldo en egresos/transferencias).
        $sql_accounts = "CREATE TABLE $accounts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(150) NOT NULL,
            account_number VARCHAR(100) NOT NULL DEFAULT '',
            type VARCHAR(20) NOT NULL DEFAULT 'banco',
            currency VARCHAR(10) NOT NULL DEFAULT 'BOB',
            opening_balance DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            balance DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            allow_negative TINYINT(1) NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            notes TEXT,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY active_key (active),
            KEY type_key (type),
            KEY name_key (name)
        ) $charset;";

        // ── Categorías contables (= Motivos) ─────────────────────────────────
        // `nature` clasifica la categoría como 'ingreso' o 'egreso' (dirección /
        // flujo de caja). `group_key` (1.1+) la cuelga de un Grupo contable
        // (FIN_Groups) que define su comportamiento en el Estado de Resultados.
        // `requires_description` (1.1+): si 1, el movimiento exige descripción
        // (motivos tipo "Otros").
        $sql_categories = "CREATE TABLE $categories (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(150) NOT NULL,
            nature VARCHAR(20) NOT NULL DEFAULT 'egreso',
            group_key VARCHAR(40) NOT NULL DEFAULT '',
            requires_description TINYINT(1) NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY active_key (active),
            KEY nature_key (nature),
            KEY group_key_key (group_key)
        ) $charset;";

        // ── Movimientos (ledger inmutable) ───────────────────────────────────
        // Notas de diseño:
        //  - `balance_after` es el running balance de la CUENTA tras este
        //    movimiento; se calcula al insertar a partir de account.balance.
        //  - `type`: opening | ingreso | egreso | transfer_in | transfer_out.
        //    `direction` (I/O) deriva del tipo.
        //  - `amount` siempre positivo.
        //  - `category_id` obligatoria en ingreso/egreso; NULL en opening y
        //    transferencias.
        //  - Inmutable: no se edita ni borra. La corrección se hace con un
        //    contrasiento (movimiento espejo) que apunta al original vía
        //    `reverses_id`; el original queda marcado con `reversed_at/by`.
        //  - `transfer_id` une las dos patas (out/in) de una transferencia.
        //  - `ref_table`/`ref_id`/`ref_code` trazan al documento origen
        //    (p.ej. 'purchases' / id / 'COMP-2026-0001') para idempotencia.
        $sql_movements = "CREATE TABLE $movements (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            account_id BIGINT UNSIGNED NOT NULL,
            category_id BIGINT UNSIGNED DEFAULT NULL,
            type VARCHAR(20) NOT NULL,
            direction CHAR(1) NOT NULL,
            currency VARCHAR(10) NOT NULL DEFAULT 'BOB',
            rate_to_base DECIMAL(18,6) NOT NULL DEFAULT 1.000000,
            amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            balance_after DECIMAL(14,2) NOT NULL DEFAULT 0.00,
            description TEXT,
            movement_date DATETIME NOT NULL,
            transfer_id BIGINT UNSIGNED DEFAULT NULL,
            reverses_id BIGINT UNSIGNED DEFAULT NULL,
            reversed_at DATETIME DEFAULT NULL,
            reversed_by BIGINT UNSIGNED DEFAULT NULL,
            ref_table VARCHAR(50) DEFAULT NULL,
            ref_id BIGINT UNSIGNED DEFAULT NULL,
            ref_code VARCHAR(50) DEFAULT NULL,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY account_chrono (account_id, id),
            KEY movement_date_key (movement_date),
            KEY category_date (category_id, movement_date),
            KEY type_date (type, movement_date),
            KEY transfer_key (transfer_id),
            KEY reverses_key (reverses_id),
            KEY ref_lookup (ref_table, ref_id)
        ) $charset;";

        dbDelta($sql_accounts);
        dbDelta($sql_categories);
        dbDelta($sql_movements);
    }
}
