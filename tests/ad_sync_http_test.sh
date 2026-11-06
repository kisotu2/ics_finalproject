#!/usr/bin/env bash
set -euo pipefail

project_root="$(cd "$(dirname "$0")/.." && pwd)"
test_dir="$(mktemp -d)"
port="$(php -r '$server = stream_socket_server("tcp://127.0.0.1:0", $errno, $error); echo parse_url(stream_socket_get_name($server, false), PHP_URL_PORT); fclose($server);')"
test_token="$(php -r 'echo bin2hex(random_bytes(32));')"
mkdir -p "$test_dir/rate-limit"

cleanup() {
    if [[ -n "${server_pid:-}" ]]; then
        kill "$server_pid" 2>/dev/null || true
        wait "$server_pid" 2>/dev/null || true
    fi
    rm -rf "$test_dir"
}
trap cleanup EXIT

AD_SYNC_TOKEN="$test_token" AD_SYNC_ALLOW_INSECURE_LOCAL=1 AD_SYNC_RATE_LIMIT_DIR="$test_dir/rate-limit" \
    php -S "127.0.0.1:$port" -t "$project_root" >"$test_dir/server.log" 2>&1 &
server_pid=$!

endpoint="http://127.0.0.1:$port/ad_sync_api.php"
for attempt in $(seq 1 30); do
    if curl --silent --output /dev/null "$endpoint"; then
        break
    fi
    sleep 0.1
done

expect_status() {
    local expected="$1"
    local authorization="$2"
    local body="$3"
    local method="${4:-POST}"
    local actual
    local arguments=(--silent --show-error --output "$test_dir/response.json" --write-out '%{http_code}' -H 'Content-Type: application/json' -X "$method")

    if [[ -n "$authorization" ]]; then
        arguments+=(-H "Authorization: Bearer $authorization")
    fi
    if [[ "$body" != '__NO_BODY__' ]]; then
        arguments+=(--data-binary "$body")
    fi

    actual="$(curl "${arguments[@]}" "$endpoint")"
    if [[ "$actual" != "$expected" ]]; then
        printf 'FAIL expected HTTP %s, got %s: ' "$expected" "$actual" >&2
        cat "$test_dir/response.json" >&2
        exit 1
    fi
    printf 'PASS HTTP %s\n' "$actual"
}

expect_status 405 '' '__NO_BODY__' GET
expect_status 401 'incorrect-test-token' '{"version":1,"computers":[]}'
malformed_auth_status="$(curl --silent --output "$test_dir/auth.json" --write-out '%{http_code}' \
    -H 'Content-Type: application/json' -H "Authorization: Bearer $test_token extra" \
    --data-binary '{}' "$endpoint")"
if [[ "$malformed_auth_status" != 401 ]]; then
    printf 'FAIL expected HTTP 401 for malformed authorization header, got %s\n' "$malformed_auth_status" >&2
    exit 1
fi
printf 'PASS HTTP 401 for malformed authorization header\n'
expect_status 401 '' '__NO_BODY__'
expect_status 400 "$test_token" '{"version":'
expect_status 422 "$test_token" '{"version":1,"computers":[{}]}'

future_date="$(php -r 'echo (new DateTimeImmutable("tomorrow"))->format("Y-m-d");')"
future_purchase_payload="$(php -r '
$record = [
    "ad_guid" => "a6f33f8b-f62d-4acb-96be-8e1ef6ab0c21",
    "computer_name" => "WS-TEST-01",
    "distinguished_name" => "CN=WS-TEST-01,OU=Test,DC=example,DC=test",
    "enabled" => true,
    "operating_system" => "Windows 11",
    "operating_system_version" => "10.0",
    "manufacturer" => "Test manufacturer",
    "model" => "Test model",
    "manufacturer_serial" => null,
    "processor" => "Test processor",
    "ram_gb" => 16,
    "storage_gb" => 512,
    "purchase_date" => $argv[1],
    "warranty_expiry" => null,
];
echo json_encode(["version" => 1, "computers" => [$record]]);
' "$future_date")"
expect_status 422 "$test_token" "$future_purchase_payload"

invalid_guid_payload='{"version":1,"computers":[{"ad_guid":"bad","computer_name":"WS-BAD-01"}]}'
expect_status 422 "$test_token" "$invalid_guid_payload"
content_status="$(curl --silent --output "$test_dir/content.json" --write-out '%{http_code}' \
    -H 'Content-Type: text/plain' -H "Authorization: Bearer $test_token" \
    --data-binary '{}' "$endpoint")"
if [[ "$content_status" != 415 ]]; then
    printf 'FAIL expected HTTP 415 for non-JSON content, got %s\n' "$content_status" >&2
    exit 1
fi
printf 'PASS HTTP 415 for unsupported content type\n'

# Authenticated callers are throttled before JSON parsing; malformed-but-authenticated requests count.
rate_status=''
for attempt in $(seq 1 40); do
    rate_status="$(curl --silent --output "$test_dir/rate.json" --write-out '%{http_code}' \
        -H 'Content-Type: application/json' -H "Authorization: Bearer $test_token" \
        --data-binary '{' "$endpoint")"
    if [[ "$rate_status" == 429 ]]; then break; fi
done
if [[ "$rate_status" != 429 ]]; then
    printf 'FAIL expected HTTP 429 after authenticated request burst, got %s\n' "$rate_status" >&2
    exit 1
fi
printf 'PASS HTTP 429 request throttling\n'

