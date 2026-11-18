<?php
// Telegram bot login: deep-link token confirmed by the bot webhook
require_once __DIR__ . '/payments.php';

function tg_token() { return rb_env('TELEGRAM_BOT_TOKEN'); }
function tg_secret() { return substr(hash('sha256', 'rubin-tg-' . tg_token()), 0, 48); }

function tg_api($method, $params = []) {
    $out = rb_http('https://api.telegram.org/bot' . tg_token() . '/' . $method, [], 'POST', $params ?: new stdClass());
    if (empty($out['ok'])) throw new RuntimeException('Telegram: ' . ($out['description'] ?? 'error'));
    return $out['result'];
}

function tg_bot_username() {
    return rb_cache('tg_me_' . md5(tg_token()), 86400, fn() => ['u' => tg_api('getMe')['username']])['u'];
}

// Points the bot webhook at this site (re-checked at most every 10 minutes)
function tg_ensure_webhook() {
    $url = rb_site_url() . '/tgbot.php';
    rb_cache('tg_wh_' . md5(tg_token() . $url), 600, function () use ($url) {
        $info = tg_api('getWebhookInfo');
        if (($info['url'] ?? '') !== $url) {
            tg_api('setWebhook', ['url' => $url, 'secret_token' => tg_secret(), 'allowed_updates' => ['message'], 'drop_pending_updates' => false]);
        }
        return ['url' => $url];
    });
}

function tg_new_hash() {
    $c = 'qazxswedcvfrtgnhyujmkiolp1234567890QAZXSWEDCVFRTGBNHYUJMKIOLP';
    $h = '';
    for ($i = 0; $i < 32; $i++) $h .= $c[random_int(0, strlen($c) - 1)];
    return $h;
}

// Finds the account bound to a Telegram ID or creates a new one. Returns kot_user row.
function tg_user_for($tgId, $username, $refId) {
    $tgId = (int)$tgId;
    $u = mysql_fetch_array(mysql_query("SELECT * FROM kot_user WHERE tg_auth_id='$tgId' LIMIT 1"));
    if ($u) return $u;
    $login = (preg_match('/^[A-Za-z0-9_]{4,20}$/', (string)$username)) ? $username : 'tg' . $tgId;
    $le = mysql_real_escape_string($login);
    if (mysql_fetch_array(mysql_query("SELECT id FROM kot_user WHERE login='$le' LIMIT 1"))) $login = 'tg' . $tgId;
    $le = mysql_real_escape_string($login);
    $hash = tg_new_hash();
    $pass = substr(tg_new_hash(), 0, 12);
    $ip = mysql_real_escape_string($_SERVER['REMOTE_ADDR'] ?? '');
    $ref = mysql_real_escape_string((string)(int)$refId);
    $data = date('d.m.Y H:i:s');
    mysql_query("INSERT INTO kot_user (date_reg, ip, ref_id, login, pass, hash, balance, social, credit, youtube, ava, img, bdate, online_time, startcoin, vk_id, vk_bon, nybonus, tg_id, tg_auth_id)
        VALUES ('$data', '$ip', '$ref', '$le', '$pass', '$hash', '0', '', '0', '0', '0', '', '', '', '0', '0', '0', '0', '$tgId', '$tgId')");
    return mysql_fetch_array(mysql_query("SELECT * FROM kot_user WHERE tg_auth_id='$tgId' LIMIT 1"));
}

const TG_CHANNEL = '@rubin_play';

// true/false = subscribed or not; throws if the bot can't check (not channel admin)
function tg_is_member($tgId) {
    $m = tg_api('getChatMember', ['chat_id' => TG_CHANNEL, 'user_id' => (int)$tgId]);
    $st = $m['status'] ?? '';
    return in_array($st, ['creator', 'administrator', 'member'], true) || ($st === 'restricted' && !empty($m['is_member']));
}
