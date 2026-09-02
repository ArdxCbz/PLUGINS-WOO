<?php
/**
 * Admin Cleanup (HPOS)
 *
 * MIGRADO — El recorte del backend para el rol "vendedor" y la página
 * "Productos" personalizada se trasladaron al plugin `ventova-catalogo-vendedor`
 * (clases `VCV_Menu` y `VCV_Catalogo`).
 *
 * Motivos del traslado:
 *   - La página listaba TODO el catálogo (`posts_per_page => -1`) y llamaba
 *     `get_available_variations()` por producto, la llamada más cara de
 *     WooCommerce. Ahora el coste es constante (~6 consultas por página).
 *   - Los productos SIMPLES siempre mostraban "Sin stock" porque el render solo
 *     contemplaba `is_type('variable')`.
 *   - No había buscador, precio ni enlace a la ficha del producto.
 *
 * Aquí solo queda el arreglo del conflicto de JS de WPForms.
 */

if (!defined('ABSPATH')) {
    exit;
}

// #### F) SOLUCION DE CONFLICTOS JS
// WPForms carga scripts de educación que asumen la presencia del editor de bloques, causando errores en la lista de pedidos.
add_action('admin_enqueue_scripts', 'wca_fix_wpforms_conflict', 9999);
function wca_fix_wpforms_conflict($hook)
{
    // En HPOS la lista de pedidos usa woocommerce_page_wc-orders
    if ($hook === 'woocommerce_page_wc-orders') {
        // Desencolar scripts de WPForms que causan conflictos
        wp_dequeue_script('wpforms-admin-education');
        wp_dequeue_script('wpforms-elementor');
        wp_dequeue_script('wpforms-admin');
    }
}
