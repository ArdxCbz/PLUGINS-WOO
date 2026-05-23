<?php
// Evita acceso directo al archivo
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Clase para manejar la exportación de traspasos a CSV.
 */
class WC_TP_CSV_Exporter {

    /**
     * Inicializa los hooks de exportación.
     */
    public static function init() {
        // Registra el manejador AJAX para exportar CSV
        add_action( 'wp_ajax_wc_tp_export_csv', [ __CLASS__, 'export_csv' ] );
    }

    /**
     * Exporta un traspaso a formato CSV.
     */
    public static function export_csv() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Sin permiso', '', 403 );
        }
        check_ajax_referer( 'wc_tp_nonce' );

        $id = intval( $_GET['id'] ?? 0 );
        if ( ! $id ) {
            wp_die( 'ID inválido', '', 400 );
        }

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}wc_tp_history WHERE id = %d",
                $id
            ),
            ARRAY_A
        );
        if ( ! $row ) {
            wp_die( 'Registro no encontrado', '', 404 );
        }

        // Configura las cabeceras para la descarga del CSV
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=traspaso-' . $id . '.csv' );

        // Genera el contenido del CSV
        $output = fopen( 'php://output', 'w' );
        fputcsv( $output, [ 'ID', 'Fecha', 'Usuario', 'Origen', 'Destino', 'Tipo', 'Contenido', 'SKU', 'Cantidad' ] );
        $user = get_userdata( $row['user_id'] );
        $items = json_decode( $row['items'] ?? '[]', true ) ?: [];
        $descripcion = $row['descripcion'] ?? '';
        $is_desc = empty( $items ) && $descripcion !== '';

        if ( $is_desc ) {
            fputcsv( $output, [
                $row['id'],
                $row['date_created'],
                $user ? $user->user_login : '',
                WC_TP_Mappings::map_sucursal( $row['origen'] ),
                WC_TP_Mappings::map_sucursal( $row['destino'] ),
                'BIENES',
                $descripcion,
                '',
                '',
            ] );
        } else {
            foreach ( $items as $it ) {
                fputcsv( $output, [
                    $row['id'],
                    $row['date_created'],
                    $user ? $user->user_login : '',
                    WC_TP_Mappings::map_sucursal( $row['origen'] ),
                    WC_TP_Mappings::map_sucursal( $row['destino'] ),
                    'PRODUCTOS',
                    $it['name'],
                    $it['sku'],
                    $it['qty'],
                ] );
            }
        }
        fclose( $output );
        exit;
    }
}