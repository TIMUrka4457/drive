<?
require ("components/header.php");
require ("components/gamebar.php");
require_once ("inc/payments.php");
$pc = rb_pay_cfg();
$pv = rb_providers();
$fee = $pc['fee'];
$tgid = '';
if (!empty($_SESSION['hash'])) {
  $sh = mysql_real_escape_string($_SESSION['hash']);
  $ur = mysql_fetch_array(mysql_query("SELECT tg_id FROM kot_user WHERE hash='$sh'"));
  $tgid = $ur['tg_id'] ?? '';
}
?>
<div class="bw-page rb-cash" data-testid="cashier-page">
  <div class="bw-page-title">
    <div class="bw-page-icon"><span class="material-icons">account_balance_wallet</span></div>
    Касса
  </div>

  <div class="rb-tabs" data-testid="cashier-tabs">
    <button class="rb-tab active" data-tab="dep" data-testid="cashier-tab-deposit">Пополнение</button>
    <button class="rb-tab" data-tab="wd" data-testid="cashier-tab-withdraw">Вывод</button>
  </div>

  <div class="rb-methods" data-testid="cashier-methods">
    <button class="rb-method active" data-p="cryptobot" data-testid="method-cryptobot">
      <span class="rb-method-logo cb">CB</span>
      <span class="rb-method-txt"><b>CryptoBot</b><small>USDT · TON · BTC · ETH · LTC · BNB · TRX · USDC</small></span>
      <?php if (!$pv['cryptobot']) { ?><span class="rb-soon">скоро</span><?php } ?>
    </button>
    <button class="rb-method" data-p="xrocket" data-testid="method-xrocket">
      <span class="rb-method-logo xr"><span class="material-icons">rocket_launch</span></span>
      <span class="rb-method-txt"><b>xRocket</b><small>TON · USDT · BTC и ещё 60+ монет</small></span>
      <?php if (!$pv['xrocket']) { ?><span class="rb-soon">скоро</span><?php } ?>
    </button>
  </div>

  <!-- Deposit -->
  <div class="rb-pane" id="paneDep" data-testid="deposit-pane">
    <label class="rb-label">Сумма пополнения, ₽</label>
    <input class="rb-input" id="depSum" type="number" min="<?= $pc['min_dep'] ?>" step="1" value="500" data-testid="deposit-amount-input">
    <div class="rb-chips">
      <?php foreach ([100, 500, 1000, 2500, 5000] as $c) { ?>
      <button class="rb-chip" onclick="document.getElementById('depSum').value=<?= $c ?>" data-testid="deposit-chip-<?= $c ?>"><?= $c ?> ₽</button>
      <?php } ?>
    </div>
    <div id="depAssetBox" style="display:none">
      <label class="rb-label">Валюта оплаты в xRocket</label>
      <select class="rb-input" id="depAsset" onchange="rbDepCalc()" data-testid="deposit-asset-select"></select>
      <div class="rb-est" data-testid="deposit-estimate">К оплате ≈ <b id="depEst">—</b></div>
    </div>
    <div class="rb-note" id="depNote" data-testid="deposit-note">Минимум <?= (float)$pc['min_dep'] ?> ₽ · Кешбэк <?= (float)$pc['cashback'] ?>% с каждого депозита · CryptoBot: оплата любой из 8 монет, сумма пересчитывается по курсу</div>
    <button class="rb-btn" id="depBtn" onclick="rbDeposit()" data-testid="deposit-submit-button">Пополнить</button>
  </div>

  <!-- Withdraw -->
  <div class="rb-pane" id="paneWd" style="display:none" data-testid="withdraw-pane">
    <label class="rb-label">Сумма вывода, ₽</label>
    <input class="rb-input" id="wdSum" type="number" min="<?= $pc['min_wd'] ?>" step="1" value="<?= (float)$pc['min_wd'] ?>" oninput="rbWdCalc()" data-testid="withdraw-amount-input">
    <label class="rb-label">Валюта вывода</label>
    <div class="rb-chips" data-testid="withdraw-asset-chips">
      <button class="rb-chip rb-wd-asset active" data-a="USDT" data-testid="withdraw-asset-usdt">USDT</button>
      <button class="rb-chip rb-wd-asset" data-a="TON" data-testid="withdraw-asset-ton">TON</button>
    </div>
    <label class="rb-label">Ваш Telegram ID (цифры)</label>
    <input class="rb-input" id="wdTg" type="text" inputmode="numeric" placeholder="Например 123456789" value="<?= htmlspecialchars((string)$tgid) ?>" data-testid="withdraw-tg-input">
    <div class="rb-note">Узнать ID можно у бота @userinfobot. Выплата приходит в кошелёк выбранного сервиса в Telegram (TON или USDT). Перед первым выводом откройте этот бот хотя бы раз. Минимум <?= (float)$pc['min_wd'] ?> ₽ · Комиссия <?= $fee ?>% от суммы вывода.</div>
    <div class="rb-est" data-testid="withdraw-estimate">Комиссия <b id="wdFee">0</b> ₽ · Вы получите ≈ <b id="wdEst">0</b> <span id="wdEstA">USDT</span></div>
    <button class="rb-btn" id="wdBtn" onclick="rbWithdraw()" data-testid="withdraw-submit-button">Вывести</button>
  </div>

  <div class="rb-hist-title">История</div>
  <div id="rbHist" class="rb-hist" data-testid="cashier-history"></div>
