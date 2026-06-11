<?php
if (!defined('ABSPATH')) {
    exit;
}
/**
 * Partial reutilizable: panel de "Gastos de importación" de una compra.
 * Lo incluyen el form de borrador (admin-purchase-form.php) y la vista de compra
 * recibida (admin-purchase-detail.php), porque desde 2.6 los gastos se pueden
 * registrar/facturar tanto antes como después de recibir.
 *
 * Flujo (2.6+): registrar → facturar. "Registrar gasto" guarda el registro en estado
 * PENDIENTE (sin tocar Finanzas). El botón "Facturar" de cada registro postea el
 * egreso en la caja elegida y lo pasa a FACTURADO. La Σ por moneda cuenta solo los
 * facturados (lo efectivamente posteado a Finanzas). Nota: a nivel interno el estado
 * facturado sigue siendo `active` y la acción AJAX `iem_validate_purchase_expense`.
 *
 * Moneda dual (2.5+): caja en $ → se ingresan $ y Bs y el TC se calcula; caja en Bs
 * → se ingresan Bs y TC y el equivalente en $ se calcula.
 *
 * @var bool   $ic_available
 * @var array  $ic_accounts   lista de cajas [id,name,currency,symbol,is_base,default_rate]
 * @var array  $ic_types      motivos activos
 * @var array  $ic_expenses   gastos de la compra (pending + active)
 * @var array  $ic_totals     totales por moneda (solo validados)
 * @var float  $ic_usd_rate   TC USD por defecto (Bs/$)
 * @var int    $ic_purchase_id id de la compra (cae a $f_id si no se pasó)
 * @var string $ajax_nonce
 */
$ic_available   = $ic_available   ?? false;
$ic_accounts    = $ic_accounts    ?? [];
$ic_types       = $ic_types       ?? [];
$ic_expenses    = $ic_expenses    ?? [];
$ic_totals      = $ic_totals      ?? [];
$ic_usd_rate    = $ic_usd_rate    ?? 0.0;
$ic_purchase_id = (int) ($ic_purchase_id ?? ($f_id ?? 0));

