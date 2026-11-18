<?php
// Telegram bot webhook: confirms site login tokens sent via /start login_<token>
require __DIR__ . '/inc/site_config.php';
require_once __DIR__ . '/inc/tg.php';

if (tg_token() === '' || !hash_equals(tg_secret(), $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '')) { http_response_code(401); exit; }
$u = json_decode(file_get_contents('php://input'), true);
$msg = $u['message'] ?? null;
if (!$msg || ($msg['chat']['type'] ?? '') !== 'private' || empty($msg['from']['id'])) exit('ok');

$from = $msg['from'];
$text = trim((string)($msg['text'] ?? ''));
$site = rb_site_url();
$reply = function ($t) use ($msg, $site) {
    try {
        tg_api('sendMessage', ['chat_id' => $msg['chat']['id'], 'text' => $t,
            'reply_markup' => ['inline_keyboard' => [[['text' => 'Открыть RUBIN', 'url' => $site]]]]]);
    } catch (Throwable $e) { error_log($e->getMessage()); }
};

if (preg_match('/^\/start login_([a-f0-9]{32})$/', $text, $m)) {
    $tok = $m[1];
    $id = (int)$from['id'];
    $name = mysql_real_escape_string(mb_substr(trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? '')), 0, 120));
    $un = mysql_real_escape_string(mb_substr((string)($from['username'] ?? ''), 0, 60));
    $min = time() - 600;
    mysql_query("UPDATE rb_tg_login SET status='done', tg_id='$id', tg_name='$name', tg_username='$un' WHERE token='$tok' AND status='new' AND created_at >= $min");
    $reply(mysql_affected_rows() > 0
        ? "Вход подтверждён ✅\nВернитесь на сайт — вы уже вошли в аккаунт."
        : "Ссылка для входа устарела. Нажмите «Войти через Telegram» на сайте ещё раз.");
    exit('ok');
}

if (strpos($text, '/start') === 0) $reply("Добро пожаловать в RUBIN! Чтобы войти, нажмите «Войти через Telegram» на сайте.");
exit('ok');
