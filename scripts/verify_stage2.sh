#!/usr/bin/env bash
# =====================================================================
# HMS Stage 2 end-to-end check: login session, CSRF, auth listener, logout.
#
# Usage (the password is read from the environment and never printed):
#   HMS_BASE_URL=http://localhost:8000 \
#   HMS_USER=<an active user from the users table> \
#   HMS_PASS=<its password> \
#   bash scripts/verify_stage2.sh
#
# Without HMS_USER / HMS_PASS the tests that need a real login are skipped.
# Exit code is 0 only when nothing failed.
# =====================================================================
set -u

BASE="${HMS_BASE_URL:-http://localhost:8000}"
BASE="${BASE%/}"
USER_NAME="${HMS_USER:-}"
USER_PASS="${HMS_PASS:-}"

JAR="$(mktemp)"
HDR="$(mktemp)"
BODY="$(mktemp)"
trap 'rm -f "$JAR" "$HDR" "$BODY"' EXIT

PASS=0
FAIL=0
SKIP=0
STATUS=""

ok()   { PASS=$((PASS + 1)); printf '  PASS  %s\n' "$1"; }
bad()  { FAIL=$((FAIL + 1)); printf '  FAIL  %s\n' "$1"; [ -n "${2:-}" ] && printf '        %s\n' "$2"; }
skip() { SKIP=$((SKIP + 1)); printf '  SKIP  %s\n' "$1"; }

# req METHOD PATH [extra curl args]  -> sets STATUS, fills $HDR and $BODY
req() {
    local method="$1" path="$2"
    shift 2
    STATUS="$(curl -s -o "$BODY" -D "$HDR" -w '%{http_code}' \
        -X "$method" -b "$JAR" -c "$JAR" "$@" "$BASE$path")"
}

expect_status() { # label expected
    if [ "$STATUS" = "$2" ]; then ok "$1 (HTTP $STATUS)"; else bad "$1" "expected HTTP $2, got $STATUS"; fi
}
expect_redirect() { # label expected-location-fragment
    local loc
    loc="$(grep -i '^location:' "$HDR" | head -1 | tr -d '\r' | sed 's/^[Ll]ocation: *//')"
    if { [ "$STATUS" = "302" ] || [ "$STATUS" = "303" ]; } && printf '%s' "$loc" | grep -q "$2"; then
        ok "$1 (-> $loc)"
    else
        bad "$1" "expected redirect to *$2*, got HTTP $STATUS location='$loc'"
    fi
}
body_has() { # label needle
    if grep -qF -- "$2" "$BODY"; then ok "$1"; else bad "$1" "response body does not contain: $2"; fi
}
body_lacks() { # label needle
    if grep -qF -- "$2" "$BODY"; then bad "$1" "response body unexpectedly contains: $2"; else ok "$1"; fi
}
extract_token() {
    grep -o 'name="_token" value="[^"]*"' "$BODY" | head -1 | sed 's/.*value="//; s/"$//'
}
session_id() {
    awk '$6 == "PHPSESSID" { print $7 }' "$JAR" | tail -1
}

echo "Target: $BASE"
echo

echo "1. Anonymous access is redirected to the login page"
for p in /home /doctors /dashboard; do
    req GET "$p"
    expect_redirect "GET $p without login" "/login"
done

echo
echo "2. Login page and CSRF"
req GET /login
expect_status "GET /login" 200
LOGIN_TOKEN="$(extract_token)"
if [ -n "$LOGIN_TOKEN" ]; then ok "login form contains a _token field"; else bad "login form contains a _token field"; fi

req POST /login --data-urlencode "username=someone" --data-urlencode "password=whatever"
expect_status "POST /login WITHOUT token is rejected" 400
body_has "  ... and shows an error message" "session has expired"

req POST /login --data-urlencode "_token=not-a-real-token" --data-urlencode "username=someone" --data-urlencode "password=whatever"
expect_status "POST /login with a FORGED token is rejected" 400

