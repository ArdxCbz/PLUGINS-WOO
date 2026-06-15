/**
 * Depósitos Express
 * - Búsqueda exacta multi-guía / multi-pedido
 * - Tabla de resultados con descuento -7% IBEX visible
 * - Modal para registrar depósito sobre la selección
 */
(function ($) {
    'use strict';

    if (typeof hawd_express === 'undefined') return;

    var lastResults = []; // filas devueltas por la última búsqueda
    var submitting  = false; // guarda anti doble-submit (re-entrada / doble binding)

    function fmt(n) {
        n = parseFloat(n) || 0;
        return n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',') + ' Bs';
    }

    function $id(id) { return $(document.getElementById(id)); }

    // ── Búsqueda ──────────────────────────────────────────────────────────

    function doSearch() {
        var raw = $.trim($id('hawd_dx_input').val());
        if (!raw) {
            alert('Ingresa al menos una guía o número de pedido.');
            return;
        }

        $id('hawd_dx_searching').show();
        $id('hawd_dx_btn_search').prop('disabled', true);

        $.post(hawd_express.ajax_url, {
            action: 'hawd_dx_search',
            nonce:  hawd_express.nonce,
            terms:  raw
        }).done(function (resp) {
            if (!resp || !resp.success) {
                alert((resp && resp.data && resp.data.message) || 'Error en la búsqueda.');
                return;
            }
            renderResults(resp.data);
        }).fail(function () {
            alert('Error de red al buscar.');
        }).always(function () {
            $id('hawd_dx_searching').hide();
            $id('hawd_dx_btn_search').prop('disabled', false);
        });
    }

    function renderResults(data) {
        lastResults = data.rows || [];

        // Resumen
        $id('hawd_dx_summary').show();
        $id('hawd_dx_stat_found').text(data.count_found);
        $id('hawd_dx_stat_missing').text(data.count_missing);
        $id('hawd_dx_stat_skipped').text(data.count_skipped);
        $id('hawd_dx_stat_total').text(fmt(data.sum_calculado));
        $id('hawd_dx_stat_ibex').text(fmt(data.sum_descuento));

        // Listas auxiliares
        if (data.missing && data.missing.length) {
            $id('hawd_dx_missing_list').text(data.missing.join(', '));
            $id('hawd_dx_missing_box').show();
        } else {
            $id('hawd_dx_missing_box').hide();
        }

        if (data.skipped && data.skipped.length) {
            var parts = data.skipped.map(function (s) {
                return s.term + ' (#' + s.order_number + ' → ' + s.numero_deposito + ')';
            });
            $id('hawd_dx_skipped_list').text(parts.join(' · '));
            $id('hawd_dx_skipped_box').show();
        } else {
            $id('hawd_dx_skipped_box').hide();
        }

        // Filas
        var $tb = $id('hawd_dx_tbody').empty();
        if (lastResults.length === 0) {
            $id('hawd_dx_results_wrap').hide();
            return;
        }

        lastResults.forEach(function (r) {
            var classes = [];
            if (r.aplica_ibex) classes.push('hawd-dx-row-ibex');
            if (r.is_top_up)   classes.push('hawd-dx-row-topup');
            if (r.has_cash)    classes.push('hawd-dx-row-cash');
            var rowCls = classes.join(' ');

            var amountCell;
            if (r.is_top_up) {
                // Top-up: muestra el faltante como "A depositar" y debajo el
                // depósito previo y comprobante para que el operador sepa de
                // dónde viene la deuda.
                amountCell =
                    '<span class="hawd-dx-amount-final">' + fmt(r.importe_calculado) + '</span>' +
                    '<span class="hawd-dx-discount-line">' +
                      'Previo: ' + fmt(r.prior_monto_deposito) +
                      ' (#' + escapeHtml(r.prior_numero_deposito || '—') + ')' +
                    '</span>';
            } else if (r.aplica_ibex) {
                amountCell =
                    '<span class="hawd-dx-amount-strike">' + fmt(r.order_total) + '</span> ' +
                    '<span class="hawd-dx-amount-final">' + fmt(r.importe_calculado) + '</span>' +
                    '<span class="hawd-dx-discount-line">−7% IBEX (' + fmt(r.descuento_ibex) + ')</span>';
            } else {
                amountCell = '<span class="hawd-dx-amount-final">' + fmt(r.importe_calculado) + '</span>';
            }

            // Pedido mitad efectivo / mitad QR: el efectivo NO se deposita al
            // banco (se concilia por Caja) y el pedido no se completa aquí.
            if (r.has_cash) {
                amountCell +=
                    '<span class="hawd-dx-discount-line">Efectivo en caja: ' +
                    fmt(r.monto_efectivo) + ' (no se deposita)</span>';
            }

            var ibexBadge  = r.aplica_ibex ? ' <span class="hawd-dx-badge">−7% IBEX</span>' : '';
            var topupBadge = r.is_top_up   ? ' <span class="hawd-dx-badge hawd-dx-badge-topup">Top-up</span>' : '';
            var cashBadge  = r.has_cash    ? ' <span class="hawd-dx-badge hawd-dx-badge-cash">Efectivo</span>' : '';

            var tr =
                '<tr class="' + rowCls + '">' +
                  '<td style="text-align:center"><input type="checkbox" class="hawd-dx-row-check" ' +
                    'data-id="' + r.id + '" data-monto="' + r.importe_calculado + '" checked /></td>' +
                  '<td><a href="' + r.edit_url + '" target="_blank">#' + escapeHtml(r.order_number) + '</a>' + topupBadge + cashBadge + '</td>' +
                  '<td>' + escapeHtml(r.guia || '—') + '</td>' +
                  '<td>' + escapeHtml(r.date) + '</td>' +
                  '<td>' + escapeHtml(r.shipping_method_title || '—') + ibexBadge + '</td>' +
                  '<td>' + escapeHtml(r.payment_method_title || '—') + '</td>' +
                  '<td style="text-align:right">' + fmt(r.order_total) + '</td>' +
                  '<td style="text-align:right">' + amountCell + '</td>' +
                '</tr>';
            $tb.append(tr);
        });

        $id('hawd_dx_check_all').prop('checked', true);
        $id('hawd_dx_results_wrap').show();
        syncSelection();
    }

    function escapeHtml(s) {
        return $('<div/>').text(s == null ? '' : String(s)).html();
    }

    // ── Selección ─────────────────────────────────────────────────────────

    function syncSelection() {
        var $checks  = $('.hawd-dx-row-check:checked');
        var count    = $checks.length;
        var total    = 0;
        $checks.each(function () { total += parseFloat($(this).data('monto')) || 0; });

        $id('hawd_dx_sel_count').text(count);
        $id('hawd_dx_sel_total').text(fmt(total));
        $id('hawd_dx_btn_complete').prop('disabled', count === 0);
    }

    // ── Modal ─────────────────────────────────────────────────────────────

    // Items seleccionados con id+number+importe (para validación local de absorber)
    var modalItems = [];

    function getSelectedItems() {
        var items = [];
        $('.hawd-dx-row-check:checked').each(function () {
            var id = parseInt($(this).data('id'), 10);
            var monto = parseFloat($(this).data('monto')) || 0;
            // Buscar el row para obtener number
            var row = lastResults.filter(function (r) { return parseInt(r.id, 10) === id; })[0];
            items.push({
                id: id,
                number: row ? row.order_number : String(id),
                importe: monto
            });
        });
        return items;
    }

    function buildAbsorberSelect(items) {
        var $sel = $id('hawd_dx_m_absorber').empty();
        items.forEach(function (it) {
            var label = '#' + it.number + ' — ' + fmt(it.importe) + ' esperado';
            $sel.append('<option value="' + it.id + '">' + escapeHtml(label) + '</option>');
        });
    }

    function fmtSignedSimple(n) {
        var num = parseFloat(n) || 0;
        var s = (Math.abs(num)).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        if (num > 0) return '+' + s + ' Bs';
        if (num < 0) return '-' + s + ' Bs';
        return s + ' Bs';
    }

    function recomputeDiff() {
        var monto = parseFloat(($id('hawd_dx_m_monto_real').val() || '').replace(',', '.')) || 0;
        var esperado = modalItems.reduce(function (s, it) { return s + (parseFloat(it.importe) || 0); }, 0);
        var diff = Math.round((monto - esperado) * 100) / 100;

        $id('hawd_dx_m_esperado').text(fmt(esperado));
        var $val = $id('hawd_dx_m_diff_val').removeClass('hawd-dx-diff-pos hawd-dx-diff-neg hawd-dx-diff-zero');

        if (monto <= 0) {
            $val.text('—');
            $id('hawd_dx_m_absorber_box').hide();
            return;
        }
        $val.text(fmtSignedSimple(diff));
        if (Math.abs(diff) <= 0.01) {
            $val.addClass('hawd-dx-diff-zero');
            $id('hawd_dx_m_absorber_box').hide();
        } else {
            $val.addClass(diff > 0 ? 'hawd-dx-diff-pos' : 'hawd-dx-diff-neg');
            if (modalItems.length > 1) {
                var hint = (diff < 0)
                    ? 'La guía elegida recibirá ' + fmtSignedSimple(diff) + ' y NO se marcará como completada.'
                    : 'La guía elegida recibirá +' + (diff).toFixed(2) + ' Bs y se marcará como completada.';
                $id('hawd_dx_m_absorber_hint').text(hint);
                $id('hawd_dx_m_absorber_box').show();
            } else {
                $id('hawd_dx_m_absorber_box').hide();
            }
        }
    }

    function openModal() {
        var items = getSelectedItems();
        if (items.length === 0) return;

        modalItems = items;
        var total = items.reduce(function (s, it) { return s + it.importe; }, 0);

        $id('hawd_dx_m_count').text(items.length);
        $id('hawd_dx_m_total').text(fmt(total));
        $id('hawd_dx_m_esperado').text(fmt(total));
        $id('hawd_dx_m_diff_val').text('—').removeClass('hawd-dx-diff-pos hawd-dx-diff-neg hawd-dx-diff-zero');
        $id('hawd_dx_m_monto_real').val('');
        $id('hawd_dx_m_directo').prop('checked', false);
        // El pago directo es de a un pedido: deshabilita el check si hay varios.
        $id('hawd_dx_m_directo').prop('disabled', items.length !== 1);
        $id('hawd_dx_m_results').hide().empty();
        submitting = false;
        // Garantiza EXACTAMENTE un handler de guardar (anula doble binding por
        // doble carga del script, y restaura el guardar tras un "Cerrar" previo).
        $id('hawd_dx_m_save').off('click').on('click', doSave).prop('disabled', false).text('Guardar');

        buildAbsorberSelect(items);
        $id('hawd_dx_m_absorber_box').hide();

        // Default fecha = hoy
        if (!$id('hawd_dx_m_fecha').val()) {
            var d = new Date();
            var iso = d.getFullYear() + '-' +
                String(d.getMonth() + 1).padStart(2, '0') + '-' +
                String(d.getDate()).padStart(2, '0');
            $id('hawd_dx_m_fecha').val(iso);
        }

        $id('hawd_dx_overlay').addClass('is-open');
        $id('hawd_dx_modal').addClass('is-open');
    }

    function closeModal() {
        $id('hawd_dx_overlay').removeClass('is-open');
        $id('hawd_dx_modal').removeClass('is-open');
    }

    function doSave() {
        if (submitting) return; // ya hay un guardado en curso: ignora el 2.º disparo

        var fecha       = $.trim($id('hawd_dx_m_fecha').val());
        var comprobante = $.trim($id('hawd_dx_m_comprobante').val());
        var monto       = parseFloat(($id('hawd_dx_m_monto_real').val() || '').replace(',', '.'));

        if (!fecha)       { alert('Indica la fecha de depósito.'); return; }
        if (!comprobante) { alert('Indica el número de comprobante.'); return; }
        if (!monto || monto <= 0) { alert('Indica el monto real depositado.'); return; }

        var ids = $('.hawd-dx-row-check:checked')
            .map(function () { return parseInt($(this).data('id'), 10); })
            .get();

        if (ids.length === 0) { alert('No hay pedidos seleccionados.'); return; }

        var esperado = modalItems.reduce(function (s, it) { return s + (parseFloat(it.importe) || 0); }, 0);
        var diff = Math.round((monto - esperado) * 100) / 100;
        var absorberId = 0;
        if (Math.abs(diff) > 0.01) {
            if (modalItems.length === 1) {
                absorberId = modalItems[0].id;
            } else {
                absorberId = parseInt($id('hawd_dx_m_absorber').val(), 10) || 0;
                if (!absorberId) {
                    alert('Selecciona la guía que absorberá la diferencia.');
                    return;
                }
            }
        }

        var pagoDirecto = $id('hawd_dx_m_directo').is(':checked') ? 1 : 0;
        if (pagoDirecto && ids.length !== 1) {
            alert('El pago directo del cliente se registra sobre un solo pedido a la vez.');
            return;
        }

        submitting = true;
        $id('hawd_dx_m_save').prop('disabled', true).text('Guardando…');

        $.post(hawd_express.ajax_url, {
            action:       'hawd_dx_complete',
            nonce:        hawd_express.nonce,
            order_ids:    ids,
            fecha:        fecha,
            comprobante:  comprobante,
            monto_real:   monto,
            absorber_id:  absorberId,
            pago_directo: pagoDirecto
        }).done(function (resp) {
            if (!resp || !resp.success) {
                alert((resp && resp.data && resp.data.message) || 'Error al guardar.');
                $id('hawd_dx_m_save').prop('disabled', false).text('Guardar');
                return;
            }

            var d = resp.data;
            var html =
                '<div><strong>' + escapeHtml(d.message) + '</strong></div>' +
                '<ul style="margin:6px 0 0 18px">' +
                d.results.map(function (r) {
                    var line = '#' + (r.number || r.id) + ' — ' + r.status;
                    if (r.reason)  line += ' (' + r.reason + ')';
                    if (r.importe) line += ' · ' + fmt(r.importe);
                    if (r.is_cash) line += ' · efectivo en caja, no completado';
                    return '<li>' + escapeHtml(line) + '</li>';
                }).join('') +
                '</ul>';

            $id('hawd_dx_m_results').html(html).show();
            // El botón pasa a "Cerrar": se QUITA doSave (off) y se bindea solo el
            // cierre, para que un clic en Cerrar no re-dispare el guardado.
            $id('hawd_dx_m_save').text('Cerrar').prop('disabled', false)
                .off('click').one('click', function (e) {
                    e.preventDefault();
                    closeModal();
                    doSearch(); // refrescar resultados
                });
        }).fail(function () {
            alert('Error de red al guardar.');
            $id('hawd_dx_m_save').prop('disabled', false).text('Guardar');
        }).always(function () {
            submitting = false;
        });
    }

    // ── Bindings ──────────────────────────────────────────────────────────

    $(function () {
        $id('hawd_dx_btn_search').on('click', doSearch);
        $id('hawd_dx_input').on('keydown', function (e) {
            if (e.ctrlKey && e.key === 'Enter') doSearch();
        });
        $id('hawd_dx_btn_clear').on('click', function () {
            $id('hawd_dx_input').val('').focus();
            $id('hawd_dx_summary').hide();
            $id('hawd_dx_missing_box').hide();
            $id('hawd_dx_skipped_box').hide();
            $id('hawd_dx_results_wrap').hide();
            lastResults = [];
        });

        $(document).on('change', '.hawd-dx-row-check', syncSelection);
        $id('hawd_dx_check_all').on('change', function () {
            $('.hawd-dx-row-check').prop('checked', this.checked);
            syncSelection();
        });

        $id('hawd_dx_btn_complete').on('click', openModal);
        $id('hawd_dx_modal_close').on('click', closeModal);
        $id('hawd_dx_m_cancel').on('click', closeModal);
        $id('hawd_dx_overlay').on('click', closeModal);
        $id('hawd_dx_m_save').on('click', doSave);
        $id('hawd_dx_m_monto_real').on('input change', recomputeDiff);
    });

})(jQuery);
