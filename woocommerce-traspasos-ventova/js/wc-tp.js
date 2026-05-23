jQuery(function ($) {
    console.log('✅ WC-TP.js cargado');

    let transferList = [];
    let productData = [];
    let editItems = [];
    let editTransferId = 0;
    let editOrigenSlug = '';
    let editTipo = 'productos';

    // ═══ TABS ═══
    $('.wc-tp-tabs .nav-tab').on('click', function (e) {
        e.preventDefault();
        const tab = $(this).data('tab');
        $('.wc-tp-tabs .nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');
        $('.wc-tp-tab-content').hide();
        $('.wc-tp-tab-content[data-tab="' + tab + '"]').show();
    });

    // ═══ FILTRAR ═══
    $('#tp_filtrar').on('click', () => {
        const origen = $('#tp_origen').val();
        const cat = $('#tp_cat').val();
        if (!origen) return alert('Selecciona una sucursal origen');

        $.post(wcTp.ajax_url, {
            action: 'wc_tp_get_products',
            nonce: wcTp.nonce,
            origen: origen,
            categoria: cat
        }, res => {
            const $body = $('#tp_resultados tbody').empty();
            if (!res.success) return alert('Error: ' + res.data.message);
            if (!res.data.rows.length) {
                return $body.append('<tr><td colspan="5" style="text-align:center;color:#777;">— No hay variaciones —</td></tr>');
            }
            productData = res.data.rows;
            res.data.rows.forEach(r => {
                $body.append(`
                    <tr>
                        <td><input type="checkbox" name="productos[]" value="${r.id}"></td>
                        <td>${r.name}</td>
                        <td>${r.stock}</td>
                        <td>
                            <input type="number" class="tp_qty_input" name="qty[${r.id}]" min="1" max="${r.stock}" style="width:70px">
                        </td>
                        <td></td>
                    </tr>
                `);
            });
        }, 'json');
    });

    // ═══ AGREGAR ═══
    $('#tp_agregar').on('click', () => {
        $('#tp_resultados tbody tr').each(function () {
            const $tr = $(this);
            const $chk = $tr.find('input[type=checkbox]');
            if (!$chk.is(':checked')) return;
            const id = $chk.val();
            const qty = parseInt($tr.find('.tp_qty_input').val()) || 0;
            if (qty < 1) return;

            const product = productData.find(r => r.id == id);
            if (product) {
                if (transferList.some(i => i.id == id)) {
                    alert(`El producto ${product.name} ya está en la lista.`);
                    return;
                }
                transferList.push({
                    id: id,
                    name: product.name,
                    qty: qty,
                    stock: product.stock,
                    sku: product.sku
                });
            }
        });
        renderTransferList();
        $('#tp_resultados input[type=checkbox]').prop('checked', false);
        $('#tp_resultados .tp_qty_input').val('');
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

    // ═══ BÚSQUEDA EN MODAL EDIT ═══
    $('#tp_edit_add_item_btn').on('click', () => {
        $('#tp_edit_search_area').toggle();
        $('#tp_edit_search_input').focus();
    });

    $('#tp_edit_search_btn').on('click', () => {
        const term = $('#tp_edit_search_input').val();
        if (term.length < 3) return alert('Ingresa al menos 3 caracteres');
        $.post(wcTp.ajax_url, {
            action: 'wc_tp_search_products',
            nonce: wcTp.nonce,
            origen: editOrigenSlug, term
        }, res => {
            const $ul = $('#tp_edit_search_results').empty();
            if (!res.success) return alert(res.data.message);
            if (!res.data.rows.length) return $ul.append('<li>Sin resultados</li>');
            res.data.rows.forEach(r => {
                const $li = $(`<li><span>[${r.sku}] ${r.name} <em>(Stock: ${r.stock})</em></span> <button class="button button-small">Agregar</button></li>`);
                $li.find('button').on('click', () => {
                    if (editItems.some(it => it.id == r.id)) {
                        alert('Ya está en la lista');
                    } else {
                        editItems.push({ id: r.id, name: r.name, qty: 1, sku: r.sku });
                        renderEditItems();
                    }
                });
                $ul.append($li);
            });
        }, 'json');
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
