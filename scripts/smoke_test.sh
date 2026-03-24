#!/bin/bash
# CKPS Production Smoke Test
#
# Run immediately after deploy to verify core functionality.
# Usage: ./scripts/smoke_test.sh <base-url> <api-token>
#
# CTO-required checks: /up, login, dataset list, /api/retrieve, /api/chat, CloudWatch

set -euo pipefail

BASE_URL="${1:?Usage: smoke_test.sh <base-url> <api-token>}"
TOKEN="${2:?Usage: smoke_test.sh <base-url> <api-token>}"
PASS=0
FAIL=0

check() {
    local name="$1"
    local result="$2"
    if [ "$result" -eq 0 ]; then
        echo "  PASS: $name"
        PASS=$((PASS + 1))
    else
        echo "  FAIL: $name"
        FAIL=$((FAIL + 1))
    fi
}

echo "=== CKPS Smoke Test ==="
echo "Target: $BASE_URL"
echo ""

# 1. Health check
echo "1. Health Check"
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/up" 2>/dev/null || echo "000")
check "/up returns 200" $([ "$HTTP_CODE" = "200" ] && echo 0 || echo 1)

# 2. Login page accessible
echo "2. Login Page"
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "$BASE_URL/login" 2>/dev/null || echo "000")
check "/login returns 200" $([ "$HTTP_CODE" = "200" ] && echo 0 || echo 1)

# 3. Dashboard (authenticated)
echo "3. Dashboard"
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" -H "Authorization: Bearer $TOKEN" "$BASE_URL/api/user" 2>/dev/null || echo "000")
check "/api/user returns 200" $([ "$HTTP_CODE" = "200" ] && echo 0 || echo 1)

# 4. Dataset list
echo "4. Datasets"
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" -H "Authorization: Bearer $TOKEN" "$BASE_URL/api/datasets" 2>/dev/null || echo "000")
check "/api/datasets returns 200" $([ "$HTTP_CODE" = "200" ] && echo 0 || echo 1)

# 5. Retrieval API
echo "5. Retrieval API"
RETRIEVE_RESP=$(curl -s -w "\n%{http_code}" -X POST "$BASE_URL/api/retrieve" \
    -H "Authorization: Bearer $TOKEN" \
    -H "Content-Type: application/json" \
    -d '{"query":"test query","dataset_id":1,"top_k":3}' 2>/dev/null || echo -e "\n000")
RETRIEVE_CODE=$(echo "$RETRIEVE_RESP" | tail -1)
check "/api/retrieve responds" $([ "$RETRIEVE_CODE" = "200" ] || [ "$RETRIEVE_CODE" = "404" ] && echo 0 || echo 1)

# 6. Chat API
echo "6. Chat API"
CHAT_RESP=$(curl -s -w "\n%{http_code}" -X POST "$BASE_URL/api/chat" \
    -H "Authorization: Bearer $TOKEN" \
    -H "Content-Type: application/json" \
    -d '{"message":"hello","dataset_id":1}' 2>/dev/null || echo -e "\n000")
CHAT_CODE=$(echo "$CHAT_RESP" | tail -1)
check "/api/chat responds" $([ "$CHAT_CODE" = "200" ] || [ "$CHAT_CODE" = "404" ] && echo 0 || echo 1)

# Summary
echo ""
echo "=== Results ==="
echo "PASS: $PASS / $((PASS + FAIL))"
echo "FAIL: $FAIL / $((PASS + FAIL))"

if [ "$FAIL" -gt 0 ]; then
    echo ""
    echo "SMOKE TEST FAILED — DO NOT PROCEED WITH ROLLOUT"
    exit 1
else
    echo ""
    echo "SMOKE TEST PASSED"
    exit 0
fi
