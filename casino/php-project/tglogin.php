<?php
session_start();
require __DIR__ . '/inc/site_config.php';
require_once __DIR__ . '/inc/tg.php';
header('Content-Type: application/json; charset=utf-8');

function out($a) { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }
if (tg_token() === '') out(['success' => false, 'error' => 'Вход через Telegram пока не подключён']);
$type = $_POST['type'] ?? '';

if ($type === 'start') {
    try { tg_ensure_webhook(); $bot = tg_bot_username(); }
    catch (Throwable $e) { error_log($e->getMessage()); out(['success' => false, 'error' => 'Telegram недоступен, попробуйте позже']); }
    $tok = bin2hex(random_bytes(16));
    $ref = mysql_real_escape_string((string)(int)($_SESSION['ref_id'] ?? 0));
    mysql_query("DELETE FROM rb_tg_login WHERE created_at < " . (time() - 86400));
    mysql_query("INSERT INTO rb_tg_login (token, created_at, ref_id) VALUES ('$tok', '" . time() . "', '$ref')");
    $_SESSION['tg_login_token'] = $tok;
    out(['success' => true, 'url' => 'https://t.me/' . $bot . '?start=login_' . $tok, 'token' => $tok]);
}

if ($type === 'poll') {
    $tok = (string)($_SESSION['tg_login_token'] ?? '');
    if ($tok === '' || !hash_equals($tok, (string)($_POST['token'] ?? ''))) out(['success' => false, 'error' => 'Начните вход заново']);
    $te = mysql_real_escape_string($tok);
    $row = mysql_fetch_array(mysql_query("SELECT * FROM rb_tg_login WHERE token='$te'"));
    if (!$row || (int)$row['created_at'] < time() - 600) out(['success' => false, 'error' => 'Время входа истекло, попробуйте снова']);
    if ($row['status'] !== 'done') out(['success' => true, 'status' => 'wait']);
    mysql_query("UPDATE rb_tg_login SET status='used' WHERE token='$te' AND status='done'");
    if (mysql_affected_rows() < 1) out(['success' => true, 'status' => 'wait']);
    $u = tg_user_for($row['tg_id'], $row['tg_username'], $row['ref_id']);
    if (!$u) out(['success' => false, 'error' => 'Не удалось создать аккаунт']);
    if ((int)$u['ban'] === 1) out(['success' => false, 'error' => 'Ваш аккаунт заблокирован']);
    session_regenerate_id(true);
    $_SESSION['hash'] = $u['hash'];
    $_SESSION['login'] = 1;
    unset($_SESSION['tg_login_token']);
    out(['success' => true, 'status' => 'ok']);
}

if ($type === 'widget') {
    $d = [];
    foreach (['id', 'first_name', 'last_name', 'username', 'photo_url', 'auth_date'] as $k) if (isset($_POST[$k])) $d[$k] = (string)$_POST[$k];
    $hash = (string)($_POST['hash'] ?? '');
    ksort($d);
    $check = implode("\n", array_map(fn($k) => "$k={$d[$k]}", array_keys($d)));
    $want = hash_hmac('sha256', $check, hash('sha256', tg_token(), true));
    if ($hash === '' || empty($d['id']) || !hash_equals($want, $hash)) out(['success' => false, 'error' => 'Не удалось подтвердить вход через Telegram']);
    if ((int)($d['auth_date'] ?? 0) < time() - 86400) out(['success' => false, 'error' => 'Данные входа устарели, попробуйте снова']);
    $tid = (int)$d['id'];
    $sh = mysql_real_escape_string((string)($_SESSION['hash'] ?? ''));
    $me = $sh !== '' ? mysql_fetch_array(mysql_query("SELECT id, tg_auth_id FROM kot_user WHERE hash='$sh' LIMIT 1")) : null;
    if ($me) {
        if ((int)$me['tg_auth_id'] === $tid) out(['success' => true, 'linked' => true]);
        if (!empty($me['tg_auth_id'])) out(['success' => false, 'error' => 'К аккаунту уже привязан другой Telegram']);
        if (mysql_fetch_array(mysql_query("SELECT id FROM kot_user WHERE tg_auth_id='$tid' LIMIT 1"))) out(['success' => false, 'error' => 'Этот Telegram уже привязан к другому аккаунту']);
        mysql_query("UPDATE kot_user SET tg_auth_id='$tid', tg_id=IFNULL(tg_id, '$tid') WHERE id='" . (int)$me['id'] . "'");
        out(['success' => true, 'linked' => true]);
    }
    $u = tg_user_for($d['id'], $d['username'] ?? '', $_SESSION['ref_id'] ?? 0);
    if (!$u) out(['success' => false, 'error' => 'Не удалось создать аккаунт']);
    if ((int)$u['ban'] === 1) out(['success' => false, 'error' => 'Ваш аккаунт заблокирован']);
    session_regenerate_id(true);
    $_SESSION['hash'] = $u['hash'];
    $_SESSION['login'] = 1;
    out(['success' => true]);
}

out(['success' => false, 'error' => 'Unknown action']);
