jQuery(document).ready(function($) {
    'use strict';

    var currentFile  = null;
    var popupCompleted = false;

    // ─── DETECCIÓN DEL MÉTODO DE PAGO ──────────────────────────────────────

    // Tras cualquier AJAX de WooCommerce (actualización de checkout)
    $(document).ajaxComplete(function() {
        syncButtonClass();
    });

    // Cuando el usuario cambia el método de pago manualmente
    $('form.checkout').on('change', 'input[name^="payment_method"]', function() {
        syncButtonClass();
    });

    // Sincronización al cargar la página
    syncButtonClass();

    function syncButtonClass() {
        var selected = $('input[name^="payment_method"]:checked').val();
        var $btn = $('.place-order button, #place_order');
        if (selected === 'hpos_ardxoz_pagoqr') {
            $btn.addClass('hpos-ardxoz-pagoqr-active');
        } else {
            $btn.removeClass('hpos-ardxoz-pagoqr-active');
        }
    }

    // ─── INTERCEPTAR CLIC EN "REALIZAR PEDIDO" (LEGACY) ───────────────────

    $(document.body).on('click', '.hpos-ardxoz-pagoqr-active', function(e) {
        if (popupCompleted) {
            popupCompleted = false;
            return true; // Dejar pasar el submit real
        }
        e.preventDefault();
        openPopup();
        return false;
    });

    // ─── INTERCEPTAR CLIC EN CHECKOUT DE BLOQUES ──────────────────────────

    $(document).on('click', '.wc-block-components-checkout-place-order-button', function(e) {
        if (popupCompleted) {
            return true;
        }
        var selected = getSelectedBlocksMethod();
        if (selected !== 'hpos_ardxoz_pagoqr') {
            return true;
        }
        e.preventDefault();
        e.stopImmediatePropagation();
        openPopup();
        return false;
    });

    function getSelectedBlocksMethod() {
        var $input = $('input[name="radio-control-wc-payment-method-options"]:checked');
        return $input.length ? $input.val() : '';
    }

    // ─── ABRIR POPUP ──────────────────────────────────────────────────────

    function openPopup() {
        var $popup = $('#hpos-ardxoz-pagoqr-popup');
        if (!$popup.length) return;

        // Total legacy
        var totalText = '';
        var $legacyTotal = $('.order-total .amount').first();
        if ($legacyTotal.length) {
            totalText = $legacyTotal.text();
        }
        // Total bloques
        if (!totalText) {
            var $blocksTotal = $('.wc-block-components-totals-footer-item .wc-block-components-totals-item__value').last();
            if ($blocksTotal.length) {
                totalText = $blocksTotal.text();
            }
        }

        $popup.find('.order-total-placeholder').text(totalText || '');
        $popup.fadeIn();

        // Reset a paso 1
        $popup.find('.step-1').show();
        $popup.find('.step-2').hide();
        currentFile = null;
        $('.file-name-display').text('');
        $('.msg-area').hide().removeClass('error');
        $('.btn-finalizar').prop('disabled', false);
        $('.loader-wrapper').hide();
    }

    // ─── FINALIZAR PEDIDO (tras cerrar popup) ─────────────────────────────

    function finalizarPedido() {
        popupCompleted = true;
        $('#hpos-ardxoz-pagoqr-popup').fadeOut();

        // Legacy
        var $legacyBtn = $('.place-order button, #place_order');
        if ($legacyBtn.length && $legacyBtn.is(':visible')) {
            $legacyBtn.removeClass('hpos-ardxoz-pagoqr-active').trigger('click');
            return;
        }

        // Bloques
        var $blocksBtn = $('.wc-block-components-checkout-place-order-button');
        if ($blocksBtn.length) {
            $blocksBtn.trigger('click');
        }
    }

    // ─── CONTROLES DEL POPUP ──────────────────────────────────────────────

    // Cerrar con ×
    $(document).on('click', '.hpos-ardxoz-pagoqr-close', function() {
        $('#hpos-ardxoz-pagoqr-popup').fadeOut();
    });

    // Cerrar con ESC
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape') {
            $('#hpos-ardxoz-pagoqr-popup').fadeOut();
        }
    });

    // Paso 1 → Paso 2 (subir comprobante)
    $(document).on('click', '.btn-continue', function() {
        $('.step-1').hide();
        $('.step-2').fadeIn();
    });

    // Finalizar directamente desde Paso 1 (sin comprobante)
    $(document).on('click', '.btn-finalizar-directo', function() {
        finalizarPedido();
    });

    // ─── DESCARGA DEL QR ─────────────────────────────────────────────────
    // El atributo download en el <a> ya maneja esto de forma nativa.
    // Este bloque fuerza la descarga para imágenes cross-origin vía fetch.
    $(document).on('click', '.download-link', function(e) {
        var url = $(this).attr('href');
        var filename = $(this).attr('download') || 'qr-pago.png';
        if (!url) return;

        // Si es misma origin, descarga directa (el atributo download es suficiente)
        try {
            var origin = new URL(url).origin;
            if (origin === window.location.origin) return; // Dejar comportamiento nativo
        } catch(err) {}

        // Cross-origin: forzar via blob
        e.preventDefault();
        fetch(url)
            .then(function(res) { return res.blob(); })
            .then(function(blob) {
                var a = document.createElement('a');
                a.href = URL.createObjectURL(blob);
                a.download = filename;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                URL.revokeObjectURL(a.href);
            })
            .catch(function() {
                window.open(url, '_blank');
            });
    });

    // ─── COMPARTIR QR ─────────────────────────────────────────────────────
    if (navigator.share) {
        $('.share-btn').addClass('is-visible');
    }

    $(document).on('click', '.share-btn', function() {
        var qrUrl = $(this).data('url');
        if (!qrUrl) return;

        if (navigator.share) {
            navigator.share({
                title: 'Código QR de Pago',
                text: 'Escanea este QR para realizar el pago de tu pedido.',
                url: qrUrl
            }).catch(function(err) {
                if (err.name !== 'AbortError') {
                    window.open(qrUrl, '_blank');
                }
            });
        } else {
            window.open(qrUrl, '_blank');
        }
    });

    // ─── DRAG & DROP DE COMPROBANTE ───────────────────────────────────────

    $(document).on('dragover dragenter', '.upload-box', function(e) {
        e.preventDefault();
        $(this).addClass('dragover');
    });
    $(document).on('dragleave dragend drop', '.upload-box', function() {
        $(this).removeClass('dragover');
    });
    $(document).on('drop', '.upload-box', function(e) {
        e.preventDefault();
        var files = e.originalEvent.dataTransfer.files;
        if (files.length) handleFileSelect(files[0]);
    });
    $(document).on('change', '#hpos-ardxoz-pagoqr-file', function() {
        if (this.files.length) handleFileSelect(this.files[0]);
    });

    function handleFileSelect(file) {
        if (!file.type.match('image.*')) {
            alert(hpos_ardxoz_pagoqr_params.texts.error_image);
            return;
        }
        currentFile = file;
        $('.file-name-display').text('Archivo seleccionado: ' + file.name);
    }

    // ─── BOTÓN FINALIZAR (Paso 2) ─────────────────────────────────────────

    $(document).on('click', '.btn-finalizar', function() {
        var $btn    = $(this);
        var $loader = $('.loader-wrapper');
        var $msg    = $('.msg-area');

        if (!currentFile) {
            finalizarPedido();
            return;
        }

        $btn.prop('disabled', true);
        $loader.show();
        $msg.hide();

        var formData = new FormData();
        formData.append('action', 'hpos_ardxoz_pagoqr_upload');
        formData.append('image', currentFile);

        $.ajax({
            url: hpos_ardxoz_pagoqr_params.ajax_url,
            type: 'POST',
            data: formData,
            contentType: false,
            processData: false,
            success: function(response) {
                if (response.success) {
                    finalizarPedido();
                } else {
                    $msg.addClass('error').text(response.data).show();
                    $btn.prop('disabled', false);
                    $loader.hide();
                }
            },
            error: function() {
                $msg.addClass('error').text('Error de conexión. Inténtalo de nuevo.').show();
                $btn.prop('disabled', false);
                $loader.hide();
            }
        });
    });

});
