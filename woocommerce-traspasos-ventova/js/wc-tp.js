jQuery(function ($) {
    console.log('✅ WC-TP.js cargado');

    let transferList = [];
    let editItems = [];
    let editTransferId = 0;
    let editOrigenSlug = '';
    let editTipo = 'productos';

    // Cachés id→fila de los resultados del buscador Select2 (para recuperar
    // name/sku/stock al seleccionar, ya que Select2 solo conserva id/text).
    let searchCache = {};
    let editSearchCache = {};

    // ═══ BUSCADOR SELECT2 DE VARIACIONES (mismo patrón que el form de compras) ═══
    // Crea un <select> con typeahead que consulta wc_tp_search_products acotado a
    // una sucursal origen (y opcionalmente categoría). Reemplaza la tabla de
    // checkboxes con tope de 50 por búsqueda server-side por nombre o SKU.
    function makeVariationSelect($el, getOrigen, getCat, cache, placeholder) {
        if (typeof jQuery.fn.selectWoo !== 'function' && typeof jQuery.fn.select2 !== 'function') {
            return setTimeout(function () {
                makeVariationSelect($el, getOrigen, getCat, cache, placeholder);
            }, 60);
        }
        const method = jQuery.fn.selectWoo ? 'selectWoo' : 'select2';
        $el[method]({
            placeholder: placeholder,
            minimumInputLength: 2,
            allowClear: true,
            width: '100%',
            language: {
                inputTooShort: function () { return 'Escribe al menos 2 caracteres…'; },
                noResults: function () { return 'Sin resultados en esta sucursal'; },
                searching: function () { return 'Buscando…'; }
            },
            ajax: {
                url: wcTp.ajax_url,
                type: 'POST', // el handler lee $_POST (como el resto del plugin); Select2 usa GET por defecto
                dataType: 'json',
                delay: 250,
                data: function (params) {
                    return {
                        action: 'wc_tp_search_products',
                        nonce: wcTp.nonce,
                        origen: getOrigen(),
                        categoria: getCat ? getCat() : '',
                        term: params.term || ''
                    };
                },
                processResults: function (res) {
                    if (!res || !res.success) return { results: [] };
                    const rows = (res.data && res.data.rows) || [];
                    return {
                        results: rows.map(function (r) {
                            cache[String(r.id)] = r;
                            return { id: String(r.id), text: r.text };
                        })
                    };
                },
                cache: true
            }
        });
    }

    // ═══ TABS ═══
    $('.wc-tp-tabs .nav-tab').on('click', function (e) {
        e.preventDefault();
        const tab = $(this).data('tab');
        $('.wc-tp-tabs .nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');
        $('.wc-tp-tab-content').hide();
        $('.wc-tp-tab-content[data-tab="' + tab + '"]').show();
    });

    // ═══ BUSCADOR + AGREGAR (paso 2) ═══
    const $tpSearch = $('#tp_product_search');
    makeVariationSelect(
        $tpSearch,
        () => $('#tp_origen').val(),
        () => $('#tp_cat').val(),
        searchCache,
        'Buscar producto por nombre o SKU…'
    );

    // El buscador se habilita solo con una sucursal origen elegida.
    function syncSearchEnabled() {
        const origen = $('#tp_origen').val();
        $tpSearch.prop('disabled', !origen).trigger('change.select2');
        $('#tp_add_feedback').text(
            origen
                ? 'Escribe para buscar productos de esta sucursal.'
                : 'Selecciona primero la sucursal origen.'
        );
    }
    // Cambiar origen/categoría invalida la selección y la caché actual.
    // IMPORTANTE: se vacía EN EL SITIO (no `searchCache = {}`), porque el closure
    // de makeVariationSelect capturó esta misma referencia; reasignarla dejaría a
    // processResults escribiendo en un objeto y al botón Agregar leyendo otro.
    function clearObj(o) { for (const k in o) { if (Object.prototype.hasOwnProperty.call(o, k)) delete o[k]; } }
    function resetSearchSelection() {
        $tpSearch.val(null).trigger('change');
        clearObj(searchCache);
    }
    $('#tp_origen').on('change', () => { resetSearchSelection(); syncSearchEnabled(); });
    $('#tp_cat').on('change', resetSearchSelection);
    syncSearchEnabled();

    $('#tp_agregar').on('click', () => {
        const origen = $('#tp_origen').val();
        if (!origen) return alert('Selecciona la sucursal origen');
        const id = $tpSearch.val();
        if (!id) return alert('Busca y selecciona un producto');
        const row = searchCache[String(id)];
        if (!row) return alert('Vuelve a seleccionar el producto');
        const qty = parseInt($('#tp_qty').val()) || 0;
        if (qty < 1) return alert('Indica una cantidad válida');
        if (qty > row.stock) return alert(`La cantidad supera el stock disponible (${row.stock})`);
        if (transferList.some(i => i.id == id)) return alert('Ese producto ya está en la lista');

        transferList.push({
            id: String(id),
            name: row.name,
            qty: qty,
            stock: row.stock,
            sku: row.sku
        });
        renderTransferList();
        resetSearchSelection();
        $('#tp_qty').val(1);
        $('#tp_add_feedback').text('✓ Agregado. Busca otro producto o continúa al paso 3.');
    });

    function renderTransferList() {
        const $b = $('#tp_lista tbody').empty();
        if (!transferList.length) {
            $b.append('<tr><td colspan="3" style="text-align:center;color:#777;">— Lista vacía —</td></tr>');
            return;
        }
        transferList.forEach((it, i) => {
            $b.append(`
                <tr data-index="${i}">
                    <td>${it.name}</td>
                    <td>${it.qty}</td>
                    <td><button class="button-link tp_remove_item" style="color:#c0392b;">Eliminar</button></td>
                </tr>
            `);
        });
    }
    renderTransferList();

    $(document).on('click', '.tp_remove_item', function () {
        const idx = $(this).closest('tr').data('index');
        transferList.splice(idx, 1);
        renderTransferList();
    });

    // ═══ TRASPASAR PRODUCTOS ═══
    $('#tp_transferir').on('click', () => {
        if (!transferList.length) return alert('La lista de traspaso está vacía');
        const origen = $('#tp_origen').val();
        const destino = $('#tp_destino').val();
        const guia = $('#tp_guia').val();
        const estado = $('#tp_estado').val();
        if (!origen || !destino) return alert('Debes seleccionar Origen y Destino');
        if (origen === destino) return alert('Origen y Destino no pueden ser iguales');
        if (!confirm('¿Estás seguro de realizar este traspaso?')) return;

        $.post(wcTp.ajax_url, {
            action: 'wc_tp_transfer',
            nonce: wcTp.nonce,
            origen, destino, guia, estado,
            items: JSON.stringify(transferList)
        }, res => {
            if (!res.success) return alert('Error: ' + res.data.message);
            alert('¡Traspaso Exitoso!');
            location.reload();
        }, 'json');
    });

    // ═══ TRASPASAR BIENES (descripción) ═══
    $('#tp_b_enviar').on('click', () => {
        const origen = $('#tp_b_origen').val();
        const destino = $('#tp_b_destino').val();
        const desc = ($('#tp_b_descripcion').val() || '').trim();
        const guia = $('#tp_b_guia').val();
        const estado = $('#tp_b_estado').val();

        if (!origen || !destino) return alert('Debes seleccionar Origen y Destino');
        if (origen === destino) return alert('Origen y Destino no pueden ser iguales');
        if (!desc) return alert('Ingresa la descripción de los bienes a enviar');
        if (!confirm('¿Crear traspaso de bienes?')) return;

        $.post(wcTp.ajax_url, {
            action: 'wc_tp_transfer',
            nonce: wcTp.nonce,
            origen, destino, guia, estado,
            descripcion: desc,
            items: JSON.stringify([])
        }, res => {
            if (!res.success) return alert('Error: ' + res.data.message);
            alert('¡Traspaso de bienes creado!');
            location.reload();
        }, 'json');
    });

    // ═══ HISTORIAL: ACCIONES ═══
    $(document).on('click', '.tp_view_details', function (e) {
        e.preventDefault();
        loadDetails($(this).data('id'), false);
    });
    $(document).on('click', '.tp_edit_details', function (e) {
        e.preventDefault();
        loadDetails($(this).data('id'), true);
    });
    $(document).on('click', '.tp_print_details', function (e) {
        e.preventDefault();
        printTransfer($(this).data('id'));
    });

    $(document).on('change', '.tp_estado_select', function () {
        const id = $(this).data('id');
        const estado = $(this).val();
        if (!confirm('¿Cambiar estado a ' + estado + '? Esto puede afectar el inventario en traspasos de productos.')) {
            location.reload();
            return;
        }
        $.post(wcTp.ajax_url, {
            action: 'wc_tp_update_status',
            nonce: wcTp.nonce, id, estado
        }, res => {
            if (!res.success) {
                alert('Error: ' + res.data.message);
                location.reload();
            } else {
                alert('Estado actualizado.');
                location.reload();
            }
        }, 'json');
    });

    function loadDetails(id, isEdit) {
        $.post(wcTp.ajax_url, {
            action: 'wc_tp_get_transfer_details',
            nonce: wcTp.nonce, id
        }, res => {
            if (!res.success) return alert(res.data.message);
            const d = res.data;
            const isDesc = d.tipo === 'descripcion';

            if (isEdit) {
                editTransferId = d.id;
                editOrigenSlug = d.origen;
                editTipo = d.tipo;

                $('#tp_edit_id_display').text('#' + d.id);
                $('#tp_edit_guia').val(d.guia);
                $('#tp_edit_origen_name').text(d.origen_name || d.origen);
                // Ocultar el área de búsqueda al reabrir; se despliega con su botón.
                $('#tp_edit_search_area').hide();

                if (isDesc) {
                    $('#tp_edit_section_productos').hide();
                    $('#tp_edit_section_descripcion').show();
                    $('#tp_edit_descripcion').val(d.descripcion || '');
                    editItems = [];
                } else {
                    $('#tp_edit_section_productos').show();
                    $('#tp_edit_section_descripcion').hide();
                    editItems = (d.items || []).map(it => ({
                        id: it.id, name: it.name, qty: parseInt(it.qty), sku: it.sku
                    }));
                    renderEditItems();
                }
                $('#tp_edit_modal').show();

            } else {
                $('#tp_modal_title').text(`Traspaso #${d.id}  ·  ${d.origen_name} → ${d.destino_name}`);
                $('#tp_modal_meta').html(`
                    <span><strong>Guía:</strong> ${d.guia || '—'}</span>
                    <span><strong>Estado:</strong> ${d.estado}</span>
                    <span><strong>Usuario:</strong> ${d.user}</span>
                `);

                if (isDesc) {
                    $('#tp_modal_items_section').hide();
                    $('#tp_modal_desc_section').show();
                    $('#tp_modal_desc_text').text(d.descripcion || '');
                } else {
                    $('#tp_modal_items_section').show();
                    $('#tp_modal_desc_section').hide();
                    const $b = $('#tp_modal_items').empty();
                    (d.items || []).forEach(it => {
                        $b.append(`<tr><td>${it.sku || ''}</td><td>${it.name}</td><td>${it.qty}</td></tr>`);
                    });
                }
                $('#tp_modal').show();
            }
        }, 'json');
    }

    function renderEditItems() {
        const $b = $('#tp_edit_items_body').empty();
        if (!editItems.length) {
            $b.append('<tr><td colspan="3" style="text-align:center;color:#777;">— Sin productos —</td></tr>');
            return;
        }
        editItems.forEach((it, i) => {
            $b.append(`
                <tr data-index="${i}">
                    <td>${it.name}</td>
                    <td><input type="number" class="tp_edit_qty" value="${it.qty}" min="1" style="width:70px"></td>
                    <td><button class="button-link tp_edit_remove" style="color:#c0392b;">Quitar</button></td>
                </tr>
            `);
        });
    }

    $(document).on('change', '.tp_edit_qty', function () {
        const idx = $(this).closest('tr').data('index');
        const val = parseInt($(this).val()) || 1;
        if (editItems[idx]) editItems[idx].qty = val;
    });

    $(document).on('click', '.tp_edit_remove', function () {
        const idx = $(this).closest('tr').data('index');
        editItems.splice(idx, 1);
        renderEditItems();
    });

    $('#tp_edit_save').on('click', () => {
        const isDesc = editTipo === 'descripcion';
        const confirmMsg = isDesc
            ? '¿Guardar cambios?'
            : 'ATENCIÓN: Modificar productos revertirá el movimiento de stock anterior y aplicará uno nuevo. ¿Continuar?';
        if (!confirm(confirmMsg)) return;

        $.post(wcTp.ajax_url, {
            action: 'wc_tp_edit_transfer',
            nonce: wcTp.nonce,
            id: editTransferId,
            guia: $('#tp_edit_guia').val(),
            descripcion: $('#tp_edit_descripcion').val(),
            items: JSON.stringify(editItems)
        }, res => {
            if (!res.success) return alert('Error: ' + res.data.message);
            alert('Traspaso actualizado correctamente.');
            location.reload();
        }, 'json');
    });

    $('#tp_edit_cancel, #tp_modal_close').on('click', function () {
        $(this).closest('.tp_modal_overlay').hide();
    });

    // ═══ BÚSQUEDA EN MODAL EDIT (Select2 acotado al origen del traspaso) ═══
    let editSearchInit = false;
    function ensureEditSearch() {
        if (editSearchInit) return;
        const $sel = $('#tp_edit_product_search');
        makeVariationSelect(
            $sel,
            () => editOrigenSlug,   // la sucursal origen fija del traspaso editado
            null,
            editSearchCache,
            'Buscar producto por nombre o SKU…'
        );
        $sel.on('select2:select', function (e) {
            const id = e.params.data.id;
            const row = editSearchCache[String(id)];
            if (!row) return;
            if (editItems.some(it => it.id == id)) {
                alert('Ese producto ya está en la lista');
            } else {
                editItems.push({ id: String(id), name: row.name, qty: 1, sku: row.sku });
                renderEditItems();
            }
            $sel.val(null).trigger('change');
        });
        editSearchInit = true;
    }

    $('#tp_edit_add_item_btn').on('click', () => {
        $('#tp_edit_search_area').toggle();
        if ($('#tp_edit_search_area').is(':visible')) {
            ensureEditSearch();
        }
    });

    // ═══ IMPRIMIR ═══
    function printTransfer(id) {
        $.post(wcTp.ajax_url, {
            action: 'wc_tp_get_transfer_details',
            nonce: wcTp.nonce, id
        }, res => {
            if (!res.success) return alert(res.data.message);
            const d = res.data;
            const isDesc = d.tipo === 'descripcion';
            const win = window.open('', '_blank');
            let body = `
                <h2>Traspaso #${d.id}</h2>
                <p><strong>Fecha:</strong> ${new Date().toLocaleDateString()}</p>
                <p><strong>Origen:</strong> ${d.origen_name} &rarr; <strong>Destino:</strong> ${d.destino_name}</p>
                <p><strong>Guía:</strong> ${d.guia} &nbsp;|&nbsp; <strong>Estado:</strong> ${d.estado}</p>
            `;
            if (isDesc) {
                const safeDesc = (d.descripcion || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                body += `<h3>Descripción</h3><div style="white-space:pre-wrap;border:1px solid #ccc;padding:10px;border-radius:4px;background:#f9f9f9;">${safeDesc}</div>`;
            } else {
                body += `<table><thead><tr><th>SKU</th><th>Producto</th><th>Cant</th></tr></thead><tbody>`;
                (d.items || []).forEach(it => {
                    body += `<tr><td>${it.sku || ''}</td><td>${it.name}</td><td>${it.qty}</td></tr>`;
                });
                body += `</tbody></table>`;
            }
            win.document.write(`
                <html><head><title>Traspaso ${d.id}</title>
                <style>
                    body { font-family: sans-serif; font-size: 12px; padding: 20px; }
                    table { width: 100%; border-collapse: collapse; margin-top: 10px; }
                    th, td { border: 1px solid #ccc; padding: 5px; text-align: left; }
                </style></head><body>${body}</body></html>
            `);
            win.document.close();
            win.print();
        }, 'json');
    }
});
