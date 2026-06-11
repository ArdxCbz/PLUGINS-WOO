/*
 * Finanzas Ventova — JS de admin (encolado).
 *
 * Tres módulos independientes, todos defensivos (si su elemento no está en la
 * página, no hacen nada):
 *  1. Form de movimientos: filtra el select de Motivo por TIPO ∩ CUENTA y maneja
 *     la descripción obligatoria. El mapa cuenta→motivos llega en data-motivos.
 *  2. Configuración: muestra el select de Naturaleza solo para el grupo "ambos".
 *  3. Guard anti-doble-submit: deshabilita el botón tras enviar cualquier form
 *     POST para evitar movimientos duplicados por doble clic o red lenta.
 */
(function () {
    'use strict';

    function ready(fn) {
        if (document.readyState !== 'loading') { fn(); }
        else { document.addEventListener('DOMContentLoaded', fn); }
    }

    // ── 1) Form de movimientos: filtro motivo por tipo ∩ cuenta ─────────────
    function initMovementForm() {
        var typeSel = document.getElementById('fin-mov-type');
        if (!typeSel) { return; }
        var catSel   = document.getElementById('fin-mov-cat');
        var accSel   = document.getElementById('fin-mov-account');
        var desc     = document.getElementById('fin-mov-desc');
        var descLbl  = document.getElementById('fin-mov-desc-label');
        var descHint = document.getElementById('fin-mov-desc-hint');
        var submit   = document.getElementById('fin-mov-submit');
        var noMot    = document.getElementById('fin-mov-nomot');

        // Mapa cuenta → [ids permitidos] desde el data attribute del select de cuenta.
        var ALLOWED = {};
        if (accSel && accSel.dataset.motivos) {
            try { ALLOWED = JSON.parse(accSel.dataset.motivos) || {}; } catch (e) { ALLOWED = {}; }
        }
        function allowedFor(accId) {
            var k = String(accId);
            return (ALLOWED && Object.prototype.hasOwnProperty.call(ALLOWED, k)) ? ALLOWED[k] : [];
        }

        function syncDesc() {
            if (!catSel || !desc) { return; }
            var opt = catSel.options[catSel.selectedIndex];
            var req = opt && opt.getAttribute('data-reqdesc') === '1';
            desc.required = !!req;
            if (descLbl)  { descLbl.innerHTML = req ? 'Descripción *' : 'Descripción'; }
            if (descHint) { descHint.style.display = req ? '' : 'none'; }
        }

        function sync() {
            var nature  = typeSel.value;
            var accId   = accSel ? accSel.value : '';
            var allowed = allowedFor(accId);
            var anyVisible = false;

            catSel.querySelectorAll('optgroup').forEach(function (g) {
                var natOk = (nature === 'ingreso' && g.classList.contains('fin-cat-ingreso')) ||
                            (nature === 'egreso'  && g.classList.contains('fin-cat-egreso'));
                var groupHasVisible = false;
                g.querySelectorAll('option').forEach(function (o) {
                    var id = parseInt(o.value, 10);
                    var ok = natOk && allowed.indexOf(id) !== -1;
                    o.disabled = !ok;
                    o.hidden   = !ok;
                    if (ok) { groupHasVisible = true; anyVisible = true; }
                });
                g.style.display = groupHasVisible ? '' : 'none';
            });

            var placeholder = catSel.querySelector('.fin-cat-empty');
            if (anyVisible) {
                var current = catSel.options[catSel.selectedIndex];
                if (!current || current.disabled || current.hidden) {
                    var firstVisible = catSel.querySelector('option:not([disabled]):not([hidden])');
                    if (firstVisible) { catSel.value = firstVisible.value; }
                }
                if (placeholder) { placeholder.hidden = true; }
                catSel.disabled = false;
                if (submit) { submit.disabled = false; }
                if (noMot)  { noMot.style.display = 'none'; }
            } else {
                if (placeholder) { placeholder.hidden = false; catSel.value = ''; }
                if (submit) { submit.disabled = true; }
                if (noMot)  { noMot.style.display = ''; }
            }
            syncDesc();
        }

        typeSel.addEventListener('change', sync);
        if (accSel) { accSel.addEventListener('change', sync); }
        if (catSel) { catSel.addEventListener('change', syncDesc); }
        sync();
    }

    // ── 2) Configuración: naturaleza solo para grupo "ambos" ────────────────
    function initCategoryGroup() {
        var sel  = document.getElementById('fin-cat-group');
        var wrap = document.getElementById('fin-cat-nature-wrap');
        if (!sel || !wrap) { return; }
        function sync() {
            var opt = sel.options[sel.selectedIndex];
            var nature = opt ? opt.getAttribute('data-nature') : '';
            wrap.style.display = (nature === 'ambos') ? '' : 'none';
        }
        sel.addEventListener('change', sync);
        sync();
    }

    // ── 2b) Multi-moneda: campo de TC en ingreso/egreso y traspaso ──────────
    function initCurrency() {
        // Ingreso/egreso: muestra el campo de TC cuando la cuenta no es base.
        var accSel = document.getElementById('fin-mov-account');
        if (accSel) {
            var base       = accSel.getAttribute('data-base') || 'BOB';
            var amountCur  = document.getElementById('fin-mov-amount-cur');
            var rateWrap   = document.getElementById('fin-mov-rate-wrap');
            var rateCur    = document.getElementById('fin-mov-rate-cur');
            var rateInput  = document.getElementById('fin-mov-rate');

            var syncMov = function () {
                var opt = accSel.options[accSel.selectedIndex];
                if (!opt) { return; }
                var cur    = opt.getAttribute('data-currency') || base;
                var symbol = opt.getAttribute('data-symbol') || cur;
                var rate   = parseFloat(opt.getAttribute('data-rate') || '0') || 0;
                if (amountCur) { amountCur.textContent = symbol; }
                var isBase = (cur === base);
                if (rateWrap) { rateWrap.style.display = isBase ? 'none' : ''; }
                if (rateCur)  { rateCur.textContent = symbol; }
                if (rateInput) {
                    rateInput.required = !isBase;
                    if (!isBase && (!rateInput.value || parseFloat(rateInput.value) <= 0) && rate > 0) {
                        rateInput.value = rate;
                    }
                }
            };
            accSel.addEventListener('change', syncMov);
            syncMov();
        }

        // Traspaso: TC + vista previa del monto que llega al destino.
        var trForm = document.getElementById('fin-tr-form');
        if (trForm) {
            var trBase   = trForm.getAttribute('data-base') || 'BOB';
            var fromSel  = document.getElementById('fin-tr-from');
            var toSel    = document.getElementById('fin-tr-to');
            var amtCur   = document.getElementById('fin-tr-amount-cur');
            var amtInput = document.getElementById('fin-tr-amount');
            var trWrap   = document.getElementById('fin-tr-rate-wrap');
            var trCur    = document.getElementById('fin-tr-rate-cur');
            var trRate   = document.getElementById('fin-tr-rate');
            var trPrev   = document.getElementById('fin-tr-preview');

            var optData = function (sel) {
                var o = sel.options[sel.selectedIndex];
                return o ? {
                    cur:    o.getAttribute('data-currency') || trBase,
                    symbol: o.getAttribute('data-symbol') || '',
                    rate:   parseFloat(o.getAttribute('data-rate') || '0') || 0
                } : { cur: trBase, symbol: '', rate: 1 };
            };
            var money = function (n, sym) {
                return sym + ' ' + (Math.round(n * 100) / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            };

            var syncTr = function () {
                if (!fromSel || !toSel) { return; }
                var f = optData(fromSel), t = optData(toSel);
                if (amtCur) { amtCur.textContent = f.symbol || f.cur; }
                var diff = (f.cur !== t.cur);
                if (trWrap) { trWrap.style.display = diff ? '' : 'none'; }
                if (!diff) { if (trRate) { trRate.required = false; } if (trPrev) { trPrev.textContent = ''; } return; }

                // El TC manual aplica a la moneda NO base (caso Bs↔$). La etiqueta y
                // la precarga usan esa moneda.
                var nonBase = (f.cur !== trBase) ? f : ((t.cur !== trBase) ? t : f);
                if (trCur) { trCur.textContent = nonBase.symbol || nonBase.cur; }
                if (trRate) {
                    trRate.required = true;
                    if ((!trRate.value || parseFloat(trRate.value) <= 0) && nonBase.rate > 0) {
                        trRate.value = nonBase.rate;
                    }
                }

                // Vista previa: dest = (monto × tasa_origen) / tasa_destino.
                var manual = parseFloat(trRate ? trRate.value : '0') || 0;
                var amt = parseFloat(amtInput ? amtInput.value : '0') || 0;
                var rf, rt;
                if (f.cur !== trBase && t.cur === trBase)      { rf = manual; rt = 1; }
                else if (f.cur === trBase && t.cur !== trBase) { rf = 1;      rt = manual; }
                else                                            { rf = f.rate; rt = t.rate; }
                if (trPrev) {
                    if (amt > 0 && rf > 0 && rt > 0) {
                        var dest = (amt * rf) / rt;
                        trPrev.textContent = 'Llega a destino: ' + money(dest, t.symbol || t.cur);
                    } else {
                        trPrev.textContent = 'Indica monto y tipo de cambio para ver el destino.';
                    }
                }
            };
            [fromSel, toSel, amtInput, trRate].forEach(function (el) {
                if (el) { el.addEventListener('input', syncTr); el.addEventListener('change', syncTr); }
            });
            syncTr();
        }
    }

    // ── 3) Guard anti-doble-submit en todos los forms POST ──────────────────
    function initSubmitGuard() {
        var forms = document.querySelectorAll('form');
        forms.forEach(function (f) {
            var method = (f.getAttribute('method') || '').toLowerCase();
            if (method !== 'post') { return; }
            f.addEventListener('submit', function (e) {
                if (f.dataset.finSubmitting === '1') { e.preventDefault(); return; }
                f.dataset.finSubmitting = '1';
                // Deshabilitar en el próximo tick: el form ya se serializó, así que
                // NO se pierde el name/value del botón pulsado (p.ej. do=validate).
                var btns = f.querySelectorAll('button[type="submit"], input[type="submit"]');
                setTimeout(function () {
                    btns.forEach(function (b) { b.disabled = true; });
                }, 0);
            });
        });
    }

    ready(function () {
        initMovementForm();
        initCategoryGroup();
        initCurrency();
        initSubmitGuard();
    });
})();
