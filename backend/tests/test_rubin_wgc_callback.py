# WGC slots callback (/api/slots/callback -> PHP wgc_wallet.php) regression tests
import hashlib
import hmac
import json
import os
import subprocess
import uuid

import pytest
import requests

BASE_URL = os.environ.get('REACT_APP_BACKEND_URL').rstrip('/')
SECRET = os.environ.get('SLOTS_API_SECRET')
CB = f"{BASE_URL}/api/slots/callback"
MYSQL = ["/app/casino/.runtime/mysql/bin/mysql", "-S", "/app/casino/mysql-run/mysql.sock",
         "-ubeezeuser", "-pbeezepass123", "beezename", "-N", "-e"]
CREATED_TX = []


def sql(q):
    return subprocess.run(MYSQL + [q], capture_output=True, text=True).stdout.strip()


def post(body, sig=None, raw=None):
    raw = raw if raw is not None else json.dumps(body, separators=(",", ":"))
    if sig is None:
        sig = hmac.new(SECRET.encode(), raw.encode(), hashlib.sha256).hexdigest()
    return requests.post(CB, data=raw, headers={"Content-Type": "application/json", "X-WGC-Signature": sig}, timeout=30)


def txid():
    t = "TEST_" + uuid.uuid4().hex[:20]
    CREATED_TX.append(t)
    return t


@pytest.fixture(scope="module", autouse=True)
def guard_real_balance():
    before = sql("SELECT balance FROM kot_user WHERE id=1559")
    yield
    after = sql("SELECT balance FROM kot_user WHERE id=1559")
    for t in CREATED_TX:
        sql(f"DELETE FROM wgc_tx WHERE tx_id='{t}' AND is_test=1")
    assert before == after, f"real balance changed {before} -> {after}"


# getBalance (real player, read-only)
def test_get_balance_real():
    r = post({"command": "getBalance", "playerId": "rubin-1559", "currency": "RUB"})
    assert r.status_code == 200, r.text
    d = r.json()
    assert d["ok"] is True and isinstance(d["balance"], (int, float))
    assert abs(d["balance"] - float(sql("SELECT balance FROM kot_user WHERE id=1559"))) < 0.01


def test_bad_signature():
    r = post({"command": "getBalance", "playerId": "rubin-1559", "currency": "RUB"}, sig="deadbeef")
    assert r.status_code == 401 and r.json()["error"] == "invalid_signature"


def test_missing_signature():
    raw = json.dumps({"command": "getBalance", "playerId": "rubin-1559", "currency": "RUB"})
    r = requests.post(CB, data=raw, headers={"Content-Type": "application/json"}, timeout=30)
    assert r.status_code == 401


def test_unknown_command():
    r = post({"command": "foo", "playerId": "rubin-1559", "currency": "RUB"})
    assert r.status_code == 404 and r.json()["error"] == "unknown_command"


def test_unknown_player():
    r = post({"command": "getBalance", "playerId": "rubin-99999999", "currency": "RUB"})
    assert r.status_code == 200 and r.json()["ok"] is False


def test_wrong_currency_real():
    r = post({"command": "getBalance", "playerId": "rubin-1559", "currency": "USD"})
    assert r.json()["error"] == "currency_not_supported"


# Sandbox wallet: writeBet / idempotency / rollback
PID = "TEST_sandbox_player"


def bal_test():
    return post({"command": "getBalance", "playerId": PID, "currency": "RUB", "test": True}).json()["balance"]


def test_writebet_idempotent_and_rollback():
    b0 = bal_test()
    t = txid()
    body = {"command": "writeBet", "playerId": PID, "currency": "RUB", "transactionId": t,
            "roundId": "r1", "gamePublicId": "3-buzzing-wilds-2", "bet": "10.50", "win": "2.25", "kind": "play", "test": True}
    r1 = post(body).json()
    assert r1["ok"] is True and abs(r1["balance"] - (b0 - 8.25)) < 1e-6
    r2 = post(body).json()
    assert r2 == r1, (r1, r2)
    assert abs(bal_test() - (b0 - 8.25)) < 1e-6
    rb = {"command": "rollback", "playerId": PID, "currency": "RUB", "transactionId": t, "test": True}
    a = post(rb).json()
    assert a["ok"] is True and abs(a["balance"] - b0) < 1e-6
    a2 = post(rb).json()
    assert a2["ok"] is True and abs(a2["balance"] - b0) < 1e-6
    # writeBet after rollback -> transaction_rolled_back
    r3 = post(body).json()
    assert r3["ok"] is True and r3 == r1 or r3.get("error") == "transaction_rolled_back"