</div>

<script>
var RB_FEE = <?= $fee ?>, rbProv = 'cryptobot', rbTab = 'dep', rbWdA = 'USDT', RB_XR = [], RB_WDR = {};
function rbToast(t, ok) { new Noty({ type: ok ? 'success' : 'error', layout: 'bottomLeft', theme: 'mint', timeout: 3000, text: t }).show(); }
function rbPost(data) { return $.ajax({ type: 'POST', url: '/payapi.php', data: data, dataType: 'json' }); }
document.querySelectorAll('.rb-tab').forEach(function (b) { b.onclick = function () {
  document.querySelectorAll('.rb-tab').forEach(function (x) { x.classList.remove('active'); }); b.classList.add('active');
  rbTab = b.dataset.tab; document.getElementById('paneDep').style.display = rbTab === 'dep' ? '' : 'none';
  document.getElementById('paneWd').style.display = rbTab === 'wd' ? '' : 'none'; }; });
document.querySelectorAll('.rb-method').forEach(function (b) { b.onclick = function () {
  document.querySelectorAll('.rb-method').forEach(function (x) { x.classList.remove('active'); }); b.classList.add('active'); rbProv = b.dataset.p; rbDepUi(); rbWdCalc(); }; });
document.querySelectorAll('.rb-wd-asset').forEach(function (b) { b.onclick = function () {
  document.querySelectorAll('.rb-wd-asset').forEach(function (x) { x.classList.remove('active'); }); b.classList.add('active'); rbWdA = b.dataset.a; rbWdCalc(); }; });
function rbDepUi() { document.getElementById('depAssetBox').style.display = rbProv === 'xrocket' ? '' : 'none'; rbDepCalc(); }
function rbFmt(v) { return v >= 1 ? v.toFixed(4).replace(/\.?0+$/, '') : v.toPrecision(4); }
function rbDepCalc() { var sel = document.getElementById('depAsset'), a = RB_XR[sel.selectedIndex], s = parseFloat(document.getElementById('depSum').value) || 0;
  document.getElementById('depEst').textContent = a ? rbFmt(s / a.rate) + ' ' + (a.code === 'GRAM' ? 'TON' : a.code) : '—'; }
function rbAssets() { rbPost({ type: 'assets' }).done(function (d) { if (!d.success) return; RB_XR = d.xrocket || []; RB_WDR = d.wd || {};
  var sel = document.getElementById('depAsset');
  sel.innerHTML = RB_XR.map(function (a) { return '<option value="' + a.code + '">' + a.title + ' (' + (a.code === 'GRAM' ? 'TON' : a.code) + ')</option>'; }).join('');
  var i = RB_XR.findIndex(function (a) { return a.code === 'USDT'; }); if (i >= 0) sel.selectedIndex = i;
  rbDepCalc(); rbWdCalc(); }); }
document.getElementById('depSum').addEventListener('input', rbDepCalc);
function rbWdCalc() { var s = parseFloat(document.getElementById('wdSum').value) || 0, f = Math.round(s * RB_FEE) / 100, r = (RB_WDR[rbProv] || {})[rbWdA];
  document.getElementById('wdFee').textContent = f.toFixed(2); document.getElementById('wdEstA').textContent = rbWdA;
  document.getElementById('wdEst').textContent = r ? rbFmt((s - f) / r) : '—'; }
