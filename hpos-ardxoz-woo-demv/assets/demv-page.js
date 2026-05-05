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
    var modalMode = 'orders'; // 'orders' (completar depósito) | 'retiro' (aprobar retiro) | 'pago_envio' (pagar envío)
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

    // Cuando cambian año o cualquier checkbox de los filtros multi-select de mes/depto → recargar depósitos
    $('#hawd_year').on('change', loadDepositNumbers);
    $(document).on('change',
        '.hawd-multi[data-name="month"] input, .hawd-multi[data-name="billing_state"] input',
        loadDepositNumbers
    );

    // ═══════════════════════════════════════════════
    //  MULTI-SELECT (filtros con checkboxes agrupados)
    // ═══════════════════════════════════════════════

    // Inicializar etiquetas
    $('.hawd-multi').each(function () { updateMultiLabel($(this)); });

    // Toggle del panel
    $(document).on('click', '.hawd-multi-toggle', function (e) {
        e.stopPropagation();
        var $multi = $(this).closest('.hawd-multi');
        var $panel = $multi.find('.hawd-multi-panel');
        // Cerrar otros paneles abiertos
        $('.hawd-multi-panel').not($panel).attr('hidden', 'hidden');
        if ($panel.attr('hidden')) {
            $panel.removeAttr('hidden');
        } else {
            $panel.attr('hidden', 'hidden');
        }
    });

    // Click fuera cierra todos los paneles
    $(document).on('click', function (e) {
        if (!$(e.target).closest('.hawd-multi').length) {
            $('.hawd-multi-panel').attr('hidden', 'hidden');
        }
    });

    // El panel no se cierra al hacer click dentro
    $(document).on('click', '.hawd-multi-panel', function (e) {
        e.stopPropagation();
    });

    // "Todos" master → toggle de todas las opciones
    $(document).on('change', '.hawd-multi-all', function () {
        var $multi = $(this).closest('.hawd-multi');
        var checked = $(this).is(':checked');
        $multi.find('.hawd-multi-opt').prop('checked', checked);
        updateMultiLabel($multi);
    });

    // Cambio en una opción individual → sincronizar master + label
    $(document).on('change', '.hawd-multi-opt', function () {
        var $multi = $(this).closest('.hawd-multi');
        var $opts = $multi.find('.hawd-multi-opt');
        var $all = $multi.find('.hawd-multi-all');
        var checkedCount = $opts.filter(':checked').length;
        $all.prop('checked', checkedCount === $opts.length);
        updateMultiLabel($multi);
    });

    function updateMultiLabel($multi) {
        var $opts = $multi.find('.hawd-multi-opt');
        var $checked = $opts.filter(':checked');
        var placeholder = $multi.attr('data-placeholder') || 'Todos';
        var label;
        // 0 o todos seleccionados → "Todos" (semánticamente equivalentes en backend)
        if ($checked.length === 0 || $checked.length === $opts.length) {
            label = placeholder;
        } else if ($checked.length === 1) {
            label = $.trim($checked.first().parent().text());
        } else {
            label = $checked.length + ' seleccionados';
        }
        $multi.find('.hawd-multi-toggle').text(label);
    }

    /**
     * Devuelve los valores filtrados de un multi-select.
     * Retorna [] cuando: 0 seleccionados o todos seleccionados (= sin filtro).
     */
    function getMultiValues(name) {
        var $multi = $('.hawd-multi[data-name="' + name + '"]');
        var $opts = $multi.find('.hawd-multi-opt');
        var $checked = $opts.filter(':checked');
        if ($checked.length === 0 || $checked.length === $opts.length) {
            return [];
        }
        return $checked.map(function () { return $(this).val(); }).get();
    }

    /**
     * Recoge los valores actuales de todos los filtros.
     * Los filtros multi-select se serializan como arrays vacíos cuando equivalen a "todos".
     */
    function getFilters() {
        return {
            year: $('#hawd_year').val(),
            month: getMultiValues('month'),
            status: getMultiValues('status'),
            billing_state: getMultiValues('billing_state'),
            shipping_method: getMultiValues('shipping_method'),
            sucursal: $('#hawd_sucursal').val() || 'all',
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
     * Para los filtros multi-valor genera dinámicamente <input name="x[]"> dentro del contenedor.
     */
    function syncCSVForm() {
        var f = getFilters();
        $('#hawd_csv_year').val(f.year);
        $('#hawd_csv_sucursal').val(f.sucursal);
        $('#hawd_csv_payment').val(f.payment_method);
        $('#hawd_csv_deposit').val(f.deposit);
        $('#hawd_csv_deposit_search').val(f.deposit_search);
        $('#hawd_csv_no_deposit').val(f.no_deposit);
        $('#hawd_csv_search').val(f.search);

        var $multi = $('#hawd_csv_multi_inputs').empty();
        ['month', 'status', 'shipping_method', 'billing_state'].forEach(function (name) {
            (f[name] || []).forEach(function (v) {
                $multi.append(
                    '<input type="hidden" name="' + name + '[]" value="' + escAttr(v) + '">'
                );
            });
        });
    }

    // ═══════════════════════════════════════════════
    //  CARGA DINÁMICA DE NÚMEROS DE DEPÓSITO
    // ═══════════════════════════════════════════════

    function loadDepositNumbers() {
        var year   = $('#hawd_year').val();
        var months = getMultiValues('month');
        var states = getMultiValues('billing_state');
        var $dep   = $('#hawd_deposit');

        // Si "Sin Depósito" está activo, deshabilitar y salir
        if ($('#hawd_no_deposit').is(':checked')) {
            $dep.html('<option value="all">Todos</option>')
                .prop('disabled', true)
                .attr('title', 'Deshabilitado por filtro "Sin Depósito"');
            return;
        }

        // Requiere año + exactamente un mes y un departamento
        if (!year || months.length !== 1 || states.length !== 1) {
            $dep.html('<option value="all">Todos</option>')
                .prop('disabled', true)
                .attr('title', 'Selecciona año, un solo mes y un solo departamento');
            return;
        }

        $dep.html('<option value="all">Cargando...</option>').prop('disabled', true);

        $.post(P.ajax_url, {
            action: 'hawd_get_deposit_numbers',
            nonce: P.nonce,
            year: year,
            month: months[0],
            billing_state: states[0]
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
        setStatsLoading();

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
                    renderStats(null);
                    return;
                }

                tableData = res.data.rows;
                totalPages = res.data.pages;
                currentPage = res.data.page;

                renderTable(tableData);
                renderPagination(res.data.total, res.data.pages, res.data.page);
                renderStats(res.data.stats || null);
                updateSummary();
            })
            .fail(function () {
                $tbody.html(
                    '<tr><td colspan="15" class="hawd-no-results">' +
                    'Error de conexión. Intenta de nuevo.</td></tr>'
                );
                renderStats(null);
            });
    }

    // ═══════════════════════════════════════════════
    //  ESTADÍSTICAS GLOBALES (sobre todo el filtro, no la página)
    // ═══════════════════════════════════════════════

    function setStatsLoading() {
        $('#hawd_stats_count').text('Calculando...');
        $('#hawd_stat_depositado').text('—');
        $('#hawd_stat_total').text('—');
        $('#hawd_stat_envio').text('—');
        $('#hawd_stat_ibex').text('—');
        $('#hawd_stat_ibex_sub').text('— pedidos IBEX-COD');
        $('#hawd_stats_users_body').empty();
    }

    function renderStats(stats) {
        if (!stats) {
            $('#hawd_stats_count').text('— pedidos');
            $('#hawd_stat_depositado').text('—');
            $('#hawd_stat_total').text('—');
            $('#hawd_stat_envio').text('—');
            $('#hawd_stat_ibex').text('—');
            $('#hawd_stat_ibex_sub').text('— pedidos IBEX-COD');
            $('#hawd_stats_users_body').empty();
            return;
        }

        $('#hawd_stats_count').text(
            stats.count + ' pedido' + (stats.count !== 1 ? 's' : '')
        );
        $('#hawd_stat_depositado').text(fmtNum(stats.total_depositado) + ' Bs');
        $('#hawd_stat_total').text(fmtNum(stats.total_orders) + ' Bs');
        $('#hawd_stat_envio').text(fmtNum(stats.total_costo_envio) + ' Bs');
        $('#hawd_stat_ibex').text(fmtNum(stats.ibex_no_depositado) + ' Bs');
        $('#hawd_stat_ibex_sub').text(
            (stats.ibex_count || 0) + ' pedido' +
            ((stats.ibex_count === 1) ? '' : 's') + ' IBEX-COD'
        );

        var $body = $('#hawd_stats_users_body').empty();
        var users = stats.por_usuario || [];

        if (users.length === 0) {
            $body.append(
                '<tr><td colspan="4" class="hawd-su-empty">Sin datos para el filtro actual.</td></tr>'
            );
            return;
        }

        users.forEach(function (u, i) {
            var who = u.name && u.name !== u.user_login
                ? esc(u.name) + ' <span class="hawd-su-login">(' + esc(u.user_login) + ')</span>'
                : esc(u.user_login);
            $body.append(
                '<tr>' +
                '<td class="hawd-su-rank">' + (i + 1) + '</td>' +
                '<td class="hawd-su-user">' + who + '</td>' +
                '<td class="hawd-su-count">' + u.count + '</td>' +
                '<td class="hawd-su-total">' + fmtNum(u.total) + ' Bs</td>' +
                '</tr>'
            );
        });

        // Notifica a la caja de Traspasos para que recompute breakdown del envío
        $(document).trigger('hawd:stats-rendered', [stats]);
    }

    // Toggle del panel "Venta total por usuario"
    $(document).on('click', '#hawd_stats_toggle_users', function () {
        var $panel = $('#hawd_stats_users');
        var expanded = !$panel.attr('hidden');
        if (expanded) {
            $panel.attr('hidden', 'hidden');
            $(this).attr('aria-expanded', 'false').removeClass('is-open');
        } else {
            $panel.removeAttr('hidden');
            $(this).attr('aria-expanded', 'true').addClass('is-open');
        }
    });

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
            var rowClass = '';
            if (r.has_deposit)    rowClass += ' hawd-row-deposited';
            if (r.has_pago_envio) rowClass += ' hawd-row-pago-envio';
            var statusClass = 'hawd-status-' + r.status.replace('wc-', '');

            var $tr = $(
                '<tr class="hawd-order-row' + rowClass + '" data-id="' + r.id + '">' +
                '<td class="hawd-col-check"><input type="checkbox" class="hawd-row-check" value="' + r.id + '"></td>' +
                '<td>' + esc(r.date) + '</td>' +
                '<td><a href="' + r.edit_url + '" class="hawd-order-link" target="_blank">#' + esc(r.order_number) + '</a></td>' +
                '<td>' + esc(r.postcode || '') + '</td>' +
                '<td><span class="hawd-status-badge ' + statusClass + '">' + esc(r.status_label) + '</span></td>' +
                '<td>' + esc(r.payment_method_title || '') + '</td>' +
                '<td>' + esc(r.billing_state_full || '') + '</td>' +
                '<td>' + esc(r.shipping_method_title || '') + '</td>' +
                '<td class="hawd-cell-costo">' + costoEnvioInput(r) + '</td>' +
                '<td>' + emptyCell(r.fecha_deposito) + '</td>' +
                '<td>' + emptyCell(r.numero_deposito) + '</td>' +
                '<td>' + fmtNum(r.order_total) + '</td>' +
                '<td>' + formatMoney(r.monto_deposito) + '</td>' +
                '<td>' + emptyCell(r.fecha_retorno) + '</td>' +
                '<td>' + emptyCell(r.fecha_pago_envio) + '</td>' +
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
        var sumDepositado = 0;
        var sumOrderTotal = 0;
        var sumCostoEnvio = 0;
        selRows.forEach(function (r) {
            sumDepositado += parseFloat(r.importe_calculado) || 0;
            sumOrderTotal += parseFloat(r.order_total) || 0;
            sumCostoEnvio += parseFloat(r.costo_envio) || 0;
        });

        var html = '<strong>' + total + '</strong> pedidos';

        if (selCount > 0) {
            html += ' &nbsp;|&nbsp; <span class="hawd-sel-count">' + selCount + ' seleccionados</span>';
            html += ' &nbsp;|&nbsp; Total depositado: <span class="hawd-sel-total">' + fmtNum(sumDepositado) + ' Bs</span>';
            html += ' &nbsp;|&nbsp; Total: <span class="hawd-sel-order-total">' + fmtNum(sumOrderTotal) + ' Bs</span>';
            html += ' &nbsp;|&nbsp; Total costo de envío: <span class="hawd-sel-shipping">' + fmtNum(sumCostoEnvio) + ' Bs</span>';
        }

        $('#hawd_summary').html(html);
        $('#hawd_btn_deposit').prop('disabled', selCount === 0);
        $('#hawd_btn_pago_envio').prop('disabled', selCount === 0);
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

    // Pagar Envío: abre el mismo modal en modo 'pago_envio' (solo fecha)
    $('#hawd_btn_pago_envio').on('click', function () {
        var rows = getSelectedRows();
        if (rows.length === 0) return;

        modalMode = 'pago_envio';
        currentRetiroId = null;
        $('#hawd_m_title').text('Registrar Pago de Envío');

        var items = rows.map(function (r) {
            return { number: r.order_number, edit_url: r.edit_url, importe: parseFloat(r.costo_envio) || 0 };
        });
        var total = items.reduce(function (s, it) { return s + it.importe; }, 0);
        openDepositoModal(items, total);
    });

    // Pagar Envío TODOS los filtrados: aplica a todo el resultado del filtro actual,
    // no solo a los visibles/seleccionados. Pide confirmación antes de procesar.
    $('#hawd_btn_pago_envio_all').on('click', function () {
        var $btn = $(this);
        $btn.prop('disabled', true).text('Calculando...');

        var f = getFilters();
        f.action = 'hawd_get_pago_envio_targets';
        f.nonce  = P.nonce;

        $.post(P.ajax_url, f)
            .done(function (res) {
                $btn.prop('disabled', false).text('Pagar Envío TODOS los filtrados');

                if (!res.success) {
                    alert((res.data && res.data.message) || 'Error al obtener pedidos elegibles');
                    return;
                }

                var d = res.data;
                if (!d.count) {
                    alert('No hay pedidos elegibles con los filtros actuales.\n' +
                          'Total filtrados: ' + d.total_found + '\n' +
                          'Sin costo de envío: ' + d.skipped_no_cost + '\n' +
                          'Ya pagados: ' + d.skipped_paid);
                    return;
                }

                openPagoEnvioAllModal(d);
            })
            .fail(function () {
                $btn.prop('disabled', false).text('Pagar Envío TODOS los filtrados');
                alert('Error de conexión.');
            });
    });

    // Estado para el modo "todos los filtrados"
    var pagoEnvioAllIds = [];

    function openPagoEnvioAllModal(d) {
        modalMode = 'pago_envio_all';
        currentRetiroId = null;
        pagoEnvioAllIds = d.ids || [];

        $('#hawd_m_title').text('Pagar Envío de TODOS los filtrados');
        $('#hawd_m_fecha').val('');
        $('#hawd_m_comprobante').val('');
        $('#hawd_m_results').hide().html('');
        $('#hawd_m_progress').hide().html('');
        enableModalControls(true);

        var $compField  = $('#hawd_m_comprobante').closest('.hawd-field');
        var $fieldsGrid = $('#hawd_m_fecha').closest('.hawd-modal-fields');
        var $fechaLabel = $('label[for="hawd_m_fecha"]');
        $compField.hide();
        $fieldsGrid.addClass('hawd-modal-fields-single');
        $fechaLabel.text('Fecha de Pago de Envío');

        var $detail = $('#hawd_m_detail').empty();
        $detail.append(
            '<tr><td colspan="3" style="padding:12px;line-height:1.6">' +
            '<strong>Resumen de operación masiva</strong><br>' +
            'Total filtrados: <strong>' + d.total_found + '</strong> pedidos<br>' +
            'Elegibles: <strong>' + d.count + '</strong> pedidos<br>' +
            'Omitidos por ya tener pago: <strong>' + d.skipped_paid + '</strong><br>' +
            'Omitidos sin costo de envío: <strong>' + d.skipped_no_cost + '</strong><br>' +
            '<br>Se va a registrar pago de envío para los <strong>' + d.count + '</strong> elegibles. ' +
            'Esta acción no se puede deshacer fila por fila.' +
            '</td></tr>'
        );

        $('#hawd_m_count').text(d.count + ' pedido' + (d.count !== 1 ? 's' : '') + ' elegibles');
        $('#hawd_m_total').text(fmtNum(d.total_costo) + ' Bs');

        $('#hawd_overlay').fadeIn(150);
        $('#hawd_modal').css('display', 'flex').hide().fadeIn(200);
    }

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

        // En modo "pago_envio" no se pide comprobante: ocultamos su campo
        var $compField   = $('#hawd_m_comprobante').closest('.hawd-field');
        var $fieldsGrid  = $('#hawd_m_fecha').closest('.hawd-modal-fields');
        var $fechaLabel  = $('label[for="hawd_m_fecha"]');
        if (modalMode === 'pago_envio') {
            $compField.hide();
            $fieldsGrid.addClass('hawd-modal-fields-single');
            $fechaLabel.text('Fecha de Pago de Envío');
        } else {
            $compField.show();
            $fieldsGrid.removeClass('hawd-modal-fields-single');
            $fechaLabel.text('Fecha de Depósito');
        }

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
            showModalProgress('Ingresa la fecha.', 'error');
            return;
        }

        if (modalMode === 'pago_envio') {
            saveCompletarPagoEnvio(fecha);
            return;
        }

        if (modalMode === 'pago_envio_all') {
            saveCompletarPagoEnvioAll(fecha);
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

    function saveCompletarPagoEnvio(fecha) {
        var ids = getSelectedIds();
        if (ids.length === 0) {
            showModalProgress('No hay pedidos seleccionados.', 'error');
            return;
        }

        isProcessing = true;
        enableModalControls(false);
        showModalProgress('<span class="hawd-spinner"></span> Procesando ' + ids.length + ' pedido(s)...', '');

        $.post(P.ajax_url, {
            action: 'hawd_complete_pago_envio',
            nonce: P.nonce,
            order_ids: ids,
            fecha: fecha
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

    /**
     * Procesa pago de envío masivo por chunks de 100 IDs.
     * Reusa el endpoint existente hawd_complete_pago_envio (cap servidor: 200).
     * Acumula resultados y los muestra al finalizar.
     */
    function saveCompletarPagoEnvioAll(fecha) {
        var ids = (pagoEnvioAllIds || []).slice();
        if (ids.length === 0) {
            showModalProgress('No hay pedidos elegibles.', 'error');
            return;
        }

        var CHUNK_SIZE = 100;
        var chunks = [];
        for (var i = 0; i < ids.length; i += CHUNK_SIZE) {
            chunks.push(ids.slice(i, i + CHUNK_SIZE));
        }

        isProcessing = true;
        enableModalControls(false);

        var totals = { processed: 0, skipped: 0, errors: 0, results: [] };
        var chunkIdx = 0;

        function runNextChunk() {
            if (chunkIdx >= chunks.length) {
                isProcessing = false;
                var cls = totals.processed > 0 ? 'success' : 'warning';
                showModalProgress(
                    'Completado. Procesados: ' + totals.processed +
                    ' | Omitidos: ' + totals.skipped +
                    ' | Errores: ' + totals.errors,
                    cls
                );
                renderModalResults(totals.results.slice(0, 200)); // cap visual
                if (totals.processed > 0) {
                    setTimeout(function () {
                        closeModal();
                        loadOrders();
                    }, 2500);
                } else {
                    enableModalControls(true);
                }
                return;
            }

            var chunk = chunks[chunkIdx];
            var done  = chunkIdx * CHUNK_SIZE;
            showModalProgress(
                '<span class="hawd-spinner"></span> Procesando ' +
                (done + chunk.length) + ' / ' + ids.length + '...',
                ''
            );

            $.post(P.ajax_url, {
                action: 'hawd_complete_pago_envio',
                nonce: P.nonce,
                order_ids: chunk,
                fecha: fecha
            })
            .done(function (res) {
                if (res.success && res.data) {
                    totals.processed += res.data.processed || 0;
                    totals.skipped   += res.data.skipped   || 0;
                    totals.errors    += res.data.errors    || 0;
                    if (Array.isArray(res.data.results)) {
                        totals.results = totals.results.concat(res.data.results);
                    }
                } else {
                    totals.errors += chunk.length;
                }
                chunkIdx++;
                runNextChunk();
            })
            .fail(function () {
                totals.errors += chunk.length;
                chunkIdx++;
                runNextChunk();
            });
        }

        runNextChunk();
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

    // ═══════════════════════════════════════════════
    //  INLINE EDIT: Costo de Envío
    // ═══════════════════════════════════════════════

    function costoEnvioInput(r) {
        var n = parseFloat(r.costo_envio);
        var val = isNaN(n) ? '' : String(n);

        // Bloqueado: si ya existe fecha de pago de envío no se puede editar.
        if (r.has_pago_envio) {
            var display = (val === '' || parseFloat(val) === 0)
                ? '<span class="hawd-empty">—</span>'
                : fmtNum(val);
            return '<span class="hawd-costo-locked" ' +
                   'title="Bloqueado: pago de envío registrado el ' +
                   escAttr(r.fecha_pago_envio || '') + '">' +
                   '<span class="dashicons dashicons-lock"></span>' +
                   display +
                   '</span>';
        }

        return '<input type="number" step="0.01" min="0" ' +
               'class="hawd-costo-input" ' +
               'data-id="' + r.id + '" ' +
               'data-original="' + escAttr(val) + '" ' +
               'value="' + escAttr(val) + '" ' +
               'placeholder="—" ' +
               'inputmode="decimal" ' +
               'autocomplete="off">';
    }

    // No togglear el checkbox de fila al interactuar con el input
    $(document).on('click mousedown', '.hawd-costo-input', function (e) {
        e.stopPropagation();
    });

    // Enter confirma (dispara blur)
    $(document).on('keydown', '.hawd-costo-input', function (e) {
        if (e.which === 13) {
            e.preventDefault();
            $(this).blur();
        } else if (e.which === 27) {
            // Escape: revertir
            $(this).val($(this).attr('data-original')).blur();
        }
    });

    // Guardar al perder foco si cambió
    $(document).on('change blur', '.hawd-costo-input', function () {
        var $inp = $(this);
        if ($inp.data('saving')) return;

        var orig = $inp.attr('data-original') || '';
        var val  = $.trim($inp.val());

        // Normalizar número (acepta vacío)
        var normalized = '';
        if (val !== '') {
            var n = parseFloat(val.replace(',', '.'));
            if (isNaN(n) || n < 0) {
                $inp.addClass('hawd-input-error');
                $inp.val(orig);
                setTimeout(function () { $inp.removeClass('hawd-input-error'); }, 1200);
                return;
            }
            normalized = String(n);
        }

        if (normalized === orig) return; // sin cambios

        $inp.data('saving', true).prop('disabled', true).addClass('hawd-input-saving');

        $.post(P.ajax_url, {
            action: 'hawd_update_costo_envio',
            nonce: P.nonce,
            order_id: $inp.attr('data-id'),
            costo_envio: normalized
        })
        .done(function (res) {
            if (res.success && res.data && res.data.row) {
                var newRow = res.data.row;
                // Actualizar tableData
                tableData = tableData.map(function (r) {
                    return r.id === newRow.id ? newRow : r;
                });
                $inp.attr('data-original', String(parseFloat(newRow.costo_envio) || 0));
                $inp.removeClass('hawd-input-saving').addClass('hawd-input-saved');
                setTimeout(function () { $inp.removeClass('hawd-input-saved'); }, 1000);
                updateSummary();
            } else {
                $inp.removeClass('hawd-input-saving').addClass('hawd-input-error');
                $inp.val(orig);
                setTimeout(function () { $inp.removeClass('hawd-input-error'); }, 1500);
            }
        })
        .fail(function () {
            $inp.removeClass('hawd-input-saving').addClass('hawd-input-error');
            $inp.val(orig);
            setTimeout(function () { $inp.removeClass('hawd-input-error'); }, 1500);
        })
        .always(function () {
            $inp.data('saving', false).prop('disabled', false);
        });
    });
});
