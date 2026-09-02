<?php
namespace HPOS\Ardxoz\Woo\Orders;

defined('ABSPATH') || exit;

class Tutorial_SCZ
{
    public static function init()
    {
        add_action('admin_footer', [__CLASS__, 'render_tutorial']);
        add_action('admin_notices', [__CLASS__, 'render_trigger_button']);
    }

    public static function is_orders_screen()
    {
        $screen = get_current_screen();
        if (!$screen) {
            return false;
        }
        return (strpos($screen->id, 'wc-orders') !== false || strpos($screen->id, 'shop_order') !== false);
    }

    public static function render_trigger_button()
    {
        if (!self::is_orders_screen()) {
            return;
        }

        ?>
        <script>
        (function() {
            function initBtn() {
                var h1 = document.querySelector('.wp-heading-inline') || document.querySelector('.wrap h1') || document.querySelector('h1');
                if (h1 && !document.getElementById('hawo-btn-tutorial-scz')) {
                    var btn = document.createElement('button');
                    btn.type = 'button';
                    btn.id = 'hawo-btn-tutorial-scz';
                    btn.className = 'button button-primary page-title-action';
                    btn.style.cssText = 'background:#8e44ad; border-color:#7d3c98; font-weight:bold; font-size:13px; padding:4px 12px; margin-left:10px; border-radius:6px; box-shadow:0 2px 5px rgba(142,68,173,0.3); display:inline-flex; align-items:center; gap:6px; cursor:pointer; vertical-align:middle;';
                    btn.innerHTML = '<span>🎓 Guía de Despacho SCZ</span>';
                    h1.insertAdjacentElement('afterend', btn);
                }
            }
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initBtn);
            } else {
                initBtn();
            }
        })();
        </script>
        <?php
    }

    public static function render_tutorial()
    {
        if (!self::is_orders_screen()) {
            return;
        }

        ?>
        <!-- Overlay del Tutorial -->
        <div id="hawo-tutorial-overlay" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.65); z-index:999990; backdrop-filter: blur(2px);"></div>

        <!-- Tarjeta del Tutorial -->
        <div id="hawo-tutorial-card" style="display:none; position:fixed; top:50%; left:50%; transform:translate(-50%, -50%); width:90%; max-width:540px; background:#ffffff; border-radius:12px; box-shadow:0 10px 30px rgba(0,0,0,0.4); z-index:999999; padding:24px; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; border-bottom:2px solid #f1f2f6; padding-bottom:10px;">
                <div style="display:flex; align-items:center; gap:8px;">
                    <span style="background:#8e44ad; color:#fff; padding:3px 8px; border-radius:12px; font-size:11px; font-weight:bold; text-transform:uppercase;" id="hawo-tut-step-badge">Paso 1 de 6</span>
                    <h3 style="margin:0; font-size:16px; color:#2c3e50; font-weight:700;" id="hawo-tut-title">Título</h3>
                </div>
                <button type="button" id="hawo-tut-close" style="background:none; border:none; font-size:20px; cursor:pointer; color:#a4b0be; line-height:1;">&times;</button>
            </div>

            <div id="hawo-tut-body" style="font-size:14px; color:#34495e; line-height:1.6; min-height:140px;">
                <!-- Contenido dinámico -->
            </div>

            <div style="display:flex; justify-content:space-between; align-items:center; margin-top:20px; border-top:1px solid #f1f2f6; padding-top:16px;">
                <button type="button" id="hawo-tut-prev" class="button" style="display:none;">&larr; Anterior</button>
                <div style="flex:1;"></div>
                <button type="button" id="hawo-tut-next" class="button button-primary" style="background:#8e44ad; border-color:#7d3c98; font-weight:bold;">Siguiente &rarr;</button>
            </div>
        </div>

        <style>
            .haw-tutorial-highlight {
                position: relative !important;
                z-index: 999995 !important;
                box-shadow: 0 0 0 4px #8e44ad, 0 0 20px rgba(142, 68, 173, 0.6) !important;
                border-radius: 4px;
                transition: all 0.3s ease;
            }
        </style>

        <script>
        (function() {
            var steps = [
                {
                    title: "1. Identificación Visual (SCZ)",
                    targetSelector: ".haw-sucursal-scz",
                    body: "<p><strong>Fondo Verde & Badge SCZ:</strong></p>" +
                          "<ul>" +
                          "<li>Cada pedido de la sucursal Santa Cruz tiene toda la fila marcada en <strong>Verde Suave</strong>.</li>" +
                          "<li>En la columna <strong>Estado</strong>, verifica que debajo del estado figure la casilla verde <strong><code>SCZ</code></strong> (ej. <em>SCZ &rarr; LA PAZ</em> o <em>SCZ &rarr; SANTA CRUZ</em>).</li>" +
                          "</ul>" +
                          "<p style='color:#e74c3c; font-size:12px;'>⚠️ Si la fila es Celeste (CBBA) o no indica SCZ, pertenece a otra sucursal y NO debes procesarla.</p>"
                },
                {
                    title: "2. Verificación de Estado Nuevo",
                    targetSelector: ".order-status.status-processing",
                    body: "<p><strong>Estado Procesando:</strong></p>" +
                          "<p>El estado del pedido debe marcar <strong><code>Procesando</code></strong> (badge verde menta).</p>" +
                          "<p>Esto indica que es un <strong>nuevo pedido confirmado</strong> listo para su preparación y embalaje.</p>"
                },
                {
                    title: "3. Impresión & Control de Cantidad (CRÍTICO)",
                    targetSelector: ".order_products, .haw-action-print",
                    body: "<p><strong>Pasos de Control:</strong></p>" +
                          "<ol>" +
                          "<li>Haz clic en el botón violeta 🖨️ (columna Acciones) para imprimir la <strong>Nota de Entrega</strong>.</li>" +
                          "<li>Saca el producto del inventario y realiza la prueba física de funcionamiento.</li>" +
                          "<li><strong style='color:#c0392b;'>VERIFICACIÓN DE CANTIDAD (CRÍTICO):</strong> Revisa el multiplicador en rojo (ej. <span style='color:red;font-weight:bold;'>x1</span>, <span style='color:red;font-weight:bold;'>x2</span>) y comprueba que la cantidad probada coincida EXACTAMENTE con la Nota.</li>" +
                          "</ol>"
                },
                {
                    title: "4. Verificación de Forma de Envío",
                    targetSelector: ".hawo-info",
                    body: "<p><strong>Identificación de Despacho:</strong></p>" +
                          "<p>Revisa la casilla <em>Forma de Envío:</em> en la columna Información:</p>" +
                          "<ul>" +
                          "<li><strong>Badge Marrón (IBEX):</strong> Courier Interdepartamental.</li>" +
                          "<li><strong>Badge Verde (CBS):</strong> Delivery Local en Santa Cruz.</li>" +
                          "</ul>"
                },
                {
                    title: "5. Flujo para Envíos IBEX",
                    targetSelector: ".hawo-info",
                    body: "<p><strong>Despacho por IBEX (Courier):</strong></p>" +
                          "<ol>" +
                          "<li>Elabora la <strong>GUÍA de seguimiento</strong> del courier.</li>" +
                          "<li>Ingresa el número de Guía en el sistema (haciendo clic en la celda o modal).</li>" +
                          "<li>El paquete DEBE salir con <strong>2 DOCUMENTOS OBLIGATORIOS:</strong>" +
                          "<br>&bull; 📄 <strong>Nota de Entrega</strong>" +
                          "<br>&bull; 🏷️ <strong>Guía IBEX</strong></li>" +
                          "</ol>"
                },
                {
                    title: "6. Flujo para Envíos CBS (Delivery)",
                    targetSelector: ".hawo-info",
                    body: "<p><strong>Despacho por CBS (Delivery Local):</strong></p>" +
                          "<ol>" +
                          "<li>Coordina el envío directamente con el repartidor de <strong>Delivery Local</strong>.</li>" +
                          "<li>El paquete sale despachado adjuntando <strong>ÚNICAMENTE:</strong>" +
                          "<br>&bull; 📄 <strong>Nota de Entrega</strong> (No requiere Guía).</li>" +
                          "</ol>" +
                          "<p style='color:#27ae60; font-weight:bold;'>¡Listo! Has completado el entrenamiento de despacho SCZ.</p>"
                }
            ];

            var currentStep = 0;
            var overlay    = document.getElementById('hawo-tutorial-overlay');
            var card       = document.getElementById('hawo-tutorial-card');
            var badge      = document.getElementById('hawo-tut-step-badge');
            var title      = document.getElementById('hawo-tut-title');
            var body       = document.getElementById('hawo-tut-body');
            var btnPrev    = document.getElementById('hawo-tut-prev');
            var btnNext    = document.getElementById('hawo-tut-next');
            var btnClose   = document.getElementById('hawo-tut-close');

            function clearHighlights() {
                document.querySelectorAll('.haw-tutorial-highlight').forEach(function(el) {
                    el.classList.remove('haw-tutorial-highlight');
                });
            }

            function showStep(idx) {
                if (idx < 0 || idx >= steps.length) return;
                currentStep = idx;

                clearHighlights();

                var step = steps[idx];
                badge.textContent = "Paso " + (idx + 1) + " de " + steps.length;
                title.textContent = step.title;
                body.innerHTML    = step.body;

                if (idx === 0) {
                    btnPrev.style.display = 'none';
                } else {
                    btnPrev.style.display = 'inline-block';
                }

                if (idx === steps.length - 1) {
                    btnNext.textContent = '¡Entendido / Finalizar!';
                    btnNext.style.background = '#27ae60';
                    btnNext.style.borderColor = '#219952';
                } else {
                    btnNext.textContent = 'Siguiente \u2192';
                    btnNext.style.background = '#8e44ad';
                    btnNext.style.borderColor = '#7d3c98';
                }

                // Resaltar elemento
                if (step.targetSelector) {
                    var target = document.querySelector(step.targetSelector);
                    if (target) {
                        target.classList.add('haw-tutorial-highlight');
                        target.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                }
            }

            window.hawoOpenTutorialSCZ = function() {
                if (!overlay || !card) return;
                overlay.style.display = 'block';
                card.style.display    = 'block';
                showStep(0);
            };

            function closeTutorial() {
                if (overlay) overlay.style.display = 'none';
                if (card) card.style.display    = 'none';
                clearHighlights();
            }

            // Delegación global de eventos para el botón
            document.addEventListener('click', function(e) {
                var btn = e.target.closest('#hawo-btn-tutorial-scz');
                if (btn) {
                    e.preventDefault();
                    window.hawoOpenTutorialSCZ();
                }
            });

            if (btnClose) {
                btnClose.addEventListener('click', closeTutorial);
            }
            if (overlay) {
                overlay.addEventListener('click', closeTutorial);
            }

            if (btnPrev) {
                btnPrev.addEventListener('click', function() {
                    showStep(currentStep - 1);
                });
            }

            if (btnNext) {
                btnNext.addEventListener('click', function() {
                    if (currentStep < steps.length - 1) {
                        showStep(currentStep + 1);
                    } else {
                        closeTutorial();
                    }
                });
            }
        })();
        </script>
        <?php
    }
}
