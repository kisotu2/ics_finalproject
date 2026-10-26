#!/usr/bin/env bash
set -euo pipefail

project_root="$(cd "$(dirname "$0")/.." && pwd)"
test_dir="$(mktemp -d)"
port="$(php -r '$server = stream_socket_server("tcp://127.0.0.1:0", $errno, $error); echo parse_url(stream_socket_get_name($server, false), PHP_URL_PORT); fclose($server);')"
test_token="$(php -r 'echo bin2hex(random_bytes(32));')"

cleanup() {
    if [[ -n "${server_pid:-}" ]]; then
        kill "$server_pid" 2>/dev/null || true
        wait "$server_pid" 2>/dev/null || true
    fi
    rm -rf "$test_dir"
}
trap cleanup EXIT

AD_SYNC_TOKEN="$test_token" AD_SYNC_ALLOW_INSECURE_LOCAL=1 \
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
    local actual
    local arguments=(--silent --show-error --output "$test_dir/response.json" --write-out '%{http_code}' -H 'Content-Type: application/json')

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

expect_status 401 'incorrect-test-token' '{"version":1,"computers":[]}'
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

printf 'All AD sync HTTP boundary tests passed.\n'
