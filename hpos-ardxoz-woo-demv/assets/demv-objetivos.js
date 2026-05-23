/**
 * Objetivos
 * - Selector mes/año → fetch AJAX hawd_obj_data
 * - Render gráfico horizontal (Real | Objetivo) — solo pedidos completed
 * - Render tabla desglose por estado
 */
(function ($) {
    'use strict';

    if (typeof hawd_obj === 'undefined') return;

    function fmt(n) {
        n = parseFloat(n) || 0;
        return n.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',') + ' Bs';
    }

    function escHtml(s) {
        return $('<div/>').text(s == null ? '' : String(s)).html();
    }

    function setLoading(on) {
        $('#hawd_obj_loading').toggle(!!on);
        $('#hawd_obj_refresh').prop('disabled', !!on);
    }

    function fetchData() {
        var year  = parseInt($('#hawd_obj_year').val(), 10);
        var month = parseInt($('#hawd_obj_month').val(), 10);

        setLoading(true);

        $.post(hawd_obj.ajax_url, {
            action: 'hawd_obj_data',
            nonce:  hawd_obj.nonce,
            year:   year,
            month:  month
        }).done(function (resp) {
            if (!resp || !resp.success) {
                renderEmpty((resp && resp.data && resp.data.message) || 'Error al cargar datos.');
                return;
            }
            renderObjective(resp.data);
            renderChart(resp.data);
            renderTable(resp.data);
        }).fail(function () {
            renderEmpty('Error de red al cargar.');
        }).always(function () {
            setLoading(false);
        });
    }

    function renderObjective(d) {
        var piso  = parseFloat(d.objetivo_piso)  || 0;
        var techo = parseFloat(d.objetivo_techo) || 0;
        $('#hawd_obj_piso').text(piso > 0 ? fmt(piso) : '— Bs');
        $('#hawd_obj_techo').text(techo > 0 ? fmt(techo) : '— Bs');
    }

    function renderChart(d) {
        var $box = $('#hawd_obj_chart').empty();

        if (!d.chart || d.chart.length === 0) {
            var emptyMsg = d.is_admin_view
                ? 'No hay pedidos en el periodo seleccionado.'
                : 'No hay vendedores configurados como visibles.';
            $box.html('<p class="hawd-obj-empty">' + escHtml(emptyMsg) + '</p>');
            return;
        }

        var piso  = parseFloat(d.objetivo_piso)  || 0;
        var techo = parseFloat(d.objetivo_techo) || 0;

        // Escala: el máximo entre techo, piso y el mayor real
        var maxReal = 0;
        d.chart.forEach(function (r) {
            if (r.real > maxReal) maxReal = r.real;
        });
        var scale = Math.max(techo, piso, maxReal, 1);

        // Leyenda
        var legend = '<div class="hawd-obj-legend">' +
              '<span><span class="hawd-obj-legend-swatch is-real"></span>Ventas (Completado)</span>' +
              '<span><span class="hawd-obj-legend-swatch is-below"></span>Bajo el piso</span>' +
              '<span><span class="hawd-obj-legend-swatch is-between"></span>Entre piso y techo</span>' +
              '<span><span class="hawd-obj-legend-swatch is-overflow"></span>Superó techo</span>';
        if (piso > 0) {
            legend += '<span><span class="hawd-obj-legend-swatch is-target-piso"></span>Piso (' + fmt(piso) + ')</span>';
        }
        if (techo > 0) {
            legend += '<span><span class="hawd-obj-legend-swatch is-target-techo"></span>Techo (' + fmt(techo) + ')</span>';
        }
        legend += '</div>';
        $box.append(legend);

        d.chart.forEach(function (r) {
            var widthPct  = scale > 0 ? Math.min(100, (r.real / scale) * 100) : 0;
            var pisoPct   = (piso  > 0 && scale > 0) ? Math.min(100, (piso  / scale) * 100) : 0;
            var techoPct  = (techo > 0 && scale > 0) ? Math.min(100, (techo / scale) * 100) : 0;

            // Clase del fill según zona devuelta por el backend
            var fillCls = '';
            switch (r.zona) {
                case 'reached':    fillCls = 'is-overflow'; break;
                case 'between':    fillCls = 'is-between';  break;
                case 'below_piso': fillCls = 'is-below';    break;
                default:           fillCls = '';            break;
            }

            // Clase del % (texto): rojo bajo piso, ámbar entre, verde si superó techo
            var pctClass = '';
            if (r.zona === 'reached')    pctClass = 'is-met';
            else if (r.zona === 'below_piso') pctClass = 'is-low';
            else if (r.zona === 'between')    pctClass = 'is-mid';

            var pctTxt = (techo > 0)
                ? r.pct.toFixed(1) + ' %'
                : '—';

            var html =
                '<div class="hawd-obj-bar-row">' +
                  '<div class="hawd-obj-bar-name" title="' + escHtml(r.name) + '">' + escHtml(r.name) + '</div>' +
                  '<div class="hawd-obj-bar-track">' +
                    '<div class="hawd-obj-bar-fill ' + fillCls + '" ' +
                         'style="width:' + widthPct.toFixed(2) + '%"></div>' +
                    (piso > 0
                      ? '<div class="hawd-obj-bar-target hawd-obj-bar-target-piso" ' +
                        'style="left:' + pisoPct.toFixed(2) + '%" title="Piso: ' + fmt(piso) + '"></div>'
                      : '') +
                    (techo > 0
                      ? '<div class="hawd-obj-bar-target hawd-obj-bar-target-techo" ' +
                        'style="left:' + techoPct.toFixed(2) + '%" title="Techo: ' + fmt(techo) + '"></div>'
                      : '') +
                  '</div>' +
                  '<div class="hawd-obj-bar-amount">' + fmt(r.real) + '</div>' +
                  '<div class="hawd-obj-bar-pct ' + pctClass + '">' + pctTxt + '</div>' +
                '</div>';

            $box.append(html);
        });
    }

    function renderTable(d) {
        var $tbody = $('#hawd_obj_table tbody').empty();
        var $tfoot = $('#hawd_obj_table tfoot tr.hawd-obj-totals');

        var estados = d.estados || [];

        // Mapear etiqueta → slug usando el orden recibido. El backend devuelve estados en
        // el mismo orden de las claves de tabla[i].cells.
        // tabla[i].cells es { slug: monto } — para preservar orden, recreamos con estados[].
        var slugByLabel = { 'Completados':'completed', 'EN CURSO':'en-curso', 'RECIBIDO':'recibido', 'CAMBIO':'entregado', 'RETORNO':'retorno' };

        if (!d.tabla || d.tabla.length === 0) {
            var emptyMsg = d.is_admin_view
                ? 'No hay pedidos en el periodo seleccionado.'
                : 'No hay vendedores configurados como visibles.';
            $tbody.append(
                '<tr><td colspan="' + (estados.length + 2) + '" style="text-align:center;color:#888;padding:14px">' +
                escHtml(emptyMsg) +
                '</td></tr>'
            );
            // Reset footer
            $tfoot.find('th.hawd-obj-num').text('—');
            return;
        }

        var totals = {};
        estados.forEach(function (label) { totals[label] = 0; });
        var grandTotal = 0;

        d.tabla.forEach(function (row) {
            var tr = '<tr><td><strong>' + escHtml(row.name) + '</strong></td>';
            estados.forEach(function (label) {
                var slug = slugByLabel[label];
                var v = parseFloat(row.cells[slug]) || 0;
                totals[label] += v;
                var cls = v === 0 ? 'is-zero' : '';
                tr += '<td class="hawd-obj-num ' + cls + '">' + fmt(v) + '</td>';
            });
            grandTotal += parseFloat(row.total) || 0;
            tr += '<td class="hawd-obj-num"><strong>' + fmt(row.total) + '</strong></td></tr>';
            $tbody.append(tr);
        });

        // Footer totales por columna
        var footerCells = $tfoot.find('th.hawd-obj-num');
        estados.forEach(function (label, i) {
            footerCells.eq(i).text(fmt(totals[label]));
        });
        footerCells.eq(estados.length).text(fmt(grandTotal));
    }

    function renderEmpty(msg) {
        $('#hawd_obj_chart').html('<p class="hawd-obj-empty">' + escHtml(msg) + '</p>');
        $('#hawd_obj_table tbody').html(
            '<tr><td colspan="99" style="text-align:center;color:#888;padding:14px">' + escHtml(msg) + '</td></tr>'
        );
    }

    $(function () {
        $('#hawd_obj_refresh').on('click', fetchData);
        $('#hawd_obj_year, #hawd_obj_month').on('change', fetchData);
        fetchData();
    });

})(jQuery);
