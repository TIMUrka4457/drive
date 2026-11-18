<?php
// RUBIN crypto payments: CryptoBot (Crypto Pay API) + xRocket (Pay API v1)
require_once __DIR__ . '/rtp.php';

const RB_CB_ASSETS = ['USDT', 'TON', 'BTC', 'ETH', 'LTC', 'BNB', 'TRX', 'USDC'];
// Withdrawal assets: label => [cryptobot code, xrocket code]
const RB_WD_ASSETS = ['USDT' => ['USDT', 'USDT'], 'TON' => ['TON', 'GRAM']];

function rb_env($k) {
    $v = getenv($k);
    if ($v !== false && $v !== '') return $v;
    static $env = null;
    if ($env === null) {
        $env = [];
        $f = '/app/backend/.env';
        if (is_readable($f)) {
            foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if ($line[0] === '#' || strpos($line, '=') === false) continue;
                [$a, $b] = explode('=', $line, 2);
                $env[trim($a)] = trim(trim($b), "\"'");
            }
        }
    }
    return $env[$k] ?? '';
}

function rb_pay_cfg() {
    $c = mysql_fetch_array(mysql_query("SELECT usdt_rub, min_sum_dep, min_withdraw_sum, cashback_pct, fee_crypto FROM kot_config LIMIT 1"));
    return [
        'rate' => max(1, (float)($c['usdt_rub'] ?? 95)),
        'min_dep' => (float)($c['min_sum_dep'] ?? 100),
        'min_wd' => (float)($c['min_withdraw_sum'] ?? 500),
        'cashback' => (float)($c['cashback_pct'] ?? 5),
        'fee' => (float)($c['fee_crypto'] ?? 3),
    ];
}

function rb_providers() {
    return [
        'cryptobot' => rb_env('CRYPTOBOT_TOKEN') !== '',
        'xrocket' => rb_env('XROCKET_TOKEN') !== '',
    ];
}

function rb_http($url, $headers, $method = 'POST', $body = null, &$code = null) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers),
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false) throw new RuntimeException('Network error: ' . $err);
    $out = json_decode($raw, true);
    if (!is_array($out)) throw new RuntimeException('Invalid response (HTTP ' . $code . ')');
    return $out;
}

// File cache for public rate lists (TTL seconds)
function rb_cache($key, $ttl, callable $fn) {
    $f = sys_get_temp_dir() . '/rubin_' . md5($key) . '.json';
    if (is_file($f) && filemtime($f) > time() - $ttl) {
        $d = json_decode((string)file_get_contents($f), true);
        if (is_array($d)) return $d;
    }
    $d = $fn();
    @file_put_contents($f, json_encode($d));
    return $d;
}

// ── CryptoBot ────────────────────────────────────────────────────────────────
function rb_cb($method, $params = []) {
    $base = rb_env('CRYPTOBOT_TESTNET') === '1' ? 'https://testnet-pay.crypt.bot/api/' : 'https://pay.crypt.bot/api/';
    $out = rb_http($base . $method, ['Crypto-Pay-API-Token: ' . rb_env('CRYPTOBOT_TOKEN')], 'POST', $params);
    if (($out['ok'] ?? false) !== true) {
        $e = $out['error'] ?? [];
        throw new RuntimeException('CryptoBot: ' . (is_array($e) ? ($e['name'] ?? json_encode($e)) : $e));
    }
    return $out['result'];
}

// RUB price of 1 unit of each CryptoBot asset
function rb_cb_rates() {
    return rb_cache('cb_rates', 60, function () {
        $r = [];
        foreach (rb_cb('getExchangeRates') as $x) {
            if (!empty($x['is_valid']) && $x['target'] === 'RUB') $r[$x['source']] = (float)$x['rate'];
        }
        return $r;
    });
}

// ── xRocket ──────────────────────────────────────────────────────────────────
function rb_xr($method, $path, $body = null) {
    $base = rtrim(rb_env('XROCKET_BASE') ?: 'https://pay.api.xrocket.exchange', '/');
    $out = rb_http($base . $path, ['Authorization: Bearer ' . rb_env('XROCKET_TOKEN')], $method, $body, $code);
    if ($code < 200 || $code >= 300) {
        $t = basename((string)($out['type'] ?? ''));
        throw new RuntimeException('xRocket: ' . ($out['detail'] ?? $out['title'] ?? ('HTTP ' . $code)) . ($t ? " [$t]" : ''));
    }
    return $out;
}

