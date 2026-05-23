<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Esquema de tablas propias del plugin (v3.0+).
 *
 * Tres tablas:
 *  - {prefix}iem_count_sessions: encabezado del conteo de un mes/sucursal.
 *  - {prefix}iem_count_lines:    líneas (producto contado) de una sesión.
 *  - {prefix}iem_mermas:         registro de mermas / defectuosos.
 *
 * Estrategia de versionado: si `iem_db_version` (option) difiere de DB_VERSION,
 * se ejecuta install() y se sube la opción. install() usa dbDelta, por lo que
 * es idempotente y soporta ALTERs incrementales en versiones futuras.
 */
class IEM_Schema
{
    const DB_VERSION = '1.3';
    const OPTION_KEY = 'iem_db_version';

    public static function table($name)
    {
        global $wpdb;
        return $wpdb->prefix . 'iem_' . $name;
    }

    public static function maybe_upgrade()
    {
        $current = (string) get_option(self::OPTION_KEY, '');
        if ($current === self::DB_VERSION) {
            return;
        }

        // Migración 1.2 → 1.3: las filas "extra" (item_id=0) rompen el UNIQUE
        // (session_id, item_id) original. dbDelta no convierte UNIQUE → KEY,
        // así que el DROP se hace explícito antes de re-correr install().
        if ($current !== '' && version_compare($current, '1.3', '<')) {
            global $wpdb;
            $lines = self::table('count_lines');
            // Suppress: si la tabla aún no existe (instalación fresca), se ignora.
            $wpdb->hide_errors();
            $wpdb->query("ALTER TABLE $lines DROP INDEX session_item");
            $wpdb->show_errors();
        }

        self::install();
        update_option(self::OPTION_KEY, self::DB_VERSION, false);
    }

    public static function install()
    {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        global $wpdb;
        $charset  = $wpdb->get_charset_collate();
        $sessions = self::table('count_sessions');
        $lines    = self::table('count_lines');
        $mermas   = self::table('mermas');

        // dbDelta es muy estricto con la sintaxis: doble espacio antes de las
        // paréntesis de PRIMARY KEY, mayúsculas en tipos, etc. No reformatear.
        $sql_sessions = "CREATE TABLE $sessions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            period CHAR(7) NOT NULL,
            sucursal_slug VARCHAR(100) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'draft',
            created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            closed_by BIGINT UNSIGNED DEFAULT NULL,
            closed_at DATETIME DEFAULT NULL,
            notes TEXT,
            PRIMARY KEY  (id),
            UNIQUE KEY period_sucursal (period, sucursal_slug),
            KEY status_key (status)
        ) $charset;";

        // Notas v1.3:
        //  - `is_extra` distingue filas ad-hoc (agregadas por el contador para
        //    productos físicos que no estaban en el listado del snapshot) de
        //    las filas originales (`is_extra=0`).
        //  - `notes` es texto libre opcional para esas filas.
        //  - Se removió el UNIQUE (session_id, item_id) porque las filas extra
        //    pueden tener item_id=0 múltiples veces. Queda solo como KEY no
        //    única para conservar el índice.
        $sql_lines = "CREATE TABLE $lines (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id BIGINT UNSIGNED NOT NULL,
            item_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            parent_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            sku VARCHAR(100) NOT NULL DEFAULT '',
            name TEXT,
            category TEXT,
            sucursal_slug VARCHAR(100) NOT NULL,
            stock_at_count INT NOT NULL DEFAULT 0,
            counted_qty INT DEFAULT NULL,
            status_at_count VARCHAR(20) NOT NULL DEFAULT 'Sin conteo',
            is_extra TINYINT(1) NOT NULL DEFAULT 0,
            notes TEXT,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY session_item (session_id, item_id),
            KEY item_id_key (item_id),
            KEY is_extra_key (is_extra)
        ) $charset;";

        $sql_mermas = "CREATE TABLE $mermas (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            item_id BIGINT UNSIGNED NOT NULL,
            parent_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            sku VARCHAR(100) NOT NULL DEFAULT '',
            name TEXT,
            sucursal_slug VARCHAR(100) NOT NULL,
            qty INT NOT NULL DEFAULT 0,
            tipo VARCHAR(30) NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            session_id BIGINT UNSIGNED DEFAULT NULL,
            decremented_wc TINYINT(1) NOT NULL DEFAULT 0,
            cost_at_register DECIMAL(12,2) DEFAULT NULL,
            returned_at DATETIME DEFAULT NULL,
            returned_by BIGINT UNSIGNED DEFAULT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY sucursal_date (sucursal_slug, created_at),
            KEY item_id_key (item_id),
            KEY tipo_key (tipo),
            KEY returned_at_key (returned_at)
        ) $charset;";

        dbDelta($sql_sessions);
        dbDelta($sql_lines);
        dbDelta($sql_mermas);
    }
}