def test_rollback_before_bet_then_bet_rejected():
    b0 = bal_test()
    t = txid()
    a = post({"command": "rollback", "playerId": PID, "currency": "RUB", "transactionId": t, "test": True}).json()
    assert a["ok"] is True
    r = post({"command": "writeBet", "playerId": PID, "currency": "RUB", "transactionId": t,
              "bet": 5, "win": 0, "test": True}).json()
    assert r["ok"] is False and r["error"] == "transaction_rolled_back"
    assert abs(bal_test() - b0) < 1e-6


def test_insufficient_funds():
    t = txid()
    r = post({"command": "writeBet", "playerId": PID, "currency": "RUB", "transactionId": t,
              "bet": "999999999999", "win": 0, "test": True}).json()
    assert r["ok"] is False and r["error"] == "insufficient_funds"


def test_invalid_bet_value():
    t = txid()
    r = post({"command": "writeBet", "playerId": PID, "currency": "RUB", "transactionId": t,
              "bet": "-5", "win": 0, "test": True})
    assert r.status_code == 400


def test_missing_tx_id():
    r = post({"command": "writeBet", "playerId": PID, "currency": "RUB", "bet": 1, "win": 0, "test": True})
    assert r.status_code == 400


# Sandbox writeBet kinds: play/bonus/tournament/jackpot/refund all accepted with correct math
@pytest.mark.parametrize("kind,bet,win", [("play", "3", "1.5"), ("bonus", "0", "7.25"), ("tournament", "0", "50"),
                                          ("jackpot", "0", "1000.000001"), ("refund", "0", "3")])
def test_writebet_kinds(kind, bet, win):
    b0 = bal_test()
    r = post({"command": "writeBet", "playerId": PID, "currency": "RUB", "transactionId": txid(), "roundId": "rk-" + kind,
              "gamePublicId": "sweet-bonanza-1", "bet": bet, "win": win, "kind": kind, "test": True}).json()
    assert r["ok"] is True, r
    assert abs(r["balance"] - (b0 - float(bet) + float(win))) < 1e-6
    assert abs(bal_test() - r["balance"]) < 1e-9


def test_unknown_kind_rejected():
    b0 = bal_test()
    r = post({"command": "writeBet", "playerId": PID, "currency": "RUB", "transactionId": txid(),
              "bet": 1, "win": 0, "kind": "weird", "test": True}).json()
    assert r["ok"] is False and r["error"] == "kind_not_supported"
    assert abs(bal_test() - b0) < 1e-9


# Bonus buy: big bet debit, then several free-spin wins with bet 0 in the same round
def test_bonus_buy_then_freespin_wins():
    b0 = bal_test()
    rnd = "bb-" + uuid.uuid4().hex[:8]
    r = post({"command": "writeBet", "playerId": PID, "currency": "RUB", "transactionId": txid(), "roundId": rnd,
              "gamePublicId": "sweet-bonanza-1", "bet": "1000", "win": "0", "kind": "play", "test": True}).json()
    assert r["ok"] is True and abs(r["balance"] - (b0 - 1000)) < 1e-6
    total = 0.0
    for w in ["12.40", "0", "250.10", "3.333333"]:
        r = post({"command": "writeBet", "playerId": PID, "currency": "RUB", "transactionId": txid(), "roundId": rnd,
                  "gamePublicId": "sweet-bonanza-1", "bet": "0", "win": w, "kind": "play", "test": True}).json()
        assert r["ok"] is True, r
        total += float(w)
    assert abs(bal_test() - (b0 - 1000 + total)) < 1e-5


def test_insufficient_funds_no_change():
    b0 = bal_test()
    r = post({"command": "writeBet", "playerId": PID, "currency": "RUB", "transactionId": txid(),
              "bet": str(int(b0) + 10), "win": "0", "test": True}).json()
    assert r["ok"] is False and r["error"] == "insufficient_funds"
    assert abs(r["balance"] - b0) < 1e-6 and abs(bal_test() - b0) < 1e-9


# Sandbox rollback must not touch a real tx with the same id (is_test scoping)
def test_sandbox_rollback_does_not_touch_real_tx():
    before = sql("SELECT rolled_back FROM wgc_tx WHERE tx_id='fs-t1' AND is_test=0")
    r = post({"command": "rollback", "playerId": PID, "currency": "RUB", "transactionId": "fs-t1", "test": True}).json()
    assert r["ok"] is True
    assert sql("SELECT rolled_back FROM wgc_tx WHERE tx_id='fs-t1' AND is_test=0") == before
    sql("DELETE FROM wgc_tx WHERE tx_id='fs-t1' AND is_test=1")
