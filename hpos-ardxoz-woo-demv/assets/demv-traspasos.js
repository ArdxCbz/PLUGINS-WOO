/**
 * HPOS Ardxoz Woo DEMV — Caja de Traspasos
 *
 * Se monta debajo de la tabla principal de pedidos. Reusa los filtros
 * existentes (año/mes/sucursal/envío/búsqueda) leyendo el mismo DOM.
 * Coordina con demv-page.js para el breakdown del total de costo de envío
 * vía window.hawdEnvio + evento "hawd:stats-rendered".
 */
jQuery(function ($) {
    'use strict';

    if (typeof hawd_page === 'undefined') return;
    if (!document.getElementById('hawd_traspasos_card')) return;

    var P = hawd_page;
    var rows = [];
    var page = 1;
    var totalPages = 1;
    var isProcessing = false;

    // Métodos válidos (inyectados por el template)
    var METODOS = [];
    try {
        METODOS = JSON.parse($('#hawd_tp_metodos_envio').text() || '[]');
    } catch (e) { METODOS = []; }

    // Coordinación de stats con demv-page.js
    window.hawdEnvio = window.hawdEnvio || { orders: 0, traspasos: null, orders_ibex: 0, traspasos_ibex: 0 };

    function fmtNum(n) {
        return Number(n || 0).toLocaleString('es-BO', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }
    function esc(str) {
        if (str === null || str === undefined) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(String(str)));
        return div.innerHTML;
    }
    function escAttr(str) {
        return String(str || '')
            .replace(/&/g, '&amp;').replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }
    function emptyCell(v) {
        if (!v && v !== 0) return '<span class="hawd-empty">—</span>';
        return esc(v);
    }

    // Lee los multi-select existentes del DOM principal
    function getMultiValues(name) {
        var $multi = $('.hawd-multi[data-name="' + name + '"]');
        var $opts = $multi.find('.hawd-multi-opt');
        var $checked = $opts.filter(':checked');
        if ($checked.length === 0 || $checked.length === $opts.length) return [];
        return $checked.map(function () { return $(this).val(); }).get();
    }

    function getFilters() {
        return {
            year: $('#hawd_year').val(),
            month: getMultiValues('month'),
            shipping_method: getMultiValues('shipping_method'),
            billing_state: getMultiValues('billing_state'),
            sucursal: $('#hawd_sucursal').val() || 'all',
            search: $.trim($('#hawd_search').val()),
            sin_pago_envio: $('#hawd_tp_sin_pago').is(':checked') ? '1' : '',
            page: page,
            per_page: 50
        };
    }

    function load() {
        var $tbody = $('#hawd_tp_tbody');
        $tbody.html(
            '<tr><td colspan="9" class="hawd-loading">' +
            '<span class="hawd-spinner"></span> Cargando traspasos...</td></tr>'
        );
        $('#hawd_tp_check_all').prop('checked', false);
        updateSummary();

        var f = getFilters();
        f.action = 'hawd_traspasos_filter';
        f.nonce  = P.nonce;

        $.post(P.ajax_url, f)
            .done(function (res) {
                if (!res.success) {
                    $tbody.html(
                        '<tr><td colspan="9" class="hawd-no-results">' +
                        ((res.data && res.data.message) || 'Error al cargar traspasos') +
                        '</td></tr>'
                    );
                    setTraspasosEnvio(0);
                    setCount(0);
                    renderPagination(0, 1, 1);
                    return;
                }

                rows = res.data.rows || [];
                totalPages = Math.max(1, res.data.pages || 1);
                page = res.data.page || 1;

                if (res.data.reason_empty === 'no_metodo_match') {
                    $tbody.html(
                        '<tr><td colspan="9" class="hawd-no-results">' +
                        'El filtro de método de envío no aplica a traspasos.</td></tr>'
                    );
                } else if (res.data.reason_empty === 'no_destino_match') {
                    $tbody.html(
                        '<tr><td colspan="9" class="hawd-no-results">' +
                        'Los DEPTO seleccionados no tienen sucursal de destino (solo Cochabamba y Santa Cruz).</td></tr>'
                    );
                } else {
                    renderTable(rows);
                }

                renderPagination(res.data.total || 0, totalPages, page);
                setCount(res.data.total || 0);
                setTraspasosEnvio(res.data.sum_costo_envio || 0, res.data.sum_costo_envio_ibex || 0);
                updateSummary();
            })
            .fail(function () {
                $tbody.html(
                    '<tr><td colspan="9" class="hawd-no-results">' +
                    'Error de conexión al cargar traspasos.</td></tr>'
                );
                setTraspasosEnvio(0);
            });
    }

    function setCount(n) {
        $('#hawd_traspasos_count').text(
            n + ' traspaso' + (n !== 1 ? 's' : '')
        );
    }

    function renderTable(data) {
        var $tbody = $('#hawd_tp_tbody').empty();
        if (!data || data.length === 0) {
            $tbody.html(
                '<tr><td colspan="9" class="hawd-no-results">' +
                'No hay traspasos para los filtros aplicados.</td></tr>'
            );
            return;
        }

        data.forEach(function (r) {
            var rowClass = '';
            if (r.fecha_pago_envio) rowClass += ' hawd-row-pago-envio';

            var date = r.date_created ? r.date_created.substring(0, 10) : '';

            var $tr = $(
                '<tr class="hawd-tp-row' + rowClass + '" data-id="' + r.id + '">' +
                '<td class="hawd-col-check">' +
                    '<input type="checkbox" class="hawd-tp-row-check" value="' + r.id + '">' +
                '</td>' +
                '<td>' + esc(date) + '</td>' +
                '<td>' + esc(r.user_login || ('#' + r.user_id)) + '</td>' +
                '<td>' + esc(r.origen_label) + ' &rarr; ' + esc(r.destino_label) + '</td>' +
                '<td>' + emptyCell(r.guia) + '</td>' +
                '<td><span class="hawd-tp-estado">' + esc(r.estado) + '</span></td>' +
                '<td class="hawd-tp-cell-metodo">' + metodoSelect(r) + '</td>' +
                '<td class="hawd-tp-cell-costo">' + costoInput(r) + '</td>' +
                '<td>' + emptyCell(r.fecha_pago_envio) + '</td>' +
                '</tr>'
            );

            $tbody.append($tr);
        });
    }

    function metodoSelect(r) {
        var current = r.metodo_envio || '';
        var html = '<select class="hawd-tp-metodo-select" data-id="' + r.id + '" ' +
                   'data-original="' + escAttr(current) + '">';
        html += '<option value="">—</option>';
        METODOS.forEach(function (m) {
            var sel = (m === current) ? ' selected' : '';
            html += '<option value="' + escAttr(m) + '"' + sel + '>' + esc(m) + '</option>';
        });
        html += '</select>';
        return html;
    }

    function costoInput(r) {
        var n = parseFloat(r.costo_envio);
        var val = isNaN(n) || n === null ? '' : String(n);

        // Bloqueado: si ya existe fecha de pago de envío no se puede editar.
        if (r.fecha_pago_envio) {
            var display = (val === '' || parseFloat(val) === 0)
                ? '<span class="hawd-empty">—</span>'
                : fmtNum(val);
            return '<span class="hawd-costo-locked" ' +
                   'title="Bloqueado: pago de envío registrado el ' +
                   escAttr(r.fecha_pago_envio) + '">' +
                   '<span class="dashicons dashicons-lock"></span>' +
                   display +
                   '</span>';
        }

        return '<input type="number" step="0.01" min="0" ' +
               'class="hawd-tp-costo-input" ' +
               'data-id="' + r.id + '" ' +
               'data-original="' + escAttr(val) + '" ' +
               'value="' + escAttr(val) + '" ' +
               'placeholder="—" inputmode="decimal" autocomplete="off">';
    }

    function renderPagination(total, pages, current) {
        var $pag = $('#hawd_tp_pagination').empty();
        if (pages <= 1) {
            $pag.html('<span class="hawd-page-info">' + total + ' traspasos</span>');
            return;
        }

        $pag.append(
            '<a class="hawd-tp-page-btn' + (current <= 1 ? ' disabled' : '') +
            '" data-page="' + (current - 1) + '">&laquo;</a>'
        );

        var start = Math.max(1, current - 2);
        var end   = Math.min(pages, current + 2);

        if (start > 1) {
            $pag.append('<a class="hawd-tp-page-btn" data-page="1">1</a>');
            if (start > 2) $pag.append('<span class="hawd-page-info">…</span>');
        }
        for (var i = start; i <= end; i++) {
            $pag.append(
                '<a class="hawd-tp-page-btn' + (i === current ? ' active' : '') +
                '" data-page="' + i + '">' + i + '</a>'
            );
        }
        if (end < pages) {
            if (end < pages - 1) $pag.append('<span class="hawd-page-info">…</span>');
            $pag.append('<a class="hawd-tp-page-btn" data-page="' + pages + '">' + pages + '</a>');
        }
        $pag.append(
            '<a class="hawd-tp-page-btn' + (current >= pages ? ' disabled' : '') +
            '" data-page="' + (current + 1) + '">&raquo;</a>'
        );
        $pag.append('<span class="hawd-page-info">' + total + ' traspasos</span>');
    }

    $(document).on('click', '.hawd-tp-page-btn:not(.active):not(.disabled)', function (e) {
        e.preventDefault();
        page = parseInt($(this).data('page'), 10) || 1;
        load();
    });

    // ── Selección + summary ──────────────────────────────────────────────

    $('#hawd_tp_check_all').on('change', function () {
        var c = $(this).is(':checked');
        $('#hawd_tp_tbody .hawd-tp-row-check').prop('checked', c);
        $('#hawd_tp_tbody .hawd-tp-row').toggleClass('hawd-row-selected', c);
        updateSummary();
    });

    $(document).on('change', '.hawd-tp-row-check', function () {
        var $row = $(this).closest('tr');
        $row.toggleClass('hawd-row-selected', $(this).is(':checked'));
        var total = $('#hawd_tp_tbody .hawd-tp-row-check').length;
        var checked = $('#hawd_tp_tbody .hawd-tp-row-check:checked').length;
        $('#hawd_tp_check_all').prop('checked', total > 0 && total === checked);
        updateSummary();
    });

    function getSelectedIds() {
        var ids = [];
        $('#hawd_tp_tbody .hawd-tp-row-check:checked').each(function () {
            ids.push(parseInt($(this).val(), 10));
        });
        return ids;
    }

    function getSelectedRows() {
        var ids = getSelectedIds();
        return rows.filter(function (r) { return ids.indexOf(r.id) !== -1; });
    }

    function updateSummary() {
        var totalRows = $('#hawd_tp_tbody .hawd-tp-row-check').length;
        var sel = getSelectedIds();
        var sumCosto = 0;
        getSelectedRows().forEach(function (r) {
            sumCosto += parseFloat(r.costo_envio) || 0;
        });

        var html = '<strong>' + totalRows + '</strong> traspasos';
        if (sel.length > 0) {
            html += ' &nbsp;|&nbsp; <span class="hawd-sel-count">' + sel.length + ' seleccionados</span>';
            html += ' &nbsp;|&nbsp; Total costo de envío: <span class="hawd-sel-shipping">' +
                    fmtNum(sumCosto) + ' Bs</span>';
        }
        $('#hawd_tp_summary').html(html);
        $('#hawd_tp_btn_pagar').prop('disabled', sel.length === 0);
    }

    // ── Inline edit: costo de envío ──────────────────────────────────────

    $(document).on('click mousedown', '.hawd-tp-costo-input, .hawd-tp-metodo-select', function (e) {
        e.stopPropagation();
    });

    $(document).on('keydown', '.hawd-tp-costo-input', function (e) {
        if (e.which === 13) { e.preventDefault(); $(this).blur(); }
        else if (e.which === 27) { $(this).val($(this).attr('data-original')).blur(); }
    });

    $(document).on('change blur', '.hawd-tp-costo-input', function () {
        var $inp = $(this);
        if ($inp.data('saving')) return;

        var orig = $inp.attr('data-original') || '';
        var val  = $.trim($inp.val());
        var normalized = '';
        if (val !== '') {
            var n = parseFloat(val.replace(',', '.'));
            if (isNaN(n) || n < 0) {
                $inp.addClass('hawd-input-error').val(orig);
                setTimeout(function () { $inp.removeClass('hawd-input-error'); }, 1200);
                return;
            }
            normalized = String(n);
        }
        if (normalized === orig) return;

        $inp.data('saving', true).prop('disabled', true).addClass('hawd-input-saving');

        $.post(P.ajax_url, {
            action: 'hawd_traspasos_set_costo_envio',
            nonce: P.nonce,
            id: $inp.attr('data-id'),
            costo_envio: normalized
        })
        .done(function (res) {
            if (res.success && res.data && res.data.row) {
                replaceRow(res.data.row);
                $inp.attr('data-original', String(parseFloat(res.data.row.costo_envio) || 0));
                $inp.removeClass('hawd-input-saving').addClass('hawd-input-saved');
                setTimeout(function () { $inp.removeClass('hawd-input-saved'); }, 1000);
                updateSummary();
                // Recalcular suma costo de envío de traspasos visibles
                recomputeTraspasosEnvio();
            } else {
                $inp.removeClass('hawd-input-saving').addClass('hawd-input-error').val(orig);
                setTimeout(function () { $inp.removeClass('hawd-input-error'); }, 1500);
            }
        })
        .fail(function () {
            $inp.removeClass('hawd-input-saving').addClass('hawd-input-error').val(orig);
            setTimeout(function () { $inp.removeClass('hawd-input-error'); }, 1500);
        })
        .always(function () { $inp.data('saving', false).prop('disabled', false); });
    });

    // ── Inline edit: método de envío ─────────────────────────────────────

    $(document).on('change', '.hawd-tp-metodo-select', function () {
        var $sel = $(this);
        if ($sel.data('saving')) return;

        var orig = $sel.attr('data-original') || '';
        var val  = $sel.val() || '';
        if (val === orig) return;

        $sel.data('saving', true).prop('disabled', true).addClass('hawd-input-saving');

        $.post(P.ajax_url, {
            action: 'hawd_traspasos_set_metodo_envio',
            nonce: P.nonce,
            id: $sel.attr('data-id'),
            metodo_envio: val
        })
        .done(function (res) {
            if (res.success && res.data && res.data.row) {
                replaceRow(res.data.row);
                $sel.attr('data-original', val);
                $sel.removeClass('hawd-input-saving').addClass('hawd-input-saved');
                setTimeout(function () { $sel.removeClass('hawd-input-saved'); }, 1000);
            } else {
                $sel.removeClass('hawd-input-saving').addClass('hawd-input-error').val(orig);
                alert((res.data && res.data.message) || 'Error');
                setTimeout(function () { $sel.removeClass('hawd-input-error'); }, 1500);
            }
        })
        .fail(function () {
            $sel.removeClass('hawd-input-saving').addClass('hawd-input-error').val(orig);
            setTimeout(function () { $sel.removeClass('hawd-input-error'); }, 1500);
        })
        .always(function () { $sel.data('saving', false).prop('disabled', false); });
    });

    function replaceRow(newRow) {
        rows = rows.map(function (r) { return r.id === newRow.id ? newRow : r; });
    }

    function recomputeTraspasosEnvio() {
        // Solo aproximación de página actual; el total real requiere refetch.
        // Para mantener exactitud, simplemente pedimos al servidor el total
        // sin recargar la tabla. Como atajo: re-load de la página actual.
        load();
    }

    // ── Modal: Pagar Envío ───────────────────────────────────────────────

    $('#hawd_tp_btn_pagar').on('click', function () {
        var sel = getSelectedRows();
        if (sel.length === 0) return;

        $('#hawd_tp_m_fecha').val('');
        $('#hawd_tp_m_results').hide().html('');
        $('#hawd_tp_m_progress').hide().html('');
        enableModal(true);

        var $detail = $('#hawd_tp_m_detail').empty();
        var sum = 0;
        sel.forEach(function (r, i) {
            sum += parseFloat(r.costo_envio) || 0;
            $detail.append(
                '<tr>' +
                '<td>' + (i + 1) + '</td>' +
                '<td>' + esc(r.origen_label) + ' &rarr; ' + esc(r.destino_label) +
                    ' (#' + r.id + ')</td>' +
                '<td>' + esc(r.metodo_envio || '—') + '</td>' +
                '<td>' + fmtNum(parseFloat(r.costo_envio) || 0) + ' Bs</td>' +
                '</tr>'
            );
        });

        $('#hawd_tp_m_count').text(sel.length + ' traspaso' + (sel.length !== 1 ? 's' : ''));
        $('#hawd_tp_m_total').text(fmtNum(sum) + ' Bs');

        $('#hawd_tp_overlay').fadeIn(150);
        $('#hawd_tp_modal').css('display', 'flex').hide().fadeIn(200);
    });

    function closeModal() {
        if (isProcessing) return;
        $('#hawd_tp_modal').fadeOut(150);
        $('#hawd_tp_overlay').fadeOut(150);
    }

    $('#hawd_tp_m_cancel, #hawd_tp_modal_close_x').on('click', closeModal);
    $('#hawd_tp_overlay').on('click', closeModal);

    $('#hawd_tp_m_save').on('click', function () {
        var fecha = $('#hawd_tp_m_fecha').val();
        if (!fecha) {
            showProgress('Ingresa la fecha.', 'error');
            return;
        }

        var ids = getSelectedIds();
        if (ids.length === 0) {
            showProgress('No hay traspasos seleccionados.', 'error');
            return;
        }

        isProcessing = true;
        enableModal(false);
        showProgress('<span class="hawd-spinner"></span> Procesando ' + ids.length + ' traspaso(s)...', '');

        $.post(P.ajax_url, {
            action: 'hawd_traspasos_pagar_envio',
            nonce: P.nonce,
            ids: ids,
            fecha: fecha
        })
        .done(function (res) {
            isProcessing = false;
            if (res.success) {
                showProgress(res.data.message || 'Procesado', 'success');
                setTimeout(function () {
                    closeModal();
                    load();
                }, 1500);
            } else {
                if (res.data && res.data.errors) {
                    renderErrors(res.data.errors);
                }
                showProgress((res.data && res.data.message) || 'Error', 'error');
                enableModal(true);
            }
        })
        .fail(function () {
            isProcessing = false;
            showProgress('Error de conexión.', 'error');
            enableModal(true);
        });
    });

    function showProgress(msg, type) {
        $('#hawd_tp_m_progress')
            .removeClass('success error warning')
            .addClass(type)
            .html(msg).show();
    }

    function enableModal(b) {
        $('#hawd_tp_m_save, #hawd_tp_m_cancel, #hawd_tp_modal_close_x').prop('disabled', !b);
        $('#hawd_tp_m_fecha').prop('disabled', !b);
    }

    function renderErrors(errors) {
        var html = '<table class="hawd-modal-detail-table"><thead><tr>' +
                   '<th>Traspaso</th><th>Motivo</th></tr></thead><tbody>';
        Object.keys(errors).forEach(function (id) {
            html += '<tr><td>#' + esc(id) + '</td><td>' + esc(errors[id]) + '</td></tr>';
        });
        html += '</tbody></table>';
        $('#hawd_tp_m_results').html(html).show();
    }

    // ── Coordinación con stats de pedidos ────────────────────────────────

    function setTraspasosEnvio(value, ibex) {
        window.hawdEnvio.traspasos      = parseFloat(value) || 0;
        window.hawdEnvio.traspasos_ibex = parseFloat(ibex)  || 0;
        renderEnvioBreakdown();
    }

    function renderEnvioBreakdown() {
        var $tile = $('#hawd_stat_envio');
        if (!$tile.length) return;
        var orders = parseFloat(window.hawdEnvio.orders || 0);
        var trasp  = window.hawdEnvio.traspasos;
        if (trasp === null) return;
        var total = orders + trasp;
        $tile.text(fmtNum(total) + ' Bs');
        var $sub = $('#hawd_stat_envio_sub');
        if ($sub.length) {
            var ibex = parseFloat(window.hawdEnvio.orders_ibex || 0)
                     + parseFloat(window.hawdEnvio.traspasos_ibex || 0);
            $sub.text(
                'Pedidos: ' + fmtNum(orders) +
                ' · Traspasos: ' + fmtNum(trasp) +
                ' · IBEX: ' + fmtNum(ibex)
            );
        }
    }

    // Cuando demv-page.js termina de renderizar stats, recomputamos breakdown
    $(document).on('hawd:stats-rendered', function (e, stats) {
        if (stats && typeof stats.total_costo_envio !== 'undefined') {
            window.hawdEnvio.orders      = parseFloat(stats.total_costo_envio) || 0;
            window.hawdEnvio.orders_ibex = parseFloat(stats.total_costo_envio_ibex) || 0;
            renderEnvioBreakdown();
        }
    });

    // ── Triggers ─────────────────────────────────────────────────────────

    // Click en el botón "Filtrar" del bloque principal
    $('#hawd_btn_filter').on('click', function () { page = 1; load(); });

    // Enter en búsqueda
    $('#hawd_search').on('keypress', function (e) {
        if (e.which === 13) { page = 1; load(); }
    });

    // Checkbox "Sin pago de envío" propio
    $('#hawd_tp_sin_pago').on('change', function () { page = 1; load(); });

    // Carga inicial
    load();
});
