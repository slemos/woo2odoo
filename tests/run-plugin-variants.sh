#!/bin/bash
# run-plugin-variants.sh — Corre las 4 variantes de test para el plugin activo en ARM
#
# Uso: ./tests/run-plugin-variants.sh <plugin_name>
# Ejemplo: ./tests/run-plugin-variants.sh woo2odoo
#
# Variantes:
#   anon           → cliente anónimo, sin cupón
#   anon-pink10    → cliente anónimo, cupón PINK10
#   auth           → cliente autenticado (ID=10, slemos.satue@gmail.com), sin cupón
#   auth-pink10    → cliente autenticado, cupón PINK10
#
# Requiere: acceso SSH al servidor ARM (alias "arm" en ~/.ssh/config)

set -e

# Cargar .env.arm si existe (un nivel arriba de tests/)
ENV_FILE="$(dirname "$0")/../.env.arm"
if [ -f "$ENV_FILE" ]; then
    set -a; source "$ENV_FILE"; set +a
fi

# Defaults (por si .env.arm no está presente)
ARM_SSH_ALIAS="${ARM_SSH_ALIAS:-arm}"
ARM_PHP_CONTAINER="${ARM_PHP_CONTAINER:-infra-php-1}"
ARM_WP_PATH="${ARM_WP_PATH:-/var/www/html}"
ARM_HOST_PLUGIN_PATH="${ARM_HOST_PLUGIN_PATH:-/srv/pinkmask/wp-content/plugins/woo2odoo}"
TEST_CUSTOMER_ID="${TEST_CUSTOMER_ID:-10}"
TEST_COUPON="${TEST_COUPON:-PINK10}"
SYNC_WAIT="${SYNC_WAIT_SECONDS:-20}"

PLUGIN="${1:-woo2odoo}"
RESULTS_DIR="$(dirname "$0")/results"
mkdir -p "$RESULTS_DIR"

# Wrapper para correr PHP dentro del contenedor y capturar ORDER_ID
run_create_order() {
    local LABEL="$1"
    local EXTRA_ARGS="$2"
    echo "==> Creando orden variante: $LABEL"
    OUTPUT=$(ssh "$ARM_SSH_ALIAS" "docker exec -e PHPUNIT_TESTING=0 \
        -w ${ARM_WP_PATH}/wp-content/plugins/woo2odoo \
        ${ARM_PHP_CONTAINER} php -d memory_limit=512M \
        tests/create-test-order.php --label $LABEL $EXTRA_ARGS" 2>&1)
    echo "$OUTPUT"
    ORDER_ID=$(echo "$OUTPUT" | grep '^ORDER_ID=' | tail -1 | cut -d= -f2 | tr -d '[:space:]')
    echo "ORDER_ID extraído: $ORDER_ID"
}

run_verify() {
    local ORDER_ID="$1"
    local OUT_LABEL="$2"
    echo "==> Verificando en Odoo (Order #$ORDER_ID)..."
    ssh "$ARM_SSH_ALIAS" "docker exec -e PHPUNIT_TESTING=1 \
        -w ${ARM_WP_PATH}/wp-content/plugins/woo2odoo \
        ${ARM_PHP_CONTAINER} php -d memory_limit=256M \
        tests/verify-odoo-result.php $ORDER_ID $PLUGIN $OUT_LABEL" 2>&1
    # Copiar result JSON a local
    ssh "$ARM_SSH_ALIAS" "cat ${ARM_HOST_PLUGIN_PATH}/tests/results/${OUT_LABEL}-result.json" \
        > "$RESULTS_DIR/${OUT_LABEL}-result.json" 2>/dev/null || true
    echo "==> Resultado guardado: $RESULTS_DIR/${OUT_LABEL}-result.json"
}

echo "=========================================="
echo "  Test de variantes para plugin: $PLUGIN"
echo "  $(date -u)"
echo "=========================================="

# ---- VARIANTE 1: Anónimo, sin cupón ----
LABEL="${PLUGIN}-anon"
run_create_order "$LABEL" ""
ORDER1="$ORDER_ID"
echo "Esperando ${SYNC_WAIT}s para sync Odoo..."
sleep $SYNC_WAIT
run_verify "$ORDER1" "$LABEL"

# ---- VARIANTE 2: Anónimo, con cupón ----
LABEL="${PLUGIN}-anon-pink10"
run_create_order "$LABEL" "--coupon $TEST_COUPON"
ORDER2="$ORDER_ID"
echo "Esperando ${SYNC_WAIT}s para sync Odoo..."
sleep $SYNC_WAIT
run_verify "$ORDER2" "$LABEL"

# ---- VARIANTE 3: Autenticado, sin cupón ----
LABEL="${PLUGIN}-auth"
run_create_order "$LABEL" "--customer-id $TEST_CUSTOMER_ID"
ORDER3="$ORDER_ID"
echo "Esperando ${SYNC_WAIT}s para sync Odoo..."
sleep $SYNC_WAIT
run_verify "$ORDER3" "$LABEL"

# ---- VARIANTE 4: Autenticado, con cupón ----
LABEL="${PLUGIN}-auth-pink10"
run_create_order "$LABEL" "--customer-id $TEST_CUSTOMER_ID --coupon $TEST_COUPON"
ORDER4="$ORDER_ID"
echo "Esperando ${SYNC_WAIT}s para sync Odoo..."
sleep $SYNC_WAIT
run_verify "$ORDER4" "$LABEL"

echo ""
echo "=========================================="
echo "  Completado. Resultados en: $RESULTS_DIR"
echo "  Orders: $ORDER1, $ORDER2, $ORDER3, $ORDER4"
echo "=========================================="
