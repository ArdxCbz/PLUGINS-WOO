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

if (!defined('ABSPATH'))
    exit;

/**
 * Encola el script de auto-recarga solo en la página de listado de pedidos
 * (soporta HPOS y Legacy) y solo para los roles configurados.
 */
function ventova_child_autoreload_orders()
{

    // Solo en el admin
    if (!is_admin())
        return;

    // Solo para administradores y vendedores
    if (!current_user_can('administrator') && !current_user_can('vendedor'))
        return;

    // ── Novedad: Excluir a los administradores que usan DEMV ──
    $current_user = wp_get_current_user();

    // Coloca aquí los IDs o correos de los usuarios que NO deben tener auto-recarga
    $usuarios_excluidos = array(
        'armandxcrazy@gmail.com',
        // 123, // También se puede excluir por ID numérico de usuario
    );

    if (in_array($current_user->user_email, $usuarios_excluidos, true) || in_array($current_user->ID, $usuarios_excluidos, true)) {
        return; // No imprimir el script para ellos
    }

    // Validar pantalla actual
    $screen = get_current_screen();
    if (!$screen)
        return;

    // Pantalla HPOS (woocommerce_page_wc-orders) o Pantalla Legacy (edit-shop_order)
    if ($screen->id !== 'woocommerce_page_wc-orders' && $screen->id !== 'edit-shop_order')
        return;

    // Evitar recargar si se está EDITANDO un pedido individual (muy importante para no perder datos)
    if (isset($_GET['action']) && $_GET['action'] === 'edit')
        return;
    if (isset($_GET['post']) && isset($_GET['action']) && $_GET['action'] === 'edit')
        return;

    // Intervalo en milisegundos (3 minutos = 180 000 ms)
    $interval_ms = 3 * 60 * 1000;
    ?>
    <script type="text/javascript">
        (function () {
            "use strict";
            var interval = <?php echo intval($interval_ms); ?>;
            var timer = null;
            var countdown = interval / 1000;

            // ── Crear el indicador visual ──
            var bar = document.createElement("div");
            bar.id = "vs-autoreload-bar";
            Object.assign(bar.style, {
                position: "fixed",
                bottom: "0",
                left: "0",
                width: "100%",
                height: "4px",
                background: "linear-gradient(90deg, #2563eb, #3b82f6, #93c5fd)",
                zIndex: "9999",
                transformOrigin: "left center",
                transform: "scaleX(1)",
                transition: "transform " + (interval / 1000) + "s linear"
            });
            document.body.appendChild(bar);

            // ── Tooltip de cuenta regresiva ──
            var tip = document.createElement("div");
            tip.id = "vs-autoreload-tip";
            Object.assign(tip.style, {
                position: "fixed",
                bottom: "12px",
                left: "10px", // Movido a la izquierda
                fontSize: "12px",
                color: "#fff",
                fontWeight: "600",
                fontFamily: "monospace",
                zIndex: "9999",
                background: "rgba(15, 23, 42, 0.75)",
                backdropFilter: "blur(4px)",
                padding: "4px 10px",
                borderRadius: "6px",
                pointerEvents: "none",
                boxShadow: "0 2px 4px rgba(0,0,0,0.1)"
            });
            document.body.appendChild(tip);

            function updateTip() {
                var m = Math.floor(countdown / 60);
                var s = countdown % 60;
                tip.textContent = "↻ Recarga en " + m + ":" + (s < 10 ? "0" : "") + s;
                if (countdown > 0) countdown--;
            }

            function startTimer() {
                countdown = interval / 1000;
                updateTip();

                // Animar la barra de progreso
                setTimeout(function () {
                    bar.style.transform = "scaleX(0)";
                }, 50);

                // Actualizar el contador cada segundo
                var tick = setInterval(function () {
                    updateTip();
                    if (countdown <= 0) clearInterval(tick);
                }, 1000);

                // Recargar al terminar
                timer = setTimeout(function () {
                    window.location.reload();
                }, interval);
            }

            // Resetear si el usuario interactúa con la página (cambios, búsquedas)
            function resetTimer() {
                clearTimeout(timer);
                countdown = interval / 1000;
                bar.style.transition = "none";
                bar.style.transform = "scaleX(1)";
                setTimeout(function () {
                    bar.style.transition = "transform " + (interval / 1000) + "s linear";
                    startTimer();
                }, 100);
            }

            // Iniciar al cargar
            startTimer();

            // Detectar si navega paginaciones o filtros (via ajax si fuera el caso, pero usualmente es submit/click)
            document.addEventListener("change", resetTimer);
            document.addEventListener("submit", resetTimer);
            document.addEventListener("keyup", resetTimer); // resetear al escribir en buscador

        })();
    </script>
    <?php
}
add_action('admin_footer', 'ventova_child_autoreload_orders', 99);

