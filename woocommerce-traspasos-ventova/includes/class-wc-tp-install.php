<?php
// Evita acceso directo al archivo
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Clase para manejar la instalación y actualización del plugin.
 */
class WC_TP_Install {

    /**
     * Inicializa los hooks de instalación.
     */
    public static function init() {
        // Registra el hook de activación del plugin
        register_activation_hook( plugin_dir_path( __DIR__ ) . 'woo-traspasos-producto.php', [ __CLASS__, 'install' ] );
        // Ejecuta la actualización al cargar el plugin
        add_action( 'plugins_loaded', [ __CLASS__, 'update_db_check' ] );
    }

    /**
     * Crea o actualiza la tabla para el historial de traspasos al activar el plugin.
     */
    public static function install() {
        // Ejecuta la creación/actualización de la tabla
        self::create_table();
    }

    /**
     * Verifica si es necesario actualizar la estructura de la base de datos.
     */
    public static function update_db_check() {
        // Compara la versión del plugin almacenada con la actual
        $current_version = '1.5.0'; // Incrementa la versión para forzar la actualización
        $installed_version = get_option( 'wc_tp_version' );

        if ( $installed_version !== $current_version ) {
            self::create_table();
            update_option( 'wc_tp_version', $current_version );
            error_log( 'WC_TP: Actualización de la base de datos ejecutada a la versión ' . $current_version );
        }
    }

    /**
     * Crea o actualiza la tabla de historial de traspasos.
     */
    public static function create_table() {
        global $wpdb;
        $table = $wpdb->prefix . 'wc_tp_history';
        $charset = $wpdb->get_charset_collate();

        // Definición de la estructura de la tabla
        $sql = "
        CREATE TABLE $table (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            date_created DATETIME NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            origen VARCHAR(100) NOT NULL,
            destino VARCHAR(100) NOT NULL,
            items LONGTEXT NOT NULL,
            descripcion TEXT NULL,
            guia VARCHAR(100) NOT NULL DEFAULT '',
            estado VARCHAR(50) NOT NULL DEFAULT 'En Curso',
            completed TINYINT(1) NOT NULL DEFAULT 0,
            costo_envio DECIMAL(10,2) DEFAULT NULL,
            fecha_pago_envio DATE DEFAULT NULL,
            metodo_envio VARCHAR(50) DEFAULT NULL,
            PRIMARY KEY  (id)
        ) $charset;
        ";

        // Ejecuta la consulta para crear o actualizar la tabla
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        // Verifica manualmente si las columnas existen y las añade si es necesario
        self::ensure_columns_exist();

        // Registra en el log si la tabla se creó o actualizó
        error_log( 'WC_TP: create_table ejecutado para ' . $table );
    }

    /**
     * Asegura que todas las columnas opcionales existan en la tabla.
     */
    public static function ensure_columns_exist() {
        global $wpdb;
        $table = $wpdb->prefix . 'wc_tp_history';

        // Verifica si la columna 'guia' existe
        $guia_exists = $wpdb->get_var( "SHOW COLUMNS FROM $table LIKE 'guia'" );
        if ( ! $guia_exists ) {
            $result = $wpdb->query( "ALTER TABLE $table ADD guia VARCHAR(100) NOT NULL DEFAULT ''" );
            error_log( 'WC_TP: Columna guia añadida: ' . ( $result !== false ? 'Éxito' : $wpdb->last_error ) );
        }

        // Verifica si la columna 'estado' existe
        $estado_exists = $wpdb->get_var( "SHOW COLUMNS FROM $table LIKE 'estado'" );
        if ( ! $estado_exists ) {
            $result = $wpdb->query( "ALTER TABLE $table ADD estado VARCHAR(50) NOT NULL DEFAULT 'En Curso'" );
            error_log( 'WC_TP: Columna estado añadida: ' . ( $result !== false ? 'Éxito' : $wpdb->last_error ) );
        }

        // Verifica si la columna 'completed' existe
        $completed_exists = $wpdb->get_var( "SHOW COLUMNS FROM $table LIKE 'completed'" );
        if ( ! $completed_exists ) {
            $result = $wpdb->query( "ALTER TABLE $table ADD completed TINYINT(1) NOT NULL DEFAULT 0" );
            error_log( 'WC_TP: Columna completed añadida: ' . ( $result !== false ? 'Éxito' : $wpdb->last_error ) );
        }

        // Verifica si la columna 'costo_envio' existe
        $costo_envio_exists = $wpdb->get_var( "SHOW COLUMNS FROM $table LIKE 'costo_envio'" );
        if ( ! $costo_envio_exists ) {
            $result = $wpdb->query( "ALTER TABLE $table ADD costo_envio DECIMAL(10,2) DEFAULT NULL" );
            error_log( 'WC_TP: Columna costo_envio añadida: ' . ( $result !== false ? 'Éxito' : $wpdb->last_error ) );
        }

        // Verifica si la columna 'fecha_pago_envio' existe
        $fecha_pago_envio_exists = $wpdb->get_var( "SHOW COLUMNS FROM $table LIKE 'fecha_pago_envio'" );
        if ( ! $fecha_pago_envio_exists ) {
            $result = $wpdb->query( "ALTER TABLE $table ADD fecha_pago_envio DATE DEFAULT NULL" );
            error_log( 'WC_TP: Columna fecha_pago_envio añadida: ' . ( $result !== false ? 'Éxito' : $wpdb->last_error ) );
        }

        // Verifica si la columna 'metodo_envio' existe
        $metodo_envio_exists = $wpdb->get_var( "SHOW COLUMNS FROM $table LIKE 'metodo_envio'" );
        if ( ! $metodo_envio_exists ) {
            $result = $wpdb->query( "ALTER TABLE $table ADD metodo_envio VARCHAR(50) DEFAULT NULL" );
            error_log( 'WC_TP: Columna metodo_envio añadida: ' . ( $result !== false ? 'Éxito' : $wpdb->last_error ) );
        }

        // Verifica si la columna 'descripcion' existe (traspasos de bienes sin productos)
        $descripcion_exists = $wpdb->get_var( "SHOW COLUMNS FROM $table LIKE 'descripcion'" );
        if ( ! $descripcion_exists ) {
            $result = $wpdb->query( "ALTER TABLE $table ADD descripcion TEXT NULL" );
            error_log( 'WC_TP: Columna descripcion añadida: ' . ( $result !== false ? 'Éxito' : $wpdb->last_error ) );
        }
    }
}