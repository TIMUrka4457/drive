<?php
require("components/header.php");
require_once __DIR__ . '/inc/wgc.php';

$gid = trim((string)($_GET['g'] ?? ''));
$game = null;
if ($gid !== '') {
    $game = mysql_fetch_assoc(mysql_query("SELECT * FROM wgc_games WHERE public_id='" . mysql_real_escape_string($gid) . "' AND removed=0"));
}
$err = '';
$url = '';
if (!$game) $err = 'Игра не найдена';
elseif (empty($id)) $err = 'login';
elseif ((int)$is_ban === 1) $err = 'Ваш аккаунт заблокирован';
else {
    $mobile = (bool)preg_match('/Mobile|Android|iPhone|iPad/i', $_SERVER['HTTP_USER_AGENT'] ?? '');
    $host = 'https://' . ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST']);
    [$code, $res] = wgc_api('POST', '/v1/sessions', [
        'publicId' => $game['public_id'],
        'playerId' => wgc_player_id((int)$id),
        'currency' => WGC_CURRENCY,
        'mode' => wgc_is_test_key() ? 'demo' : 'real',
        'language' => 'ru',
        'returnUrl' => $host . '/slot',
        'platform' => $mobile ? 'mobile' : 'desktop',
    ], bin2hex(random_bytes(16)));
    if (!empty($res['ok'])) $url = $res['data']['url'];
    else {
        $c = $res['error']['code'] ?? 'error';
        $msgs = ['balance_required' => 'Игра временно недоступна. Попробуйте позже', 'player_blocked' => 'Игры недоступны для вашего аккаунта',
            'country_restricted' => 'Игра недоступна в вашей стране', 'not_found' => 'Игра недоступна', 'rate_limited' => 'Слишком много запросов, попробуйте через минуту',
            'supplier_unavailable' => 'Провайдер игры не отвечает, попробуйте позже', 'busy' => 'Сервер перегружен, попробуйте через минуту'];
        $err = $msgs[$c] ?? 'Не удалось открыть игру';
        if (in_array($c, WGC_REJECT_CODES, true)) {
            mysql_query("UPDATE wgc_games SET rejected=2 WHERE public_id='" . mysql_real_escape_string($game['public_id']) . "'");
            $err = 'Эта игра сейчас недоступна у провайдера — мы убрали её из каталога. Выберите другую игру';
        }
        error_log('WGC session error: ' . $c . ' ' . ($res['error']['message'] ?? ''));
    }
}
?>
<div class="bw-page" style="max-width:1180px;">
  <div class="bw-page-title" style="display:flex;align-items:center;gap:10px;">
    <a href="/slot" class="bw-page-icon" data-testid="slot-back-btn" style="text-decoration:none;"><span class="material-icons">arrow_back</span></a>
    <?= htmlspecialchars($game['name'] ?? 'Слот') ?>
    <?php if ($game): ?><span style="font-size:12px;opacity:.6;margin-left:auto;"><?= htmlspecialchars($game['brand']) ?></span><?php endif; ?>
  </div>
  <?php if ($url): ?>
    <div class="bw-card" data-testid="slot-redirect" style="padding:24px;text-align:center;">
      <div style="margin-bottom:14px;">Открываем игру…</div>
      <a href="<?= htmlspecialchars($url) ?>" target="_blank" rel="noopener" class="bw-btn bw-btn-primary" data-testid="slot-open-link">Открыть игру</a>
    </div>
    <script>window.location.href = <?= json_encode($url) ?>;</script>
  <?php elseif ($err === 'login'): ?>
    <div class="bw-card" data-testid="slot-login-required" style="padding:24px;text-align:center;">
      <div style="margin-bottom:14px;">Войдите или зарегистрируйтесь, чтобы играть</div>
      <a href="/" class="bw-btn bw-btn-primary">На главную</a>
    </div>
  <?php else: ?>
    <div class="bw-card" data-testid="slot-error" style="padding:24px;text-align:center;">
      <div style="margin-bottom:14px;"><?= htmlspecialchars($err) ?></div>
      <a href="/slot" class="bw-btn bw-btn-primary" data-testid="slot-error-back">К другим слотам</a>
    </div>
  <?php endif; ?>
</div>
<?php
require("components/outsidebar.php");
require("components/modal.php");
require("components/footer.php");
?>
