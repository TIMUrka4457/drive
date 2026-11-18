# Smoke tests for restored RUBIN PHP casino (pages + login + slots callback proxy)
import os
import re
import pytest
import requests

BASE_URL = os.environ.get('REACT_APP_BACKEND_URL').rstrip('/')
PHP_ERR = re.compile(r'(Fatal error|Parse error|Warning:</b>|Notice:</b>)')


@pytest.fixture(scope="module")
def logged():
    s = requests.Session()
    r = s.post(f"{BASE_URL}/action.php", data={"type": "login", "login": "tst465641", "pass": "Test12345"})
    assert r.status_code == 200
    assert '"success":"success"' in r.text.replace(' ', '')
    return s


# Public pages
@pytest.mark.parametrize("path", ["/", "/slot", "/admin"])
def test_public_pages(path):
    r = requests.get(f"{BASE_URL}{path}")
    assert r.status_code == 200
    assert not PHP_ERR.search(r.text)


def test_home_branding():
    r = requests.get(f"{BASE_URL}/")
    assert "RUBIN" in r.text and "Оригинальные игры" in r.text


# Logged-in pages
@pytest.mark.parametrize("path", ["/dice", "/mines", "/bubbles", "/x50", "/bonus", "/me", "/ref", "/slot",
                                  "/admin", "/admin/users", "/admin/rtp", "/admin/slots", "/admin/promo"])
def test_logged_pages(logged, path):
    r = logged.get(f"{BASE_URL}{path}")
    assert r.status_code == 200
    assert not PHP_ERR.search(r.text)
    assert "tst465641" in r.text


def test_login_wrong_password():
    r = requests.post(f"{BASE_URL}/action.php", data={"type": "login", "login": "tst465641", "pass": "wrong_pw"})
    assert r.status_code == 200
    assert '"success":"success"' not in r.text.replace(' ', '')


# Slots callback proxy (keys missing -> server_misconfigured expected)
def test_slots_callback_json():
    r = requests.post(f"{BASE_URL}/api/slots/callback", json={"method": "getBalance"})
    data = r.json()
    assert data.get("ok") is False
    assert "error" in data