// Deposit assets available in xRocket: code => ['title', 'rate' (RUB per 1 unit)]
function rb_xr_assets() {
    return rb_cache('xr_assets', 300, function () {
        $cur = rb_xr('GET', '/api/v1/currencies');
        $rates = [];
        foreach (rb_xr('GET', '/api/v1/rates?base=rub') as $x) $rates[$x['currency']] = (float)$x['rate'];
        $out = [];
        foreach ($cur as $c) {
            if (($c['kind'] ?? '') === 'crypto' && ($rates[$c['code']] ?? 0) > 0) {
                $out[$c['code']] = ['title' => $c['code'] === 'GRAM' ? 'Toncoin (TON)' : $c['title'], 'rate' => $rates[$c['code']]];
            }
        }
        return $out;
    });
}

function rb_crypto_amount($rub, $rate, $up = true) {
    $dec = $rate >= 1000 ? 8 : ($rate >= 1 ? 4 : 2);
    $m = 10 ** $dec;
    $v = $up ? ceil($rub / $rate * $m) / $m : floor($rub / $rate * $m) / $m;
    return number_format($v, $dec, '.', '');
}

function rb_site_url() {
    $proto = (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) ? $_SERVER['HTTP_X_FORWARDED_PROTO'] : 'https');
    return $proto . '://' . ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost');
}

// ── Deposits ─────────────────────────────────────────────────────────────────
function rb_create_deposit($uid, $provider, $rub, $asset = '') {
    $uid = (int)$uid; $rub = round((float)$rub, 2);
    if ($provider === 'xrocket') {
        $assets = rb_xr_assets();
        if (!isset($assets[$asset])) throw new RuntimeException('Выберите валюту оплаты');
        $crypto = rb_crypto_amount($rub, $assets[$asset]['rate']);
    }
    $now = date('Y-m-d H:i:s');
    mysql_query("INSERT INTO rb_invoices (user_id, provider, amount_rub, status, created_at) VALUES ('$uid', '$provider', '$rub', 'pending', '$now')");
    $lid = mysql_insert_id();
    $back = rb_site_url() . '/deposit';
    if ($provider === 'cryptobot') {
        $r = rb_cb('createInvoice', [
            'currency_type' => 'fiat', 'fiat' => 'RUB', 'amount' => number_format($rub, 2, '.', ''),
            'accepted_assets' => implode(',', RB_CB_ASSETS),
            'description' => 'Пополнение баланса RUBIN #' . $lid,
            'payload' => (string)$lid, 'expires_in' => 3600,
            'paid_btn_name' => 'callback', 'paid_btn_url' => $back,
        ]);
        $ext = (string)$r['invoice_id'];
        $url = $r['bot_invoice_url'] ?? ($r['pay_url'] ?? '');
        $cr = 'NULL'; $as = 'RUB';
    } else {
        $r = rb_xr('POST', '/api/v1/invoices', [
            'priceAmount' => $crypto, 'priceCurrency' => $asset, 'payCurrencies' => [$asset], 'numPayments' => 1,
            'clientInvoiceId' => 'rubin_dep_' . $lid,
            'description' => 'Пополнение баланса RUBIN #' . $lid . ' (' . $rub . ' ₽)', 'expiresIn' => 3600,
            'callback' => ['callbackUrl' => rb_site_url() . '/payhook.php?p=xrocket', 'payload' => ['id' => $lid]],
            'url' => ['successUrl' => $back, 'cancelUrl' => $back],
        ]);
        $ext = (string)$r['id'];
        $url = $r['links']['telegramBotLink'] ?? '';
        $cr = "'$crypto'"; $as = mysql_real_escape_string($asset);
    }
    $ext_e = mysql_real_escape_string($ext); $url_e = mysql_real_escape_string($url);
    mysql_query("UPDATE rb_invoices SET ext_id='$ext_e', pay_url='$url_e', amount_crypto=$cr, asset='$as' WHERE id='$lid'");
    return ['id' => $lid, 'url' => $url];
}

