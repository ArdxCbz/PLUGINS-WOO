<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scripts para el área de administración (Cargador de medios)
 */
function hpos_ardxoz_pagoqr_admin_scripts() {
    $screen = get_current_screen();
    if ( $screen && strpos( $screen->id, 'woocommerce_page_wc-settings' ) !== false ) {
        wp_enqueue_media();
        wp_enqueue_script( 'hpos-ardxoz-pagoqr-admin', plugins_url( 'assets/js/admin.js', dirname(__FILE__) ), array( 'jquery' ), '1.0.0', true );
        wp_enqueue_style( 'hpos-ardxoz-pagoqr-admin', plugins_url( 'assets/css/admin.css', dirname(__FILE__) ), array(), '1.0.0' );
    }
}
add_action( 'admin_enqueue_scripts', 'hpos_ardxoz_pagoqr_admin_scripts' );

/**
 * Scripts para el front-end (legacy y bloques)
 */
function hpos_ardxoz_pagoqr_front_scripts() {
    if ( ! is_checkout() && ! has_block( 'woocommerce/checkout' ) ) return;

    wp_enqueue_style( 'dashicons' );
    wp_enqueue_style( 'hpos-ardxoz-pagoqr-front', plugins_url( 'assets/css/front.css', dirname(__FILE__) ), array( 'dashicons' ), '1.0.3' );
    wp_enqueue_script( 'hpos-ardxoz-pagoqr-front', plugins_url( 'assets/js/front.js', dirname(__FILE__) ), array( 'jquery' ), '1.0.3', true );

    wp_localize_script( 'hpos-ardxoz-pagoqr-front', 'hpos_ardxoz_pagoqr_params', array(
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'texts'    => array(
            'continue' => 'Continuar',
            'error_image' => 'Por favor, sube solo archivos de imagen (JPG, PNG).',
            'upload_required' => 'Es obligatorio subir el comprobante para continuar.'
        )
    ) );
}
add_action( 'wp_enqueue_scripts', 'hpos_ardxoz_pagoqr_front_scripts' );

/**
 * Helper para generar campos de administración (Icono y QR)
 */
function hpos_ardxoz_pagoqr_generate_admin_field_html( $gateway, $key, $data, $type ) {
    $field_id = $gateway->get_field_key( $key );
    $preview_key = ( $type === 'icon' ) ? 'preview_icon' : 'preview_qr';
    $preview_value = $gateway->get_option( $preview_key );
    $title = $data['title'] ?? 'Archivo';
    
    ob_start();
    ?>
    <tr valign="top">
        <th scope="row" class="titledesc">
            <label><?php echo esc_html( $title ); ?></label>
        </th>
        <td class="forminp">
            <div class="hpos-ardxoz-pagoqr-upload-wrapper" data-target="<?php echo esc_attr( $gateway->get_field_key( $preview_key ) ); ?>">
                <div class="preview-area" style="margin-bottom: 10px;">
                    <?php if ( $preview_value ) : ?>
                        <img src="<?php echo esc_url( $preview_value ); ?>" style="max-width: 200px; display: block; border: 1px solid #ccc; padding: 5px;">
                        <button type="button" class="button remove-file" style="margin-top: 5px;">Eliminar</button>
                    <?php endif; ?>
                </div>
                <button type="button" class="button upload-file"><?php echo $preview_value ? 'Cambiar imagen' : 'Seleccionar imagen'; ?></button>
            </div>
        </td>
    </tr>
    <?php
    return ob_get_clean();
}

/**
 * Popup de pago en el footer (Solo en checkout)
 */
function hpos_ardxoz_pagoqr_footer_popup() {
    if ( ! is_checkout() && ! has_block( 'woocommerce/checkout' ) ) return;

    $gateway = new HPOS_ARDXOZ_WC_Gateway_QR();
    if ( $gateway->enabled !== 'yes' ) return;

    $qr_image = $gateway->get_option( 'preview_qr' );
    $phone = $gateway->get_option( 'number_telephone' );
    $limit = $gateway->get_option( 'limit_amount' );
    $limit_msg = $gateway->get_option( 'message_limit_amount' );
    
    ?>
    <div id="hpos-ardxoz-pagoqr-popup" class="hpos-ardxoz-pagoqr-overlay" style="display: none;">
        <div class="hpos-ardxoz-pagoqr-modal">
            <span class="hpos-ardxoz-pagoqr-close">&times;</span>
            
            <div class="step-1" data-limit="<?php echo esc_attr($limit); ?>">
                <h3>Escanea y Paga</h3>
                <?php if ( $qr_image ) : ?>
                    <div class="qr-container">
                        <img src="<?php echo esc_url( $qr_image ); ?>" class="qr-main-img" alt="Código QR de Pago">
                        <div class="qr-actions">
                            <a href="<?php echo esc_url( $qr_image ); ?>" download="qr-pago.png" class="qr-action-btn download-link" title="Descargar QR">
                                <span class="dashicons dashicons-download"></span> Descargar
                            </a>
                            <button type="button" class="qr-action-btn share-btn" data-url="<?php echo esc_url( $qr_image ); ?>">
                                <span class="dashicons dashicons-share"></span> Compartir
                            </button>
                        </div>
                    </div>
                <?php endif; ?>
                
                <div class="payment-details">
                    <p><strong>Total a pagar:</strong> <span class="order-total-placeholder"></span></p>
                    <?php if ( $phone ) : ?>
                        <p><strong>Contacto/Billetera:</strong> <?php echo esc_html( $phone ); ?></p>
                    <?php endif; ?>
                </div>

                <div class="limit-error" style="display: none; color: #d63638; margin-bottom: 15px;">
                    <?php echo esc_html( $limit_msg ); ?>
                </div>

                <div class="popup-actions">
                    <button type="button" class="btn-continue">Subir Comprobante</button>
                    <button type="button" class="btn-finalizar-directo">Finalizar ahora</button>
                </div>
            </div>

            <div class="step-2" style="display: none;">
                <h3>Sube tu comprobante</h3>
                <p>Por favor, adjunta una captura de pantalla o foto del pago realizado.</p>
                
                <form id="hpos-ardxoz-pagoqr-upload-form">
                    <div class="upload-box">
                        <input type="file" id="hpos-ardxoz-pagoqr-file" accept="image/*">
                        <label for="hpos-ardxoz-pagoqr-file">Arrastra aquí o haz clic para seleccionar</label>
                        <div class="file-name-display"></div>
                    </div>
                    
                    <div class="msg-area"></div>
                    <div class="loader-wrapper" style="display: none;">
                        <img src="<?php echo plugins_url( 'assets/img/loader.gif', dirname(__FILE__) ); ?>" style="width: 50px; display: block; margin: 10px auto;">
                        <p>Procesando...</p>
                    </div>
                    
                    <button type="button" class="button alt btn-finalizar">Finalizar Pedido</button>
                </form>
            </div>
        </div>
    </div>
    <?php
}
add_action( 'wp_footer', 'hpos_ardxoz_pagoqr_footer_popup' );

