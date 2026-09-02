/**
 * Copiado del resumen de venta al portapapeles.
 *
 * navigator.clipboard exige contexto seguro (HTTPS) y un gesto del usuario.
 * Ambos se cumplen aquí, pero el WebView que abre WhatsApp al pulsar un enlace
 * a veces no expone la API — de ahí el respaldo con textarea + execCommand,
 * que sigue funcionando en ese entorno.
 */
(function () {
    'use strict';

    var TEXTO_OK = (window.vcvCatalogo && window.vcvCatalogo.copiado) || '¡Copiado!';
    var TEXTO_ERR = (window.vcvCatalogo && window.vcvCatalogo.error) || 'No se pudo copiar';

    function copiarConRespaldo(texto) {
        var area = document.createElement('textarea');
        area.value = texto;
        // Fuera de pantalla, pero enfocable: iOS ignora los elementos ocultos.
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.top = '0';
        area.style.left = '-9999px';
        document.body.appendChild(area);

        var seleccionPrevia = document.getSelection().rangeCount > 0
            ? document.getSelection().getRangeAt(0)
            : null;

        area.select();
        area.setSelectionRange(0, area.value.length);

        var ok = false;
        try {
            ok = document.execCommand('copy');
        } catch (e) {
            ok = false;
        }

        document.body.removeChild(area);

        if (seleccionPrevia) {
            document.getSelection().removeAllRanges();
            document.getSelection().addRange(seleccionPrevia);
        }

        return ok;
    }

    function copiar(texto) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(texto).then(
                function () { return true; },
                function () { return copiarConRespaldo(texto); }
            );
        }
        return Promise.resolve(copiarConRespaldo(texto));
    }

    function avisar(boton, ok) {
        var etiqueta = boton.querySelector('.vcv-copy-label');
        if (!etiqueta) {
            return;
        }

        if (boton.dataset.vcvOriginal === undefined) {
            boton.dataset.vcvOriginal = etiqueta.textContent;
        }

        etiqueta.textContent = ok ? TEXTO_OK : TEXTO_ERR;
        boton.classList.add(ok ? 'is-ok' : 'is-error');

        window.clearTimeout(boton._vcvTimer);
        boton._vcvTimer = window.setTimeout(function () {
            etiqueta.textContent = boton.dataset.vcvOriginal;
            boton.classList.remove('is-ok', 'is-error');
        }, 1800);
    }

    document.addEventListener('click', function (evento) {
        var boton = evento.target.closest ? evento.target.closest('.vcv-copy') : null;
        if (!boton) {
            return;
        }

        evento.preventDefault();

        // El texto vive en el <textarea> hermano: es el único contenedor que
        // conserva los saltos de línea literales. El data- se mantiene solo como
        // respaldo por si alguna pantalla vieja quedó cacheada.
        var fuente = boton.parentNode
            ? boton.parentNode.querySelector('.vcv-copy-src')
            : null;

        var texto = fuente ? fuente.value : (boton.getAttribute('data-vcv-text') || '');

        if (!texto) {
            avisar(boton, false);
            return;
        }

        copiar(texto).then(function (ok) {
            avisar(boton, ok);
        });
    });
}());