// Idempotent: credits balance + referral + cashback exactly once
function rb_credit_invoice($lid) {
    $lid = (int)$lid; $now = date('Y-m-d H:i:s');
    mysql_query("UPDATE rb_invoices SET status='paid', paid_at='$now' WHERE id='$lid' AND status IN ('pending','expired')");
    if (mysql_affected_rows() < 1) return false;
    $inv = mysql_fetch_array(mysql_query("SELECT * FROM rb_invoices WHERE id='$lid'"));
    $uid = (int)$inv['user_id']; $rub = (float)$inv['amount_rub'];
    $cfg = rb_pay_cfg();
    $cb = round($rub * $cfg['cashback'] / 100, 2);
    mysql_query("UPDATE kot_user SET balance = balance + '$rub', cashback = cashback + '$cb' WHERE id='$uid'");
    $tx = mysql_real_escape_string($inv['provider'] . ':' . $inv['ext_id']);
    mysql_query("INSERT INTO kot_payments (user_id, suma, data, qiwi, transaction) VALUES ('$uid', '$rub', '" . date('d.m.Y H:i') . "', '" . $inv['provider'] . "', '$tx')");
    rb_ref_reward($uid, $rub);
    return true;
}

// Polls provider for an invoice; credits if paid. Returns status.
function rb_check_invoice($inv) {
    if (!in_array($inv['status'], ['pending', 'expired'], true) || !$inv['ext_id']) return $inv['status'];
    if ($inv['provider'] === 'cryptobot') {
        $r = rb_cb('getInvoices', ['invoice_ids' => $inv['ext_id']]);
        $st = $r['items'][0]['status'] ?? '';
    } else {
        $r = rb_xr('GET', '/api/v1/invoice?invoiceId=' . rawurlencode($inv['ext_id']));
        $st = $r['status'] ?? '';
    }
    if ($st === 'paid') { rb_credit_invoice($inv['id']); return 'paid'; }
    if (in_array($st, ['expired', 'cancelled'], true)) { mysql_query("UPDATE rb_invoices SET status='expired' WHERE id='" . (int)$inv['id'] . "' AND status='pending'"); return 'expired'; }
    return $inv['status'];
}

// ── Withdrawals ──────────────────────────────────────────────────────────────
// RUB price of 1 unit of a withdrawal asset (USDT/TON) for the given provider
function rb_wd_rate($provider, $asset) {
    if (!isset(RB_WD_ASSETS[$asset])) throw new RuntimeException('Выберите валюту вывода');
    if ($provider === 'cryptobot') $rate = rb_cb_rates()[RB_WD_ASSETS[$asset][0]] ?? 0;
    else $rate = rb_xr_assets()[RB_WD_ASSETS[$asset][1]]['rate'] ?? 0;
    if ($rate <= 0) throw new RuntimeException('Курс временно недоступен');
    return $rate;
}

// Sent on admin approval; idempotent via spend_id / clientPayoutId
function rb_send_withdraw($wd) {
    $tg = (string)(int)$wd['wallet'];
    $asset = $wd['asset'] ?? 'USDT';
    if (!isset(RB_WD_ASSETS[$asset])) $asset = 'USDT';
    $amount = rtrim(rtrim(number_format((float)$wd['amount_crypto'], 6, '.', ''), '0'), '.');
    $spend = 'rubin_wd_' . (int)$wd['id'];
    $note = 'Вывод RUBIN #' . (int)$wd['id'];
    if ($wd['ps'] === 'cryptobot') {
        $r = rb_cb('transfer', ['user_id' => (int)$tg, 'asset' => RB_WD_ASSETS[$asset][0], 'amount' => $amount,
            'spend_id' => $spend, 'comment' => $note]);
        return (string)($r['transfer_id'] ?? '');
    }
    try {
        $r = rb_xr('POST', '/api/v1/payouts', ['clientPayoutId' => $spend, 'target' => $tg, 'targetType' => 'telegram_user_id',
            'asset' => RB_WD_ASSETS[$asset][1], 'amount' => $amount, 'description' => $note]);
    } catch (RuntimeException $e) {
        if (strpos($e->getMessage(), 'payout_client_id_duplicate') === false) throw $e;
        $r = rb_xr('GET', '/api/v1/payout?clientPayoutId=' . rawurlencode($spend));
    }
    if (($r['status'] ?? '') === 'failed') throw new RuntimeException('xRocket: payout failed');
    return (string)($r['payoutId'] ?? $spend);
}