/**
 * Handler AJAX para subir el comprobante
 */
function hpos_ardxoz_pagoqr_handle_upload() {
    if ( ! isset( $_FILES['image'] ) ) {
        wp_send_json_error( 'No se recibió ninguna imagen.' );
    }

    require_once( ABSPATH . 'wp-admin/includes/file.php' );
    require_once( ABSPATH . 'wp-admin/includes/image.php' );
    require_once( ABSPATH . 'wp-admin/includes/media.php' );

    // Filtro temporal para cambiar el directorio de subida (privacidad)
    add_filter( 'upload_dir', 'hpos_ardxoz_pagoqr_custom_upload_dir' );

    $uploaded_file = wp_handle_upload( $_FILES['image'], array( 'test_form' => false ) );

    remove_filter( 'upload_dir', 'hpos_ardxoz_pagoqr_custom_upload_dir' );

    if ( isset( $uploaded_file['url'] ) ) {
        // Guardar en la sesión de WooCommerce
        WC()->session->set( 'hpos_ardxoz_pagoqr_receipt_url', $uploaded_file['url'] );
        wp_send_json_success( 'Subido correctamente.' );
    } else {
        wp_send_json_error( $uploaded_file['error'] ?? 'Error al subir el archivo.' );
    }
}
add_action( 'wp_ajax_hpos_ardxoz_pagoqr_upload', 'hpos_ardxoz_pagoqr_handle_upload' );
add_action( 'wp_ajax_nopriv_hpos_ardxoz_pagoqr_upload', 'hpos_ardxoz_pagoqr_handle_upload' );

function hpos_ardxoz_pagoqr_custom_upload_dir( $dir ) {
    $subdir = '/hpos-ardxoz-comprobantes-qr';
    $dir['path']   = $dir['basedir'] . $subdir;
    $dir['url']    = $dir['baseurl'] . $subdir;
    $dir['subdir'] = $subdir;

    if ( ! is_dir( $dir['path'] ) ) {
        mkdir( $dir['path'], 0755, true );
        // Evitar listado de directorio
        file_put_contents( $dir['path'] . '/index.html', '' );
    }

    return $dir;
}

/**
 * Meta Box en el pedido (HPOS Compatible)
 */
function hpos_ardxoz_pagoqr_register_meta_box() {
    $screens = array( 'shop_order' );
    
    // Si HPOS está activo, la pantalla de pedidos cambia
    if ( class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' ) ) {
        $screens[] = 'woocommerce_page_wc-orders';
    }
    
    add_meta_box( 
        'hpos-ardxoz-pagoqr-receipt-box', 
        'Comprobante de Pago QR', 
        'hpos_ardxoz_pagoqr_render_meta_box', 
        $screens, 
        'side', 
        'default' 
    );
}
add_action( 'add_meta_boxes', 'hpos_ardxoz_pagoqr_register_meta_box' );

function hpos_ardxoz_pagoqr_render_meta_box( $post_or_order ) {
    $order = ( $post_or_order instanceof WP_Post ) ? wc_get_order( $post_or_order->ID ) : $post_or_order;
    if ( ! $order ) return;

    $receipt_url = $order->get_meta( '_hpos_ardxoz_pagoqr_receipt' );

    if ( $receipt_url ) {
        echo '<div style="text-align:center;">';
        echo '<a href="' . esc_url( $receipt_url ) . '" target="_blank">';
        echo '<img src="' . esc_url( $receipt_url ) . '" style="max-width:100%; height:auto; border:1px solid #ccc; padding:5px;">';
        echo '</a>';
        echo '<p><small>Haz clic en la imagen para ver en tamaño completo.</small></p>';
        echo '</div>';
    } else {
        echo '<p>No se adjuntó ningún comprobante para este pedido.</p>';
    }
}