req GET /login
LOGIN_TOKEN="$(extract_token)"
req POST /login --data-urlencode "_token=$LOGIN_TOKEN" --data-urlencode "username=" --data-urlencode "password="
expect_status "POST /login with empty fields" 200
body_has "  ... shows the 'required' message" "Username and password are required"

req GET /login
LOGIN_TOKEN="$(extract_token)"
req POST /login --data-urlencode "_token=$LOGIN_TOKEN" \
    --data-urlencode "username=hms_no_such_user_$$" --data-urlencode "password=definitely-wrong"
expect_status "POST /login with wrong credentials stays on login page" 200
body_has "  ... shows the failure message" "Invalid username or password"

echo
echo "3. Successful login, session fixation, protected pages"
if [ -z "$USER_NAME" ] || [ -z "$USER_PASS" ]; then
    skip "valid login (set HMS_USER and HMS_PASS to run sections 3 and 4)"
    SKIP=$((SKIP + 6))
else
    req GET /login
    LOGIN_TOKEN="$(extract_token)"
    SID_BEFORE="$(session_id)"
    req POST /login --data-urlencode "_token=$LOGIN_TOKEN" \
        --data-urlencode "username=$USER_NAME" --data-urlencode "password=$USER_PASS"
    expect_redirect "POST /login with valid credentials" "/home"
    SID_AFTER="$(session_id)"
    if [ -n "$SID_BEFORE" ] && [ -n "$SID_AFTER" ] && [ "$SID_BEFORE" != "$SID_AFTER" ]; then
        ok "session ID changed on login (fixation protection)"
    else
        bad "session ID changed on login (fixation protection)" "before and after IDs are identical or missing"
    fi

    req GET /home
    expect_status "GET /home when logged in" 200
    body_has "  ... shows the signed-in username" "$USER_NAME"
    if grep -qi '^cache-control:.*no-store' "$HDR"; then
        ok "  ... Cache-Control contains no-store"
    else
        bad "  ... Cache-Control contains no-store" "$(grep -i '^cache-control:' "$HDR" | tr -d '\r')"
    fi
    LOGOUT_TOKEN="$(extract_token)"
    if [ -n "$LOGOUT_TOKEN" ]; then ok "  ... dashboard contains the logout _token"; else bad "  ... dashboard contains the logout _token"; fi

    req GET /login
    expect_redirect "GET /login while logged in" "/home"

    echo
    echo "4. Logout"
    req GET /logout
    expect_status "GET /logout is not allowed (POST only)" 405

    req POST /logout --data-urlencode "_token=forged"
    if { [ "$STATUS" = "302" ] || [ "$STATUS" = "303" ]; }; then ok "POST /logout with forged token is refused (HTTP $STATUS)"; else bad "POST /logout with forged token is refused" "HTTP $STATUS"; fi
    req GET /home
    expect_status "  ... user is STILL logged in after the forged logout" 200
    LOGOUT_TOKEN="$(extract_token)"

    OLD_SID="$(session_id)"
    req POST /logout --data-urlencode "_token=$LOGOUT_TOKEN"
    expect_redirect "POST /logout with valid token" "/login"

    req GET /login
    expect_status "GET /login after logout" 200
    body_has "  ... shows the logged-out message" "You have been logged out"

    req GET /home
    expect_redirect "GET /home after logout" "/login"

    # Replay the old session cookie from a clean client: it must be dead.
    STATUS="$(curl -s -o "$BODY" -D "$HDR" -w '%{http_code}' -H "Cookie: PHPSESSID=$OLD_SID" "$BASE/home")"
    expect_redirect "old session cookie replayed after logout" "/login"
fi

echo
echo "-------------------------------------------"
printf 'Passed: %d   Failed: %d   Skipped: %d\n' "$PASS" "$FAIL" "$SKIP"
[ "$FAIL" -eq 0 ]