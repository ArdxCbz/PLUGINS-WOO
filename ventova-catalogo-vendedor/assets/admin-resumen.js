/**
 * Metabox "Descripción resumida": contador de longitud, relleno desde la
 * descripción larga y vista previa con los tokens ya resueltos.
 */
(function () {
    'use strict';

    var cfg = window.vcvResumen || {};

    var textarea = document.getElementById('vcv-resumen-texto');
    var btnGen = document.getElementById('vcv-regenerar');
    var btnPrev = document.getElementById('vcv-previsualizar');
    var estado = document.getElementById('vcv-resumen-estado');
    var preview = document.getElementById('vcv-resumen-preview');
    var contador = document.getElementById('vcv-resumen-contador');

    if (!textarea || !btnGen) {
        return;
    }

    // Referencia, no límite: un mensaje que se lee de un vistazo en WhatsApp
    // ronda los 900 caracteres. Pasado eso el cliente ve un muro de texto.
    var HOLGADO = 900;
    var LARGO = 1500;

    function contar() {
        if (!contador) {
            return;
        }

        var n = textarea.value.length;

        if (n === 0) {
            contador.textContent = 'Vacío — se copiará un borrador derivado de la descripción larga.';
            contador.className = 'vcv-resumen-contador is-vacio';
            return;
        }

        var nota = '';
        var clase = '';

        if (n > LARGO) {
            nota = ' — demasiado largo para enviar por WhatsApp';
            clase = ' is-largo';
        } else if (n > HOLGADO) {
            nota = ' — algo largo';
            clase = ' is-holgado';
        }

        contador.textContent = n + ' caracteres' + nota;
        contador.className = 'vcv-resumen-contador' + clase;
    }

    textarea.addEventListener('input', contar);
    contar();

    function decir(mensaje, tipo) {
        if (!estado) {
            return;
        }
        estado.textContent = mensaje || '';
        estado.className = 'vcv-resumen-estado' + (tipo ? ' is-' + tipo : '');
    }

    function pedir(datos) {
        var cuerpo = new URLSearchParams();
        cuerpo.append('action', cfg.action);
        cuerpo.append('product_id', btnGen.dataset.product);
        cuerpo.append('nonce', btnGen.dataset.nonce);

        Object.keys(datos).forEach(function (clave) {
            cuerpo.append(clave, datos[clave]);
        });

        return fetch(cfg.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: cuerpo.toString()
        }).then(function (r) {
            return r.json();
        });
    }

    btnGen.addEventListener('click', function () {
        // Sobrescribe lo redactado: se confirma antes.
        if (textarea.value.trim() !== '') {
            var seguir = window.confirm(
                cfg.confirmar || 'Esto reemplazará el texto actual por un borrador nuevo. ¿Continuar?'
            );
            if (!seguir) {
                return;
            }
        }

        btnGen.disabled = true;
        decir(cfg.generando, 'trabajando');

        pedir({ modo: 'generar' }).then(function (respuesta) {
            btnGen.disabled = false;

            if (!respuesta || !respuesta.success) {
                decir((respuesta && respuesta.data && respuesta.data.message) || cfg.error, 'error');
                return;
            }

            textarea.value = respuesta.data.texto;
            contar();
            decir(cfg.listo, 'ok');
        }).catch(function () {
            btnGen.disabled = false;
            decir(cfg.error, 'error');
        });
    });

    if (btnPrev && preview) {
        btnPrev.addEventListener('click', function () {
            btnPrev.disabled = true;
            decir(cfg.generando, 'trabajando');

            pedir({ modo: 'preview', plantilla: textarea.value }).then(function (respuesta) {
                btnPrev.disabled = false;

                if (!respuesta || !respuesta.success) {
                    decir((respuesta && respuesta.data && respuesta.data.message) || cfg.error, 'error');
                    return;
                }

                preview.textContent = respuesta.data.texto;
                preview.hidden = false;
                decir('', '');
            }).catch(function () {
                btnPrev.disabled = false;
                decir(cfg.error, 'error');
            });
        });
    }
}());