function rbDeposit() {
  var btn = document.getElementById('depBtn'); btn.disabled = true; btn.textContent = 'Создаём счёт…';
  var w = window.open('', '_blank');
  rbPost({ type: 'createDeposit', provider: rbProv, sum: document.getElementById('depSum').value, asset: document.getElementById('depAsset').value }).done(function (d) {
    if (d.success) { if (w) w.location.href = d.url; else location.href = d.url; rbToast('Счёт создан. Оплатите его в Telegram — баланс пополнится автоматически', true); rbHistory(); }
    else { if (w) w.close(); rbToast(d.error); }
  }).fail(function () { if (w) w.close(); rbToast('Ошибка соединения'); })
    .always(function () { btn.disabled = false; btn.textContent = 'Пополнить'; });
}
function rbWithdraw() {
  var btn = document.getElementById('wdBtn'); btn.disabled = true;
  rbPost({ type: 'withdraw', provider: rbProv, asset: rbWdA, sum: document.getElementById('wdSum').value, tg_id: document.getElementById('wdTg').value }).done(function (d) {
    if (d.success) { rbToast('Заявка на вывод создана: ' + d.crypto + ' ' + d.asset + ' (комиссия ' + d.fee + ' ₽)', true); updateBalance(0, d.balance); rbHistory(); } else rbToast(d.error);
  }).fail(function () { rbToast('Ошибка соединения'); }).always(function () { btn.disabled = false; });
}
function rbCheck(id) {
  rbPost({ type: 'checkDeposit', id: id }).done(function (d) {
    if (!d.success) return rbToast(d.error);
    if (d.status === 'paid') { rbToast('Оплата получена, баланс пополнен!', true); updateBalance(0, d.balance); }
    else if (d.status === 'pending') rbToast('Оплата ещё не поступила');
    rbHistory();
  });
}
function rbCancelWd(id) { rbPost({ type: 'cancelWithdraw', id: id }).done(function (d) { if (d.success) { rbToast('Заявка отменена, средства возвращены', true); updateBalance(0, d.balance); rbHistory(); } else rbToast(d.error); }); }
var RB_ST = { pending: ['Ожидает оплаты', 'wait'], paid: ['Зачислено', 'ok'], expired: ['Истёк', 'bad'] };
var RB_WST = { '0': ['На проверке', 'wait'], '1': ['Выплачено', 'ok'], '2': ['Отменено', 'bad'] };
var RB_PN = { cryptobot: 'CryptoBot', xrocket: 'xRocket' };
var rbPending = [];
function rbHistory() {
  rbPost({ type: 'history' }).done(function (d) {
    if (!d.success) { document.getElementById('rbHist').innerHTML = '<div class="rb-empty">' + d.error + '</div>'; return; }
    var rows = [];
    rbPending = [];
    d.deposits.forEach(function (x) { var s = RB_ST[x.status] || [x.status, 'wait']; if (x.status === 'pending') rbPending.push(x.id);
      rows.push('<div class="rb-row" data-testid="history-deposit-' + x.id + '"><span class="material-icons in">south_west</span><div class="rb-row-main"><b>+' + parseFloat(x.amount_rub).toFixed(2) + ' ₽</b><small>' + (RB_PN[x.provider] || x.provider) + ' · ' + x.created_at.slice(0, 16) + '</small></div><span class="rb-st ' + s[1] + '">' + s[0] + '</span>' +
        (x.status === 'pending' ? '<div class="rb-row-act"><a href="' + x.pay_url + '" target="_blank" class="rb-mini">Оплатить</a><button class="rb-mini" onclick="rbCheck(' + x.id + ')" data-testid="history-check-' + x.id + '">Проверить</button></div>' : '') + '</div>'); });
    d.withdraws.forEach(function (x) { var s = RB_WST[x.status] || [x.status, 'wait'];
      rows.push('<div class="rb-row" data-testid="history-withdraw-' + x.id + '"><span class="material-icons out">north_east</span><div class="rb-row-main"><b>−' + parseFloat(x.sum).toFixed(2) + ' ₽</b><small>' + (RB_PN[x.ps] || x.ps) + (x.amount_crypto ? ' · ' + rbFmt(parseFloat(x.amount_crypto)) + ' ' + (x.asset || 'USDT') : '') + ' · ' + x.date + '</small></div><span class="rb-st ' + s[1] + '">' + s[0] + '</span>' +
        (x.status === '0' ? '<div class="rb-row-act"><button class="rb-mini" onclick="rbCancelWd(' + x.id + ')" data-testid="history-cancel-' + x.id + '">Отменить</button></div>' : '') + '</div>'); });
    document.getElementById('rbHist').innerHTML = rows.length ? rows.join('') : '<div class="rb-empty">Операций пока нет</div>';
  });
}
setInterval(function () { rbPending.slice(0, 2).forEach(function (id) {
  rbPost({ type: 'checkDeposit', id: id }).done(function (d) { if (d.success && d.status === 'paid') { rbToast('Оплата получена, баланс пополнен!', true); updateBalance(0, d.balance); rbHistory(); } }); }); }, 15000);
if (location.hash === '#withdraw') document.querySelector('[data-tab=wd]').click();
rbDepUi(); rbWdCalc(); rbHistory(); rbAssets();
</script>
<?
require ("components/outsidebar.php");
require ("components/modal.php");
require ("components/footer.php");
?>
