jQuery(document).ready(function ($) {
    var params = hawd_params;

    // ── Modal open/close ──────────────────────────────────
    $('#hawd-open-btn').on('click', function () {
        $('#hawd-overlay').fadeIn();
        $('#hawd-modal').fadeIn();
        hideProgress();
        $('#hawd-results').hide();
    });

    $('#hawd-close-btn').on('click', function () {
        if (!$(this).prop('disabled')) {
            $('#hawd-modal').fadeOut();
            $('#hawd-overlay').fadeOut();
        }
    });

    $('#hawd-overlay').on('click', function () {
        if (!$('#hawd-close-btn').prop('disabled')) {
            $('#hawd-modal').fadeOut();
            $('#hawd-overlay').fadeOut();
        }
    });

    // ── Execute (Guardar) ────────────────────────────────
    $('#hawd-execute-btn').on('click', function () {
        var fecha = $('#hawd_fecha').val();
        var comprobante = $('#hawd_comprobante').val();

        if (!fecha || !comprobante) {
            showProgress('Completa la fecha de depósito y el número de comprobante.', 'error');
            return;
        }

        if (!params.search_term) {
            showProgress('No hay búsqueda activa.', 'error');
            return;
        }

        disableControls(true);
        showProgress('<span class="hawd-spinner"></span> Buscando y procesando pedidos...', '');

        $.post(params.ajax_url, {
            action: 'hawd_bulk_save',
            nonce: params.nonce,
            fecha: fecha,
            comprobante: comprobante,
            search_term: params.search_term
        })
        .done(function (res) {
            if (res.success) {
                var d = res.data;
                var cls = d.processed > 0 ? 'success' : 'warning';
                showProgress(d.message, cls);

                // Actualizar total con el real procesado
                $('#hawd_total').val(formatNumber(d.importe_total) + ' Bs');
                renderResultsDetail(d.results);
                $('#hawd-results').show();

                if (d.processed > 0) {
                    setTimeout(function () { location.reload(); }, 2500);
                } else {
                    disableControls(false);
                }
            } else {
                showProgress(res.data.message || 'Error desconocido', 'error');
                disableControls(false);
            }
        })
        .fail(function () {
            showProgress('Error de conexión. Intenta de nuevo.', 'error');
            disableControls(false);
        });
    });

    // ── Helpers ───────────────────────────────────────────
    function showProgress(msg, type) {
        $('#hawd-progress').removeClass('success error warning').addClass(type).html(msg).show();
    }

    function hideProgress() {
        $('#hawd-progress').hide().html('');
    }

    function disableControls(disabled) {
        $('#hawd-execute-btn, #hawd-close-btn').prop('disabled', disabled);
        $('#hawd_fecha, #hawd_comprobante').prop('disabled', disabled);
    }

    function renderResultsDetail(results) {
        if (!results || results.length === 0) {
            $('#hawd-results-detail').html('');
            return;
        }

        var rows = results.map(function (r) {
            var badge = '';
            if (r.status === 'processed') {
                badge = '<span class="hawd-badge hawd-badge--ok">Procesado</span>';
            } else if (r.status === 'skipped') {
                badge = '<span class="hawd-badge hawd-badge--skip">Omitido</span>';
            } else {
                badge = '<span class="hawd-badge hawd-badge--err">Error</span>';
            }

            var extra = r.importe ? formatNumber(r.importe) + ' Bs' : (r.reason || '');
            return '<tr><td>#' + (r.number || r.id) + '</td><td>' + badge + '</td><td>' + extra + '</td></tr>';
        }).join('');

        $('#hawd-results-detail').html(
            '<table class="hawd-detail-table"><thead><tr><th>Pedido</th><th>Estado</th><th>Detalle</th></tr></thead><tbody>' +
            rows + '</tbody></table>'
        );
    }

    function formatNumber(n) {
        return Number(n).toLocaleString('es-BO', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
});
