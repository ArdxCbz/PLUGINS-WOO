/**
 * HPOS Ardxoz Woo DEMV — Gestión de Depósitos (JS)
 *
 * Maneja:
 * - Filtros + AJAX para cargar tabla
 * - Carga dinámica de depósitos cuando año+mes están definidos
 * - Checkboxes (seleccionar todo / individual)
 * - Resumen de selección con totales
 * - Modal de completar depósito
 * - Paginación AJAX
 * - Exportar CSV (sync con filtros)
 */
jQuery(function ($) {
    'use strict';

    var P = hawd_page; // {ajax_url, nonce, current_year}
    var currentPage = 1;
    var totalPages = 1;
    var tableData = []; // Datos de la página actual
    var isProcessing = false; // true solo mientras el AJAX de depósito está en vuelo
    var modalMode = 'orders'; // 'orders' (completar depósito) | 'retiro' (aprobar retiro)
    var currentRetiroId = null;

    // ═══════════════════════════════════════════════
    //  INIT: Cargar datos al entrar
    // ═══════════════════════════════════════════════
    loadOrders();

    // ═══════════════════════════════════════════════
    //  FILTROS
    // ═══════════════════════════════════════════════

    // Botón filtrar
    $('#hawd_btn_filter').on('click', function () {
        currentPage = 1;
        loadOrders();
    });

    // Enter en campos de búsqueda (general y depósito)
    $('#hawd_search, #hawd_deposit_search').on('keypress', function (e) {
        if (e.which === 13) {
            e.preventDefault();
            currentPage = 1;
            loadOrders();
        }
    });

    // "Sin Depósito" es excluyente con los filtros de depósito existentes
    $('#hawd_no_deposit').on('change', function () {
        var checked = $(this).is(':checked');
        $('#hawd_deposit, #hawd_deposit_search').prop('disabled', checked);
        if (checked) {
            $('#hawd_deposit').val('all');
            $('#hawd_deposit_search').val('');
        } else {
            // Reactivar dropdown solo si año/mes/depto permiten
            loadDepositNumbers();
        }
    });

    // Cuando cambian año, mes o departamento → recargar depósitos
    $('#hawd_year, #hawd_month, #hawd_billing_state').on('change', function () {
        loadDepositNumbers();
    });

    /**
     * Recoge los valores actuales de todos los filtros.
     */
    function getFilters() {
        return {
            year: $('#hawd_year').val(),
            month: $('#hawd_month').val(),
            status: $('#hawd_status').val(),
            billing_state: $('#hawd_billing_state').val(),
            shipping_method: $('#hawd_shipping').val(),
            payment_method: $('#hawd_payment').val(),
            deposit: $('#hawd_deposit').val() || 'all',
            deposit_search: $.trim($('#hawd_deposit_search').val()),
            no_deposit: $('#hawd_no_deposit').is(':checked') ? '1' : '',
            search: $.trim($('#hawd_search').val()),
            page: currentPage,
            per_page: 50
        };
    }

    /**
     * Sincroniza los filtros actuales con el form oculto de CSV.
     */
    function syncCSVForm() {
        var f = getFilters();
        $('#hawd_csv_year').val(f.year);
        $('#hawd_csv_month').val(f.month);
        $('#hawd_csv_status').val(f.status);
        $('#hawd_csv_billing_state').val(f.billing_state);
        $('#hawd_csv_payment').val(f.payment_method);
        $('#hawd_csv_shipping').val(f.shipping_method);
        $('#hawd_csv_deposit').val(f.deposit);
        $('#hawd_csv_deposit_search').val(f.deposit_search);
        $('#hawd_csv_no_deposit').val(f.no_deposit);
        $('#hawd_csv_search').val(f.search);
    }

    // ═══════════════════════════════════════════════
    //  CARGA DINÁMICA DE NÚMEROS DE DEPÓSITO
    // ═══════════════════════════════════════════════

    function loadDepositNumbers() {
        var year  = $('#hawd_year').val();
        var month = $('#hawd_month').val();
        var state = $('#hawd_billing_state').val();
        var $dep  = $('#hawd_deposit');

        // Si "Sin Depósito" está activo, deshabilitar y salir
        if ($('#hawd_no_deposit').is(':checked')) {
            $dep.html('<option value="all">Todos</option>')
                .prop('disabled', true)
                .attr('title', 'Deshabilitado por filtro "Sin Depósito"');
            return;
        }

        // Solo funciona cuando año, mes Y departamento están seleccionados
        if (!year || !month || month === 'all' || !state || state === 'all') {
            $dep.html('<option value="all">Todos</option>')
                .prop('disabled', true)
                .attr('title', 'Selecciona año, mes y departamento');
            return;
        }

        $dep.html('<option value="all">Cargando...</option>').prop('disabled', true);

        $.post(P.ajax_url, {
            action: 'hawd_get_deposit_numbers',
            nonce: P.nonce,
            year: year,
            month: month,
            billing_state: state
        })
        .done(function (res) {
            var html = '<option value="all">Todos</option>';
            if (res.success && res.data.numbers && res.data.numbers.length > 0) {
                res.data.numbers.forEach(function (num) {
                    html += '<option value="' + escAttr(num) + '">' + esc(num) + '</option>';
                });
            }
            $dep.html(html).prop('disabled', false).removeAttr('title');
        })
        .fail(function () {
            $dep.html('<option value="all">Error</option>').prop('disabled', true);
        });
    }

    // ═══════════════════════════════════════════════
    //  CARGA DE PEDIDOS (AJAX)
    // ═══════════════════════════════════════════════

    function loadOrders() {
        var $tbody = $('#hawd_tbody');
        $tbody.html(
            '<tr><td colspan="15" class="hawd-loading">' +
            '<span class="hawd-spinner"></span> Cargando pedidos...</td></tr>'
        );
        $('#hawd_check_all').prop('checked', false);
        updateSummary();
        syncCSVForm();

        var filters = getFilters();
        filters.action = 'hawd_filter_orders';
        filters.nonce = P.nonce;

        $.post(P.ajax_url, filters)
            .done(function (res) {
                if (!res.success) {
                    $tbody.html(
                        '<tr><td colspan="15" class="hawd-no-results">' +
                        (res.data && res.data.message ? res.data.message : 'Error al cargar') +
                        '</td></tr>'
                    );
                    return;
                }

                tableData = res.data.rows;
                totalPages = res.data.pages;
                currentPage = res.data.page;

                renderTable(tableData);
                renderPagination(res.data.total, res.data.pages, res.data.page);
                updateSummary();
            })
            .fail(function () {
                $tbody.html(
                    '<tr><td colspan="15" class="hawd-no-results">' +
                    'Error de conexión. Intenta de nuevo.</td></tr>'
                );
            });
    }

    // ═══════════════════════════════════════════════
    //  RENDERIZAR TABLA
    // ═══════════════════════════════════════════════

    function renderTable(rows) {
        var $tbody = $('#hawd_tbody').empty();

        if (!rows || rows.length === 0) {
            $tbody.html(
                '<tr><td colspan="15" class="hawd-no-results">' +
                'No se encontraron pedidos con los filtros aplicados.</td></tr>'
            );
            return;
        }

        rows.forEach(function (r) {
            var rowClass = r.has_deposit ? ' hawd-row-deposited' : '';
            var statusClass = 'hawd-status-' + r.status.replace('wc-', '');

            var $tr = $(
                '<tr class="hawd-order-row' + rowClass + '" data-id="' + r.id + '">' +
                '<td class="hawd-col-check"><input type="checkbox" class="hawd-row-check" value="' + r.id + '"></td>' +
                '<td>' + esc(r.date) + '</td>' +
                '<td>' + esc(r.user_login || '') + '</td>' +
                '<td><a href="' + r.edit_url + '" class="hawd-order-link" target="_blank">#' + esc(r.order_number) + '</a></td>' +
                '<td>' + esc(r.postcode || '') + '</td>' +
                '<td><span class="hawd-status-badge ' + statusClass + '">' + esc(r.status_label) + '</span></td>' +
                '<td>' + esc(r.payment_method_title || '') + '</td>' +
                '<td>' + esc(r.billing_state_full || '') + '</td>' +
                '<td>' + esc(r.shipping_method_title || '') + '</td>' +
                '<td>' + formatMoney(r.costo_envio) + '</td>' +
                '<td>' + emptyCell(r.fecha_deposito) + '</td>' +
                '<td>' + emptyCell(r.numero_deposito) + '</td>' +
                '<td>' + fmtNum(r.order_total) + '</td>' +
                '<td>' + formatMoney(r.monto_deposito) + '</td>' +
                '<td>' + emptyCell(r.fecha_retorno) + '</td>' +
                '</tr>'
            );

            $tbody.append($tr);
        });
    }

    // ═══════════════════════════════════════════════
    //  CHECKBOXES
    // ═══════════════════════════════════════════════

    // Seleccionar/deseleccionar todos
    $('#hawd_check_all').on('change', function () {
        var checked = $(this).is(':checked');
        $('#hawd_tbody .hawd-row-check').prop('checked', checked);
        $('#hawd_tbody .hawd-order-row').toggleClass('hawd-row-selected', checked);
        updateSummary();
    });

    // Individual
    $(document).on('change', '.hawd-row-check', function () {
        var $row = $(this).closest('tr');
        $row.toggleClass('hawd-row-selected', $(this).is(':checked'));

        var total = $('#hawd_tbody .hawd-row-check').length;
        var checked = $('#hawd_tbody .hawd-row-check:checked').length;
        $('#hawd_check_all').prop('checked', total > 0 && total === checked);

        updateSummary();
    });

    function getSelectedIds() {
        var ids = [];
        $('#hawd_tbody .hawd-row-check:checked').each(function () {
            ids.push(parseInt($(this).val()));
        });
        return ids;
    }

    function getSelectedRows() {
        var ids = getSelectedIds();
        return tableData.filter(function (r) {
            return ids.indexOf(r.id) !== -1;
        });
    }

    function updateSummary() {
        var total = $('#hawd_tbody .hawd-row-check').length;
        var selected = getSelectedIds();
        var selCount = selected.length;

        var selRows = getSelectedRows();
        var selTotal = 0;
        selRows.forEach(function (r) {
            selTotal += r.importe_calculado || 0;
        });

        var html = '<strong>' + total + '</strong> pedidos';

        if (selCount > 0) {
            html += ' &nbsp;|&nbsp; <span class="hawd-sel-count">' + selCount + ' seleccionados</span>';
            html += ' &nbsp;|&nbsp; Total calculado: <span class="hawd-sel-total">' + fmtNum(selTotal) + ' Bs</span>';
        }

        $('#hawd_summary').html(html);
        $('#hawd_btn_deposit').prop('disabled', selCount === 0);
    }

    // ═══════════════════════════════════════════════
    //  PAGINACIÓN
    // ═══════════════════════════════════════════════

    function renderPagination(total, pages, page) {
        var $pag = $('#hawd_pagination').empty();

        if (pages <= 1) {
            $pag.html('<span class="hawd-page-info">' + total + ' pedidos</span>');
            return;
        }

        $pag.append(
            '<a class="hawd-page-btn' + (page <= 1 ? ' disabled' : '') + '" data-page="' + (page - 1) + '">&laquo;</a>'
        );

        var start = Math.max(1, page - 2);
        var end = Math.min(pages, page + 2);

        if (start > 1) {
            $pag.append('<a class="hawd-page-btn" data-page="1">1</a>');
            if (start > 2) $pag.append('<span class="hawd-page-info">…</span>');
        }

        for (var i = start; i <= end; i++) {
            $pag.append(
                '<a class="hawd-page-btn' + (i === page ? ' active' : '') + '" data-page="' + i + '">' + i + '</a>'
            );
        }

        if (end < pages) {
            if (end < pages - 1) $pag.append('<span class="hawd-page-info">…</span>');
            $pag.append('<a class="hawd-page-btn" data-page="' + pages + '">' + pages + '</a>');
        }

        $pag.append(
            '<a class="hawd-page-btn' + (page >= pages ? ' disabled' : '') + '" data-page="' + (page + 1) + '">&raquo;</a>'
        );

        $pag.append('<span class="hawd-page-info">' + total + ' pedidos</span>');
    }

    $(document).on('click', '.hawd-page-btn:not(.active):not(.disabled)', function (e) {
        e.preventDefault();
        currentPage = parseInt($(this).data('page'));
        loadOrders();
        $('html, body').animate({ scrollTop: $('.hawd-table-wrap').offset().top - 40 }, 200);
    });

    // ═══════════════════════════════════════════════
    //  MODAL: COMPLETAR DEPÓSITO
    // ═══════════════════════════════════════════════

    $('#hawd_btn_deposit').on('click', function () {
        var rows = getSelectedRows();
        if (rows.length === 0) return;

        modalMode = 'orders';
        currentRetiroId = null;
        $('#hawd_m_title').text('Completar Depósito Bancario');

        var items = rows.map(function (r) {
            return { number: r.order_number, edit_url: r.edit_url, importe: r.importe_calculado || 0 };
        });
        var total = items.reduce(function (s, it) { return s + it.importe; }, 0);
        openDepositoModal(items, total);
    });

    // Aprobación de retiro: abre el mismo modal en modo 'retiro'
    $(document).on('click', '.hawd-rp-aprobar', function () {
        var $btn = $(this);
        var detail = $btn.attr('data-detail');
        try { detail = JSON.parse(detail || '[]'); } catch (e) { detail = []; }

        modalMode = 'retiro';
        currentRetiroId = parseInt($btn.attr('data-retiro-id'), 10);
        var monto = parseFloat($btn.attr('data-monto')) || 0;

        $('#hawd_m_title').text('Aprobar Retiro #' + currentRetiroId);

        var items = detail.map(function (d) {
            return { number: d.number, edit_url: d.edit_url, importe: d.monto_efectivo || 0 };
        });
        openDepositoModal(items, monto);
    });

    function openDepositoModal(items, total) {
        $('#hawd_m_fecha').val('');
        $('#hawd_m_comprobante').val('');
        $('#hawd_m_results').hide().html('');
        $('#hawd_m_progress').hide().html('');
        enableModalControls(true);

        var $detail = $('#hawd_m_detail').empty();
        items.forEach(function (it, i) {
            $detail.append(
                '<tr>' +
                '<td>' + (i + 1) + '</td>' +
                '<td><a href="' + it.edit_url + '" target="_blank">#' + esc(it.number) + '</a></td>' +
                '<td>' + fmtNum(it.importe) + ' Bs</td>' +
                '</tr>'
            );
        });

        $('#hawd_m_count').text(items.length + ' pedido' + (items.length !== 1 ? 's' : ''));
        $('#hawd_m_total').text(fmtNum(total) + ' Bs');

        $('#hawd_overlay').fadeIn(150);
        $('#hawd_modal').css('display', 'flex').hide().fadeIn(200);
    }

    function closeModal() {
        if (isProcessing) {
            return;
        }
        $('#hawd_modal').fadeOut(150);
        $('#hawd_overlay').fadeOut(150);
    }

    $('#hawd_m_cancel, #hawd_modal_close_x').on('click', closeModal);
    $('#hawd_overlay').on('click', closeModal);

    $('#hawd_m_save').on('click', function () {
        var fecha = $('#hawd_m_fecha').val();
        var comprobante = $.trim($('#hawd_m_comprobante').val());

        if (!fecha) {
            showModalProgress('Ingresa la fecha de depósito.', 'error');
            return;
        }
        if (!comprobante) {
            showModalProgress('Ingresa el número de depósito.', 'error');
            return;
        }

        if (modalMode === 'retiro') {
            saveAprobarRetiro(currentRetiroId, fecha, comprobante);
        } else {
            saveCompletarDeposito(fecha, comprobante);
        }
    });

    function saveCompletarDeposito(fecha, comprobante) {
        var ids = getSelectedIds();
        if (ids.length === 0) {
            showModalProgress('No hay pedidos seleccionados.', 'error');
            return;
        }

        isProcessing = true;
        enableModalControls(false);
        showModalProgress('<span class="hawd-spinner"></span> Procesando ' + ids.length + ' pedido(s)...', '');

        $.post(P.ajax_url, {
            action: 'hawd_complete_deposit',
            nonce: P.nonce,
            order_ids: ids,
            fecha: fecha,
            comprobante: comprobante
        })
        .done(function (res) {
            isProcessing = false;
            if (res.success) {
                var d = res.data;
                var cls = d.processed > 0 ? 'success' : 'warning';
                showModalProgress(d.message, cls);
                renderModalResults(d.results);

                if (d.processed > 0) {
                    setTimeout(function () {
                        closeModal();
                        loadOrders();
                    }, 2000);
                } else {
                    enableModalControls(true);
                }
            } else {
                showModalProgress(res.data.message || 'Error desconocido', 'error');
                enableModalControls(true);
            }
        })
        .fail(function () {
            isProcessing = false;
            showModalProgress('Error de conexión. Intenta de nuevo.', 'error');
            enableModalControls(true);
        });
    }

    function saveAprobarRetiro(retiroId, fecha, numero) {
        if (!retiroId) {
            showModalProgress('Retiro inválido.', 'error');
            return;
        }

        isProcessing = true;
        enableModalControls(false);
        showModalProgress('<span class="hawd-spinner"></span> Aprobando retiro...', '');

        $.post(P.ajax_url, {
            action: 'hawd_aprobar_retiro',
            nonce: P.nonce,
            retiro_id: retiroId,
            fecha: fecha,
            numero_deposito: numero
        })
        .done(function (res) {
            isProcessing = false;
            if (res.success) {
                showModalProgress(res.data.message || 'Retiro aprobado.', 'success');
                renderModalResults(res.data.results);
                setTimeout(function () {
                    closeModal();
                    window.location.reload();
                }, 1500);
            } else {
                showModalProgress((res.data && res.data.message) || 'Error desconocido', 'error');
                enableModalControls(true);
            }
        })
        .fail(function () {
            isProcessing = false;
            showModalProgress('Error de conexión. Intenta de nuevo.', 'error');
            enableModalControls(true);
        });
    }

    function showModalProgress(msg, type) {
        $('#hawd_m_progress')
            .removeClass('success error warning')
            .addClass(type)
            .html(msg)
            .show();
    }

    function enableModalControls(enabled) {
        $('#hawd_m_save, #hawd_m_cancel, #hawd_modal_close_x').prop('disabled', !enabled);
        $('#hawd_m_fecha, #hawd_m_comprobante').prop('disabled', !enabled);
    }

    function renderModalResults(results) {
        if (!results || results.length === 0) return;

        var html = '<table class="hawd-modal-detail-table"><thead><tr>' +
                   '<th>Pedido</th><th>Estado</th><th>Detalle</th></tr></thead><tbody>';

        results.forEach(function (r) {
            var badge = '';
            if (r.status === 'processed') {
                badge = '<span class="hawd-result-badge ok">Procesado</span>';
            } else if (r.status === 'skipped') {
                badge = '<span class="hawd-result-badge skip">Omitido</span>';
            } else {
                badge = '<span class="hawd-result-badge err">Error</span>';
            }

            var extra = r.importe ? fmtNum(r.importe) + ' Bs' : (r.reason || '');
            html += '<tr><td>#' + (r.number || r.id) + '</td><td>' + badge + '</td><td>' + extra + '</td></tr>';
        });

        html += '</tbody></table>';
        $('#hawd_m_results').html(html).show();
    }

    // ═══════════════════════════════════════════════
    //  HELPERS
    // ═══════════════════════════════════════════════

    function esc(str) {
        if (str === null || str === undefined) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(String(str)));
        return div.innerHTML;
    }

    function escAttr(str) {
        return String(str || '')
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function fmtNum(n) {
        return Number(n || 0).toLocaleString('es-BO', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function formatMoney(val) {
        if (val === '' || val === null || val === undefined || val === '0') {
            return '<span class="hawd-empty">—</span>';
        }
        var n = parseFloat(val);
        if (isNaN(n) || n === 0) return '<span class="hawd-empty">—</span>';
        return fmtNum(n);
    }

    function emptyCell(val) {
        if (!val || val === '') return '<span class="hawd-empty">—</span>';
        return esc(val);
    }
});
