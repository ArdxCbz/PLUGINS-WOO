document.addEventListener('DOMContentLoaded', function () {
    var params = (typeof hawa_admin !== 'undefined') ? hawa_admin : null;
    if (!params) return;

    // ── Helpers ───────────────────────────────────────────

    function showModal(id) {
        var modal = document.getElementById(id);
        if (modal) modal.classList.add('show');
    }

    function hideModal(id) {
        var modal = document.getElementById(id);
        if (modal) modal.classList.remove('show');
    }

    function ajaxPost(action, data, callback) {
        var fd = new FormData();
        fd.append('action', action);
        fd.append('nonce', params.nonce);
        for (var key in data) {
            fd.append(key, data[key]);
        }
        fetch(params.ajaxurl, { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(callback)
            .catch(function () { alert('Error de conexión'); });
    }

    function flashRow(orderId, color) {
        // HPOS list table: rows have data attributes, not #post-{id}
        var row = document.querySelector('tr[data-order-id="' + orderId + '"]')
               || document.getElementById('post-' + orderId);
        if (!row) return;
        row.style.transition = 'background-color 0.5s';
        row.style.backgroundColor = color || '#e6ffea';
        setTimeout(function () { row.style.backgroundColor = ''; }, 1200);
    }

    function setButtonLoading(btn, loading) {
        if (loading) {
            btn._origText = btn.textContent;
            btn.textContent = 'Guardando...';
            btn.disabled = true;
        } else {
            btn.textContent = btn._origText || 'Guardar';
            btn.disabled = false;
        }
    }

    // ── Intercept status action buttons (hpos-ardxoz-woo-status buttons) ──

    document.addEventListener('click', function (e) {
        // Intercept links that change order status to recibido/retorno/en-curso
        var link = e.target.closest('a[href*="status=recibido"], a[href*="status=retorno"], a[href*="status=en-curso"]');
        if (!link) return;

        var href = link.getAttribute('href');
        if (!href) return;

        var url;
        try { url = new URL(href, window.location.origin); } catch (ex) { return; }

        var status = url.searchParams.get('status');
        var orderId = url.searchParams.get('order_id') || link.closest('tr')?.getAttribute('data-order-id');

        if (!status || !orderId) return;
        if (['recibido', 'retorno', 'en-curso'].indexOf(status) === -1) return;

        e.preventDefault();

        // Fetch order data for modals
        ajaxPost('hawa_get_modal_data', { order_id: orderId }, function (res) {
            if (!res.success) {
                alert('Error: ' + (res.data?.message || 'Desconocido'));
                return;
            }
            var d = res.data;
            var method = (d.shipping_method || '').toUpperCase();

            if (status === 'recibido') {
                document.querySelector('#hawa-modal-recibido input[name="order_id"]').value = orderId;
                document.getElementById('hawa_costo_envio').value = '';
                document.getElementById('hawa_recibido_postcode').value = d.shipping_postcode || '';
                document.getElementById('hawa-recibido-guia-wrapper').style.display =
                    method.indexOf('ENCOMIENDA') !== -1 ? 'block' : 'none';
                showModal('hawa-modal-recibido');

            } else if (status === 'retorno') {
                document.querySelector('#hawa-modal-retorno input[name="order_id"]').value = orderId;
                showModal('hawa-modal-retorno');

            } else if (status === 'en-curso') {
                document.getElementById('hawa-modal-customer-name').textContent = d.customer_name || '';
                document.querySelector('#hawa-modal-encurso input[name="order_id"]').value = orderId;
                document.querySelector('#hawa-modal-encurso input[name="postcode"]').value = d.shipping_postcode || '';
                if (d.costo_courier) {
                    document.querySelector('#hawa-modal-encurso input[name="costo_courier"]').value = d.costo_courier;
                }
                showModal('hawa-modal-encurso');
            }
        });
    });

    // ── Save Recibido ─────────────────────────────────────

    var btnRecibido = document.getElementById('hawa-save-recibido');
    if (btnRecibido) {
        btnRecibido.addEventListener('click', function () {
            var orderId = document.querySelector('#hawa-modal-recibido input[name="order_id"]').value;
            var costo = document.getElementById('hawa_costo_envio').value;
            var metodo = document.getElementById('hawa_metodo_pago').value;

            if (costo === '') { alert('Ingrese costo de envío'); return; }

            setButtonLoading(this, true);

            var data = { order_id: orderId, costo_envio: costo, metodo_pago: metodo };

            // Guía if ENCOMIENDA wrapper visible
            if (document.getElementById('hawa-recibido-guia-wrapper').style.display !== 'none') {
                data.postcode = document.getElementById('hawa_recibido_postcode').value;
            }

            ajaxPost('hawa_save_recibido', data, function (res) {
                setButtonLoading(btnRecibido, false);
                if (res.success) {
                    hideModal('hawa-modal-recibido');
                    flashRow(orderId, '#e6ffea');
                    setTimeout(function () { location.reload(); }, 800);
                } else {
                    alert('Error: ' + (res.data?.message || 'Desconocido'));
                }
            });
        });
    }

    // ── Save Retorno ──────────────────────────────────────

    var btnRetorno = document.getElementById('hawa-save-retorno');
    if (btnRetorno) {
        btnRetorno.addEventListener('click', function () {
            var orderId = document.querySelector('#hawa-modal-retorno input[name="order_id"]').value;
            var fecha = document.querySelector('#hawa-modal-retorno input[name="fecha_retorno"]').value;
            if (!fecha) { alert('Seleccione fecha'); return; }

            setButtonLoading(this, true);
            ajaxPost('hawa_save_retorno', { order_id: orderId, fecha: fecha }, function (res) {
                setButtonLoading(btnRetorno, false);
                if (res.success) {
                    hideModal('hawa-modal-retorno');
                    flashRow(orderId, '#fff3cd');
                    setTimeout(function () { location.reload(); }, 800);
                } else {
                    alert('Error: ' + (res.data?.message || 'Desconocido'));
                }
            });
        });
    }

    // ── Save En Curso ─────────────────────────────────────

    var btnEnCurso = document.getElementById('hawa-save-encurso');
    if (btnEnCurso) {
        btnEnCurso.addEventListener('click', function () {
            var orderId = document.querySelector('#hawa-modal-encurso input[name="order_id"]').value;
            var postcode = document.querySelector('#hawa-modal-encurso input[name="postcode"]').value;
            var costo = document.querySelector('#hawa-modal-encurso input[name="costo_courier"]').value;

            if (!postcode) { alert('Ingrese la guía'); return; }

            setButtonLoading(this, true);
            ajaxPost('hawa_save_encurso', { order_id: orderId, postcode: postcode, costo_courier: costo }, function (res) {
                setButtonLoading(btnEnCurso, false);
                if (res.success) {
                    hideModal('hawa-modal-encurso');
                    flashRow(orderId, '#e6ffea');
                    setTimeout(function () { location.reload(); }, 800);
                } else {
                    alert('Error: ' + (res.data?.message || 'Desconocido'));
                }
            });
        });
    }

    // ── Cambiar Método Envío ──────────────────────────────

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.hawa-abrir-modal-envio');
        if (!btn) return;
        e.preventDefault();
        var orderId = btn.getAttribute('data-order-id');
        var current = btn.getAttribute('data-current-method');
        document.querySelector('#hawa-modal-cambiar-envio input[name="order_id"]').value = orderId;
        var select = document.querySelector('#hawa-modal-cambiar-envio select[name="new_shipping_method"]');
        if (select) select.value = current;
        showModal('hawa-modal-cambiar-envio');
    });

    var btnCambiarEnvio = document.getElementById('hawa-save-cambiar-envio');
    if (btnCambiarEnvio) {
        btnCambiarEnvio.addEventListener('click', function () {
            var orderId = document.querySelector('#hawa-modal-cambiar-envio input[name="order_id"]').value;
            var newMethod = document.querySelector('#hawa-modal-cambiar-envio select[name="new_shipping_method"]').value;

            if (!newMethod) return;
            setButtonLoading(this, true);
            ajaxPost('hawa_cambiar_envio', { order_id: orderId, new_method: newMethod }, function (res) {
                setButtonLoading(btnCambiarEnvio, false);
                if (res.success) {
                    hideModal('hawa-modal-cambiar-envio');
                    flashRow(orderId, '#fff8e1');
                    setTimeout(function () { location.reload(); }, 800);
                } else {
                    alert('Error: ' + (res.data?.message || 'Error'));
                }
            });
        });
    }

    // ── Close Modals ──────────────────────────────────────

    document.addEventListener('click', function (e) {
        if (e.target.classList.contains('hawa-modal-close')) {
            e.target.closest('.hawa-modal').classList.remove('show');
        }
        if (e.target.classList.contains('hawa-modal')) {
            e.target.classList.remove('show');
        }
    });
});