# An unset server token is a configuration error; it must not enable a weaker auth path.
missing_token_port="$(php -r '$s = stream_socket_server("tcp://127.0.0.1:0", $errno, $error); echo parse_url(stream_socket_get_name($s, false), PHP_URL_PORT); fclose($s);')"
env -u AD_SYNC_TOKEN AD_SYNC_ALLOW_INSECURE_LOCAL=1 AD_SYNC_RATE_LIMIT_DIR="$test_dir/missing-rate" \
    php -S "127.0.0.1:$missing_token_port" -t "$project_root" >"$test_dir/missing-token.log" 2>&1 &
missing_token_pid=$!
for attempt in $(seq 1 30); do
    if curl --silent --output /dev/null "http://127.0.0.1:$missing_token_port/ad_sync_api.php"; then break; fi
    sleep 0.1
done
missing_token_status="$(curl --silent --output "$test_dir/missing-token.json" --write-out '%{http_code}' \
    -H 'Content-Type: application/json' --data-binary '{}' "http://127.0.0.1:$missing_token_port/ad_sync_api.php")"
kill "$missing_token_pid" 2>/dev/null || true
wait "$missing_token_pid" 2>/dev/null || true
if [[ "$missing_token_status" != 503 ]]; then
    printf 'FAIL expected HTTP 503 for missing server token, got %s\n' "$missing_token_status" >&2
    exit 1
fi
printf 'PASS HTTP 503 when server token is missing\n'

# A forwarded scheme header from an untrusted peer must not enable HTTP.
strict_port="$(php -r '$s = stream_socket_server("tcp://127.0.0.1:0", $errno, $error); echo parse_url(stream_socket_get_name($s, false), PHP_URL_PORT); fclose($s);')"
AD_SYNC_TOKEN="$test_token" AD_SYNC_RATE_LIMIT_DIR="$test_dir/strict-rate" \
    php -S "127.0.0.1:$strict_port" -t "$project_root" >"$test_dir/strict-server.log" 2>&1 &
strict_pid=$!
for attempt in $(seq 1 30); do
    if curl --silent --output /dev/null "http://127.0.0.1:$strict_port/ad_sync_api.php"; then break; fi
    sleep 0.1
done
strict_status="$(curl --silent --output "$test_dir/strict.json" --write-out '%{http_code}' \
    -H 'Content-Type: application/json' -H "Authorization: Bearer $test_token" \
    -H 'X-Forwarded-Proto: https' --data-binary '{"version":1,"computers":[]}' \
    "http://127.0.0.1:$strict_port/ad_sync_api.php")"
kill "$strict_pid" 2>/dev/null || true
wait "$strict_pid" 2>/dev/null || true
if [[ "$strict_status" != 400 ]]; then
    printf 'FAIL expected insecure HTTP to return 400 despite forwarded header, got %s\n' "$strict_status" >&2
    cat "$test_dir/strict.json" >&2
    exit 1
fi
printf 'PASS HTTP 400 for untrusted forwarded HTTPS header\n'

# A configured immediate proxy may assert HTTPS for its own forwarded request.
trusted_port="$(php -r '$s = stream_socket_server("tcp://127.0.0.1:0", $errno, $error); echo parse_url(stream_socket_get_name($s, false), PHP_URL_PORT); fclose($s);')"
AD_SYNC_TOKEN="$test_token" AD_SYNC_TRUSTED_PROXY_IPS=127.0.0.1 AD_SYNC_RATE_LIMIT_DIR="$test_dir/trusted-rate" \
    php -S "127.0.0.1:$trusted_port" -t "$project_root" >"$test_dir/trusted-server.log" 2>&1 &
trusted_pid=$!
for attempt in $(seq 1 30); do
    if curl --silent --output /dev/null "http://127.0.0.1:$trusted_port/ad_sync_api.php"; then break; fi
    sleep 0.1
done
trusted_status="$(curl --silent --output "$test_dir/trusted.json" --write-out '%{http_code}' \
    -H 'Content-Type: application/json' -H "Authorization: Bearer $test_token" \
    -H 'X-Forwarded-Proto: https' --data-binary '{"version":1,"computers":[]}' \
    "http://127.0.0.1:$trusted_port/ad_sync_api.php")"
kill "$trusted_pid" 2>/dev/null || true
wait "$trusted_pid" 2>/dev/null || true
if [[ "$trusted_status" != 422 ]]; then
    printf 'FAIL expected trusted proxy HTTPS request to pass transport check and return 422, got %s\n' "$trusted_status" >&2
    cat "$test_dir/trusted.json" >&2
    exit 1
fi
printf 'PASS trusted proxy HTTPS detection\n'


short_token_port="$(php -r '$s = stream_socket_server("tcp://127.0.0.1:0", $errno, $error); echo parse_url(stream_socket_get_name($s, false), PHP_URL_PORT); fclose($s);')"
AD_SYNC_TOKEN=short AD_SYNC_ALLOW_INSECURE_LOCAL=1 AD_SYNC_RATE_LIMIT_DIR="$test_dir/short-rate" \
    php -S "127.0.0.1:$short_token_port" -t "$project_root" >"$test_dir/short-token.log" 2>&1 &
short_token_pid=$!
for attempt in $(seq 1 30); do
    if curl --silent --output /dev/null "http://127.0.0.1:$short_token_port/ad_sync_api.php"; then break; fi
    sleep 0.1
done
short_token_status="$(curl --silent --output "$test_dir/short-token.json" --write-out '%{http_code}' \
    -H 'Content-Type: application/json' --data-binary '{}' "http://127.0.0.1:$short_token_port/ad_sync_api.php")"
kill "$short_token_pid" 2>/dev/null || true
wait "$short_token_pid" 2>/dev/null || true
if [[ "$short_token_status" != 503 ]]; then
    printf 'FAIL expected HTTP 503 for short server token, got %s\n' "$short_token_status" >&2
    exit 1
fi
printf 'PASS HTTP 503 when server token is too short\n'

printf 'All AD sync HTTP boundary tests passed.\n'
