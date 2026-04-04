<?php
/**
 * Ventova Store Child — Auto-recarga de la página de pedidos HPOS
 *
 * Recarga automáticamente la página de pedidos de WooCommerce (HPOS)
 * cada 3 minutos para roles específicos (administrador y vendedor).
 *
 * Página HPOS: wp-admin/admin.php?page=wc-orders
 *
 * @package Ventova_Store_Child
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Encola el script de auto-recarga solo en la página de pedidos HPOS
 * y solo para los roles configurados.
 */
function ventova_child_autoreload_orders() {

    // Solo en el admin
    if ( ! is_admin() ) return;

    // Solo para administradores y vendedores
    if ( ! current_user_can( 'administrator' ) && ! current_user_can( 'vendedor' ) ) return;

    // Solo en la página de pedidos HPOS: admin.php?page=wc-orders
    $screen = get_current_screen();
    if ( ! $screen || $screen->id !== 'woocommerce_page_wc-orders' ) return;

    // Intervalo en milisegundos (3 minutos = 180 000 ms)
    $interval_ms = 3 * 60 * 1000;

    wp_add_inline_script(
        'jquery',
        sprintf(
            '(function() {
                "use strict";
                var interval = %d;
                var timer    = null;
                var countdown = interval / 1000;

                // ── Crear el indicador visual ──
                var bar = document.createElement("div");
                bar.id  = "vs-autoreload-bar";
                Object.assign(bar.style, {
                    position:   "fixed",
                    bottom:     "0",
                    left:       "0",
                    width:      "100%%",
                    height:     "3px",
                    background: "linear-gradient(90deg, #2271b1, #72aee6)",
                    zIndex:     "999999",
                    transformOrigin: "left center",
                    transform:  "scaleX(1)",
                    transition: "transform " + (interval / 1000) + "s linear"
                });
                document.body.appendChild(bar);

                // ── Tooltip de cuenta regresiva ──
                var tip = document.createElement("div");
                tip.id  = "vs-autoreload-tip";
                Object.assign(tip.style, {
                    position:   "fixed",
                    bottom:     "8px",
                    right:      "12px",
                    fontSize:   "11px",
                    color:      "#72aee6",
                    fontFamily: "monospace",
                    zIndex:     "999999",
                    background: "rgba(0,0,0,0.5)",
                    padding:    "2px 8px",
                    borderRadius: "4px",
                    pointerEvents: "none"
                });
                document.body.appendChild(tip);

                function updateTip() {
                    var m = Math.floor(countdown / 60);
                    var s = countdown %% 60;
                    tip.textContent = "↻ " + m + ":" + (s < 10 ? "0" : "") + s;
                    if (countdown > 0) countdown--;
                }

                function startTimer() {
                    countdown = interval / 1000;
                    updateTip();

                    // Animar la barra de progreso
                    setTimeout(function() {
                        bar.style.transform = "scaleX(0)";
                    }, 50);

                    // Actualizar el contador cada segundo
                    var tick = setInterval(function() {
                        updateTip();
                        if (countdown <= 0) clearInterval(tick);
                    }, 1000);

                    // Recargar al terminar
                    timer = setTimeout(function() {
                        window.location.reload();
                    }, interval);
                }

                // Resetear si el usuario interactúa con la página
                function resetTimer() {
                    clearTimeout(timer);
                    countdown = interval / 1000;
                    bar.style.transition = "none";
                    bar.style.transform  = "scaleX(1)";
                    setTimeout(function() {
                        bar.style.transition = "transform " + (interval / 1000) + "s linear";
                        startTimer();
                    }, 100);
                }

                // Iniciar al cargar
                startTimer();

                // Resetear si el usuario filtra, ordena o navega en la tabla
                document.addEventListener("change",  resetTimer);
                document.addEventListener("submit",  resetTimer);

            })()',
            $interval_ms
        )
    );
}
add_action( 'admin_enqueue_scripts', 'ventova_child_autoreload_orders', 20 );
