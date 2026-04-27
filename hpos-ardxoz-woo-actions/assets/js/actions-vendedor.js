document.addEventListener('DOMContentLoaded', function () {
    var params = (typeof hawa_vendedor !== 'undefined') ? hawa_vendedor : null;
    if (!params) return;

    // ── Helpers ───────────────────────────────────────────

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
        var row = document.querySelector('tr[data-order-id="' + orderId + '"]')
               || document.getElementById('post-' + orderId);
        if (!row) return;
        row.style.transition = 'background-color 0.5s';
        row.style.backgroundColor = color || '#d4edda';
        setTimeout(function () { row.style.backgroundColor = ''; }, 1200);
    }

    // ── Recibido / Acomodar buttons (status change) ──────

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.hawa-btn-recibido, .hawa-btn-acomodar');
        if (!btn) return;

        e.preventDefault();
        var orderId = btn.getAttribute('data-order-id');
        var action = btn.getAttribute('data-action');

        btn.disabled = true;
        btn.textContent = '...';

        ajaxPost('hawa_vendedor_status', { order_id: orderId, status: action }, function (res) {
            if (res.success) {
                flashRow(orderId, '#d4edda');
                btn.textContent = '✓';
                setTimeout(function () { location.reload(); }, 800);
            } else {
                btn.disabled = false;
                btn.textContent = action === 'recibido' ? 'Recibido' : 'Acomodar';
                alert('Error: ' + (res.data?.message || 'Desconocido'));
            }
        });
    });

    // ── Print button ─────────────────────────────────────

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.hawa-btn-print');
        if (!btn) return;

        e.preventDefault();
        var printUrl = btn.getAttribute('data-print-url');
        if (!printUrl) return;

        fetch(printUrl)
            .then(function (r) {
                if (!r.ok) throw new Error('Print URL failed');
                return r.text();
            })
            .then(function (html) {
                var win = window.open('', '_blank', 'width=800,height=600');
                if (win) {
                    win.document.open();
                    win.document.write(html);
                    win.document.close();
                    setTimeout(function () { win.print(); win.close(); }, 500);
                } else {
                    alert('Permite las ventanas emergentes para imprimir.');
                }
            })
            .catch(function () {
                alert('Error al imprimir.');
            });
    });

    // ── En Curso button → Modal Guía ─────────────────────

    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.hawa-btn-encurso');
        if (!btn) return;

        e.preventDefault();
        var orderId = btn.getAttribute('data-order-id');
        var orderNum = btn.getAttribute('data-order-number');
        var shipMethod = (btn.getAttribute('data-shipping-method') || '').toUpperCase();
        var montoActual = btn.getAttribute('data-monto-efectivo');

        document.getElementById('hawa-guia-order-id').value = orderId;
        
        // Lógica condicional: SUECIA o CBS (Solo Método de Envío)
        var isSpecial = shipMethod.includes('SUECIA') || shipMethod.includes('CBS');
        
        var inputGuia = document.getElementById('hawa-guia-postcode');
        inputGuia.value = isSpecial ? orderNum : '';
        inputGuia.readOnly = isSpecial;
        inputGuia.style.backgroundColor = isSpecial ? '#f0f0f0' : '';

        // Bloqueo de Monto Efectivo si ya tiene valor
        var inputMonto = document.getElementById('hawa-guia-monto-efectivo');
        inputMonto.value = montoActual || '';
        inputMonto.readOnly = !!montoActual;
        inputMonto.style.backgroundColor = montoActual ? '#f0f0f0' : '';

        document.getElementById('hawa-modal-guia').classList.add('show');
    });

    // ── Save Guía ────────────────────────────────────────

    var saveGuia = document.getElementById('hawa-save-guia');
    if (saveGuia) {
        saveGuia.addEventListener('click', function () {
            var orderId = document.getElementById('hawa-guia-order-id').value;
            var postcode = document.getElementById('hawa-guia-postcode').value;
            var costo = document.getElementById('hawa-guia-costo').value;
            var montoEfectivo = document.getElementById('hawa-guia-monto-efectivo').value;

            if (!postcode) {
                alert('Ingrese el número de guía');
                return;
            }

            saveGuia.disabled = true;
            saveGuia.textContent = 'Guardando...';

            ajaxPost('hawa_vendedor_guia', {
                order_id: orderId,
                postcode: postcode,
                costo_courier: costo,
                monto_efectivo: montoEfectivo
            }, function (res) {
                saveGuia.disabled = false;
                saveGuia.textContent = 'Guardar';

                if (res.success) {
                    document.getElementById('hawa-modal-guia').classList.remove('show');
                    flashRow(orderId, '#d4edda');
                    setTimeout(function () { location.reload(); }, 800);
                } else {
                    alert('Error: ' + (res.data?.message || 'Desconocido'));
                }
            });
        });
    }

    // ── Close Modals ─────────────────────────────────────

    document.addEventListener('click', function (e) {
        if (e.target.classList.contains('hawa-modal-close')) {
            e.target.closest('.hawa-modal').classList.remove('show');
        }
        if (e.target.classList.contains('hawa-modal')) {
            e.target.classList.remove('show');
        }
    });
});