// Mapa account_id => meta (para mostrar la caja de cada gasto).
$ic_acc_map = [];
foreach ($ic_accounts as $a) { $ic_acc_map[(int) $a['id']] = $a; }
$ic_fmt_total = function ($t) { return $t['symbol'] . ' ' . number_format((float) $t['total'], 2, '.', ','); };
// Formatea $ / TC con hasta 6 decimales, sin ceros sobrantes ('' si es 0).
$ic_fmt6 = function ($v) {
    $v = (float) $v;
    if ($v <= 0) { return ''; }
    $s = number_format($v, 6, '.', '');
    return (strpos($s, '.') !== false) ? rtrim(rtrim($s, '0'), '.') : $s;
};
// TC promedio ponderado (Σ Bs ÷ Σ $) de los gastos facturados de la compra. Se
// muestra como un dato más al final de la línea de Σ por moneda.
$ic_tc_avg = IEM_Import_Costs::tc_weighted_average($ic_purchase_id);
?>
<h2 style="margin-top:24px;">Gastos de importación</h2>
<div class="iem-card" style="padding:14px 16px;" id="iem-exp-panel"
     data-purchase="<?php echo (int) $ic_purchase_id; ?>"
     data-usd-rate="<?php echo esc_attr(number_format((float) $ic_usd_rate, 6, '.', '')); ?>"
     data-accounts="<?php echo esc_attr(wp_json_encode(array_values($ic_accounts))); ?>">
    <?php if (!$ic_available): ?>
        <div class="notice notice-warning inline" style="margin:0;"><p>
            El plugin <strong>Finanzas Ventova</strong> no está activo. Los gastos de importación
            requieren Finanzas para registrar el egreso en caja.
        </p></div>
    <?php elseif (empty($ic_accounts)): ?>
        <div class="notice notice-warning inline" style="margin:0;"><p>
            No hay <strong>cajas activas</strong> en Finanzas. Crea una en
            <strong>Finanzas Ventova → Cuentas</strong> (puede ser en Bs o en dólares).
        </p></div>
    <?php else: ?>
        <p class="iem-help" style="margin:0 0 10px;">
            Cada gasto se <strong>registra primero como pendiente</strong> (no toca la caja);
            luego se <strong>factura</strong> con el botón de su fila para postear el
            <strong>egreso</strong> en la caja elegida. Si la caja es en <strong>$</strong>, ingresa el
            monto en $ y en Bs y el <strong>TC se calcula</strong>; si es en <strong>Bs</strong>, ingresa el
            monto en Bs y el <strong>TC</strong>, y el equivalente en $ se calcula.
        </p>

        <div style="display:grid;grid-template-columns:1.3fr 1.2fr 0.9fr 0.9fr 0.9fr 0.7fr;gap:10px;align-items:end;max-width:980px;">
            <p style="margin:0;">
                <label>Motivo
                    <select id="iem-exp-type" style="width:100%;">
                        <option value="">— Elegir —</option>
                        <?php foreach ($ic_types as $tp): ?>
                            <option value="<?php echo (int) $tp['id']; ?>"><?php echo esc_html($tp['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </p>
            <p style="margin:0;">
                <label>Caja
                    <select id="iem-exp-account" style="width:100%;">
                        <option value="">— Elegir —</option>
                        <?php foreach ($ic_accounts as $a): ?>
                            <option value="<?php echo (int) $a['id']; ?>"
                                    data-currency="<?php echo esc_attr($a['currency']); ?>"
                                    data-symbol="<?php echo esc_attr($a['symbol']); ?>"
                                    data-base="<?php echo $a['is_base'] ? '1' : '0'; ?>"
                                    data-rate="<?php echo esc_attr(number_format((float) $a['default_rate'], 6, '.', '')); ?>">
                                <?php echo esc_html($a['name'] . ' (' . $a['currency'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </p>
            <p style="margin:0;">
                <label>Monto Bs
                    <input type="number" id="iem-exp-bob" min="0" step="0.01" value="" style="width:100%;">
                </label>
            </p>
            <p style="margin:0;">
                <label>Monto $
                    <input type="number" id="iem-exp-usd" min="0" step="0.000001" value="" style="width:100%;">
                </label>
            </p>
            <p style="margin:0;">
                <label>TC (Bs/$)
                    <input type="number" id="iem-exp-tc" min="0" step="0.000001" value="" style="width:100%;">
                </label>
            </p>
            <p style="margin:0;">
                <button type="button" class="button button-primary" id="iem-exp-add">Registrar gasto</button>
            </p>
        </div>
        <?php if (empty($ic_types)): ?>
            <p class="iem-help" style="color:#a00;">No hay motivos activos. Créalos en Configuración.</p>
        <?php endif; ?>
        <p style="margin:6px 0 0;"><span id="iem-exp-feedback" style="font-size:12px;color:#666;"></span></p>

        <table class="widefat striped" style="margin-top:12px;max-width:1040px;" id="iem-exp-table">
            <thead><tr>
                <th>Motivo</th>
                <th style="width:160px;">Caja</th>
                <th style="width:110px;text-align:right;">Monto Bs</th>
                <th style="width:110px;text-align:right;">Monto $</th>
                <th style="width:100px;text-align:right;">TC (Bs/$)</th>
                <th style="width:150px;">Estado</th>
                <th style="width:90px;text-align:center;">Acción</th>
            </tr></thead>
            <tbody>
            <?php if (empty($ic_expenses)): ?>
                <tr id="iem-exp-empty"><td colspan="7"><em>Sin gastos adjuntados.</em></td></tr>
            <?php else: foreach ($ic_expenses as $ex):
                $ex_cur    = (string) ($ex['fin_currency'] ?: 'BOB');
                $ex_acc    = $ic_acc_map[(int) $ex['fin_account_id']] ?? null;
                $ex_name   = $ex_acc ? $ex_acc['name'] : ('#' . (int) $ex['fin_account_id']);
                $ex_base   = $ex_acc ? !empty($ex_acc['is_base']) : ($ex_cur === 'BOB');
                $ex_status = (string) ($ex['status'] ?? 'pending');
                $is_valid  = ($ex_status === 'active');
                // Caja en Bs → el $ es derivado (readonly); caja en $ → el TC es derivado.
                $usd_ro    = $ex_base ? ' readonly' : '';
                $tc_ro     = $ex_base ? '' : ' readonly';
            ?>
                <tr data-expense-id="<?php echo (int) $ex['id']; ?>"
                    data-account="<?php echo (int) $ex['fin_account_id']; ?>"
                    data-base="<?php echo $ex_base ? '1' : '0'; ?>"
                    data-status="<?php echo esc_attr($ex_status); ?>">
                    <td><?php echo esc_html($ex['name']); ?></td>
                    <td><?php echo esc_html($ex_name . ' (' . $ex_cur . ')'); ?></td>
                    <td style="text-align:right;white-space:nowrap;">
                        <span style="color:#666;">Bs</span>
                        <input type="number" class="iem-exp-bob" min="0" step="0.01"
                               value="<?php echo esc_attr(number_format((float) $ex['amount_bob'], 2, '.', '')); ?>"
                               style="width:95px;text-align:right;">
                    </td>
                    <td style="text-align:right;white-space:nowrap;">
                        <span style="color:#666;">$</span>
                        <input type="number" class="iem-exp-usd" min="0" step="0.000001"<?php echo $usd_ro; ?>
                               value="<?php echo esc_attr($ic_fmt6($ex['amount_usd'])); ?>"
                               style="width:95px;text-align:right;">
                    </td>
                    <td style="text-align:right;white-space:nowrap;">
                        <input type="number" class="iem-exp-tc" min="0" step="0.000001"<?php echo $tc_ro; ?>
                               value="<?php echo esc_attr($ic_fmt6($ex['tc'])); ?>"
                               style="width:90px;text-align:right;">
                    </td>
                    <td class="iem-exp-state-cell">
                        <?php if ($is_valid): ?>
                            <span class="iem-exp-badge iem-exp-badge--ok">✓ Facturado</span>
                        <?php else: ?>
                            <span class="iem-exp-badge iem-exp-badge--pend">Pendiente</span>
                            <button type="button" class="button button-small button-primary iem-exp-validate">Facturar</button>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;">
                        <span class="iem-savestate" data-expense-id="<?php echo (int) $ex['id']; ?>"></span>
                        <button type="button" class="button button-small iem-exp-delete">Eliminar</button>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="2" style="text-align:right;">Σ Gastos facturados por moneda:</th>
                    <th colspan="5" style="text-align:left;font-family:monospace;" id="iem-exp-sum">
                        <?php
                        $parts = [];
                        foreach ($ic_totals as $t) { $parts[] = $ic_fmt_total($t); }
                        $sum_txt = empty($parts) ? '—' : implode('  ·  ', $parts);
                        if ((float) ($ic_tc_avg['tc'] ?? 0) > 0) {
                            $sum_txt .= '  ·  TC prom. ' . number_format((float) $ic_tc_avg['tc'], 4, '.', ',') . ' Bs/$';
                        }
                        echo esc_html($sum_txt);
                        ?>
                    </th>
                </tr>
            </tfoot>
        </table>
    <?php endif; ?>
</div>

<style>
.iem-exp-badge{display:inline-block;padding:1px 8px;border-radius:10px;font-size:11px;font-weight:600;white-space:nowrap;}
.iem-exp-badge--ok{background:#edfaef;color:#1d6e3d;border:1px solid #1d6e3d;}
.iem-exp-badge--pend{background:#fff4e5;color:#9a6700;border:1px solid #d68f00;margin-right:6px;}
</style>

<?php if ($ic_available && !empty($ic_accounts)): ?>
<script>
(function(){
    var IEM_EXP_AJAX = {
        url:   <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>,
        nonce: <?php echo wp_json_encode($ajax_nonce ?? ''); ?>,
        field: <?php echo wp_json_encode(IEM_Ajax::NONCE_FIELD); ?>
    };

    function post(action, data, cb){
        var form = new FormData();
        form.append('action', action);
        form.append(IEM_EXP_AJAX.field, IEM_EXP_AJAX.nonce);
        Object.keys(data).forEach(function(k){ form.append(k, data[k]); });
        fetch(IEM_EXP_AJAX.url, { method:'POST', credentials:'same-origin', body: form })
            .then(function(r){ return r.json().then(function(j){ return { ok:r.ok, j:j }; }); })
            .then(function(res){ cb(null, res); })
            .catch(function(e){ cb(e); });
    }
    function fmt(n){
        return Number(n).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
    }
    function escapeHtml(s){
        return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
        });
    }
    function trimNum(x){
        x = Number(x) || 0;
        if (x <= 0) return '';
        var s = x.toFixed(6);
        return s.indexOf('.') >= 0 ? s.replace(/0+$/, '').replace(/\.$/, '') : s;
    }

    var $expPanel = document.getElementById('iem-exp-panel');
    if (!$expPanel) return;

    var $expType    = document.getElementById('iem-exp-type');
    var $expAccount = document.getElementById('iem-exp-account');
    var $expBob     = document.getElementById('iem-exp-bob');
    var $expUsd     = document.getElementById('iem-exp-usd');
    var $expTc      = document.getElementById('iem-exp-tc');
    var $expAdd     = document.getElementById('iem-exp-add');
    var $expFb      = document.getElementById('iem-exp-feedback');
    var $expTbody   = $expPanel.querySelector('#iem-exp-table tbody');
    var $expSum     = document.getElementById('iem-exp-sum');
    var expPurchaseId = parseInt($expPanel.getAttribute('data-purchase'), 10) || 0;
    var expUsdRate    = parseFloat($expPanel.getAttribute('data-usd-rate')) || 0;

    var ACC_MAP = {};
    try {
        (JSON.parse($expPanel.getAttribute('data-accounts') || '[]') || []).forEach(function(a){
            ACC_MAP[String(a.id)] = a;
        });
    } catch(e) {}

    // Σ por moneda (solo validados); al final, el TC promedio ponderado. Desde recon.
    function applyRecon(recon){
        if (!recon || !$expSum) return;
        var totals = recon.totals || [];
        var txt = totals.length
            ? totals.map(function(t){ return t.symbol + ' ' + fmt(t.total); }).join('  ·  ')
            : '—';
        var a = recon.tc_avg;
        if (a && Number(a.tc) > 0) {
            txt += '  ·  TC prom. ' + Number(a.tc).toFixed(4) + ' Bs/$';
        }
        $expSum.textContent = txt;
    }

    // Vincula Bs/$/TC. Caja en Bs: editan Bs y TC, $ derivado. Caja en $: editan $ y Bs, TC derivado.
    function recalcTriple(bobEl, usdEl, tcEl, isBase){
        if (!bobEl || !usdEl || !tcEl) return;
        var bob = parseFloat(bobEl.value || '0') || 0;
        var usd = parseFloat(usdEl.value || '0') || 0;
        var tc  = parseFloat(tcEl.value  || '0') || 0;
        if (isBase) {
            usdEl.readOnly = true;  tcEl.readOnly = false;
            usdEl.value = (bob > 0 && tc > 0) ? trimNum(bob / tc) : '';
        } else {
            usdEl.readOnly = false; tcEl.readOnly = true;
            tcEl.value = (bob > 0 && usd > 0) ? trimNum(bob / usd) : '';
        }
    }
    function addIsBase(){
        var opt = $expAccount ? $expAccount.options[$expAccount.selectedIndex] : null;
        return opt && opt.value ? (opt.getAttribute('data-base') === '1') : true;
    }
    function recalcAdd(){ recalcTriple($expBob, $expUsd, $expTc, addIsBase()); }

    function syncExpCurrency(){
        var opt = $expAccount ? $expAccount.options[$expAccount.selectedIndex] : null;
        var hasCaja = !!(opt && opt.value);
        var isBase = addIsBase();
        if (hasCaja && isBase && (!$expTc.value || parseFloat($expTc.value) <= 0) && expUsdRate > 0) {
            $expTc.value = trimNum(expUsdRate);
        }
        recalcAdd();
    }
    if ($expAccount) { $expAccount.addEventListener('change', syncExpCurrency); }
    [$expBob, $expUsd, $expTc].forEach(function(el){ if (el) el.addEventListener('input', recalcAdd); });
    syncExpCurrency();

    function expEmptyRow(show){
        var empty = document.getElementById('iem-exp-empty');
        if (show && !empty) {
            var tr = document.createElement('tr');
            tr.id = 'iem-exp-empty';
            tr.innerHTML = '<td colspan="7"><em>Sin gastos adjuntados.</em></td>';
            $expTbody.appendChild(tr);
        } else if (!show && empty) {
            empty.parentNode.removeChild(empty);
        }
    }

    // Contenido de la celda Estado según el estado del gasto.
    function stateCellHtml(ex){
        if (ex.status === 'active') {
            return '<span class="iem-exp-badge iem-exp-badge--ok">✓ Facturado</span>';
        }
        return '<span class="iem-exp-badge iem-exp-badge--pend">Pendiente</span>' +
               '<button type="button" class="button button-small button-primary iem-exp-validate">Facturar</button>';
    }

    function expRowHtml(ex){
        var acc = ACC_MAP[String(ex.fin_account_id)] || null;
        var cur = ex.fin_currency || (acc ? acc.currency : 'BOB');
        var name = acc ? acc.name : ('#' + ex.fin_account_id);
        var isBase = acc ? !!acc.is_base : (cur === 'BOB');
        var usdRO = isBase ? ' readonly' : '';
        var tcRO  = isBase ? '' : ' readonly';
        return '<td>' + escapeHtml(ex.name) + '</td>' +
            '<td>' + escapeHtml(name + ' (' + cur + ')') + '</td>' +
            '<td style="text-align:right;white-space:nowrap;"><span style="color:#666;">Bs</span> ' +
                '<input type="number" class="iem-exp-bob" min="0" step="0.01" value="' +
                Number(ex.amount_bob).toFixed(2) + '" style="width:95px;text-align:right;"></td>' +
            '<td style="text-align:right;white-space:nowrap;"><span style="color:#666;">$</span> ' +
                '<input type="number" class="iem-exp-usd" min="0" step="0.000001"' + usdRO + ' value="' +
                trimNum(ex.amount_usd) + '" style="width:95px;text-align:right;"></td>' +
            '<td style="text-align:right;white-space:nowrap;">' +
                '<input type="number" class="iem-exp-tc" min="0" step="0.000001"' + tcRO + ' value="' +
                trimNum(ex.tc) + '" style="width:90px;text-align:right;"></td>' +
            '<td class="iem-exp-state-cell">' + stateCellHtml(ex) + '</td>' +
            '<td style="text-align:center;"><span class="iem-savestate" data-expense-id="' + ex.id + '"></span> ' +
                '<button type="button" class="button button-small iem-exp-delete">Eliminar</button></td>';
    }

    if ($expAdd) {
        $expAdd.addEventListener('click', function(){
            var tid = parseInt($expType.value || '0', 10) || 0;
            var acc = parseInt($expAccount.value || '0', 10) || 0;
            var isBase = addIsBase();
            recalcAdd();
            var bob = parseFloat($expBob.value || '0') || 0;
            var usd = parseFloat($expUsd.value || '0') || 0;
            var tc  = parseFloat($expTc.value  || '0') || 0;
            if (!tid) { $expFb.style.color = '#a00'; $expFb.textContent = 'Elige un motivo.'; return; }
            if (!acc) { $expFb.style.color = '#a00'; $expFb.textContent = 'Elige la caja.'; return; }
            if (bob <= 0) { $expFb.style.color = '#a00'; $expFb.textContent = 'Indica el monto en Bs.'; return; }
            if (!isBase && usd <= 0) { $expFb.style.color = '#a00'; $expFb.textContent = 'Indica el monto en $.'; return; }
            $expAdd.disabled = true;
            $expFb.style.color = '#666'; $expFb.textContent = 'Registrando gasto…';
            post('iem_add_purchase_expense', {
                purchase_id: expPurchaseId, type_id: tid, account_id: acc,
                amount_bob: bob, amount_usd: usd, tc: tc
            }, function(err, res){
                $expAdd.disabled = false;
                if (err || !res.ok || !res.j.success) {
                    $expFb.style.color = '#a00';
                    $expFb.textContent = '✗ ' + ((res && res.j && res.j.data && res.j.data.message) || 'Error.');
                    return;
                }
                var ex = res.j.data.expense;
                var accMeta = ACC_MAP[String(ex.fin_account_id)] || null;
                var rowBase = accMeta ? !!accMeta.is_base : ((ex.fin_currency || 'BOB') === 'BOB');
                expEmptyRow(false);
                var tr = document.createElement('tr');
                tr.dataset.expenseId = ex.id;
                tr.dataset.account   = ex.fin_account_id;
                tr.dataset.base      = rowBase ? '1' : '0';
                tr.dataset.status    = ex.status || 'pending';
                tr.innerHTML = expRowHtml(ex);
                $expTbody.appendChild(tr);
                applyRecon(res.j.data.recon);
                $expType.value = ''; $expAccount.value = '';
                $expBob.value = ''; $expUsd.value = ''; $expTc.value = '';
                syncExpCurrency();
                $expFb.style.color = '#107010';
                $expFb.textContent = '✓ Gasto registrado (pendiente de facturar).';
            });
        });
    }

    function rowTriple(tr){
        return {
            bob: tr.querySelector('.iem-exp-bob'),
            usd: tr.querySelector('.iem-exp-usd'),
            tc:  tr.querySelector('.iem-exp-tc'),
            isBase: tr.dataset.base === '1'
        };
    }
    function isExpField(el){
        return el && el.classList && (el.classList.contains('iem-exp-bob') ||
            el.classList.contains('iem-exp-usd') || el.classList.contains('iem-exp-tc'));
    }
    $expTbody.addEventListener('input', function(e){
        if (!isExpField(e.target)) return;
        var tr = e.target.closest('tr'); if (!tr) return;
        var t = rowTriple(tr);
        recalcTriple(t.bob, t.usd, t.tc, t.isBase);
    });
    $expTbody.addEventListener('change', function(e){
        if (!isExpField(e.target)) return;
        var tr = e.target.closest('tr'); if (!tr) return;
        var t = rowTriple(tr);
        recalcTriple(t.bob, t.usd, t.tc, t.isBase);
        var eid = tr.dataset.expenseId;
        var accId = parseInt(tr.dataset.account || '0', 10) || 0;
        var bob = parseFloat(t.bob.value || '0') || 0;
        var usd = parseFloat(t.usd.value || '0') || 0;
        var tc  = parseFloat(t.tc.value  || '0') || 0;
        var state = tr.querySelector('.iem-savestate');
        if (state) { state.className = 'iem-savestate saving'; state.textContent = 'guardando…'; }
        post('iem_update_purchase_expense', {
            expense_id: eid, account_id: accId, amount_bob: bob, amount_usd: usd, tc: tc
        }, function(err, res){
            if (err || !res.ok || !res.j.success) {
                if (state) { state.className = 'iem-savestate error'; state.textContent = '✗'; }
                return;
            }
            if (state) { state.className = 'iem-savestate saved'; state.textContent = '✓'; }
            var ex = res.j.data.expense;
            if (ex) {
                t.bob.value = Number(ex.amount_bob).toFixed(2);
                t.usd.value = trimNum(ex.amount_usd);
                t.tc.value  = trimNum(ex.tc);
            }
            applyRecon(res.j.data.recon);
        });
    });

    // Validar un gasto pendiente: postea el egreso y pasa a validado.
    $expPanel.addEventListener('click', function(e){
        if (!e.target.classList || !e.target.classList.contains('iem-exp-validate')) return;
        var tr = e.target.closest('tr'); if (!tr) return;
        var eid = tr.dataset.expenseId;
        var btn = e.target;
        btn.disabled = true;
        var cell = tr.querySelector('.iem-exp-state-cell');
        post('iem_validate_purchase_expense', { expense_id: eid }, function(err, res){
            if (err || !res.ok || !res.j.success) {
                btn.disabled = false;
                alert((res && res.j && res.j.data && res.j.data.message) || 'No se pudo facturar.');
                return;
            }
            var ex = res.j.data.expense;
            tr.dataset.status = ex.status || 'active';
            if (cell) cell.innerHTML = stateCellHtml(ex);
            applyRecon(res.j.data.recon);
        });
    });

    // Eliminar gasto.
    $expPanel.addEventListener('click', function(e){
        if (!e.target.classList || !e.target.classList.contains('iem-exp-delete')) return;
        if (!confirm('¿Eliminar este gasto? Si ya estaba facturado, se reversará su egreso en la caja.')) return;
        var tr = e.target.closest('tr');
        var eid = tr.dataset.expenseId;
        post('iem_delete_purchase_expense', { expense_id: eid }, function(err, res){
            if (err || !res.ok || !res.j.success) {
                alert((res && res.j && res.j.data && res.j.data.message) || 'No se pudo eliminar.');
                return;
            }
            tr.parentNode.removeChild(tr);
            if (!$expTbody.querySelector('tr[data-expense-id]')) expEmptyRow(true);
            applyRecon(res.j.data.recon);
        });
    });
})();
</script>
<?php endif; ?>
