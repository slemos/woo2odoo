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

PLUGIN="${1:-woo2odoo}"
RESULTS_DIR="$(dirname "$0")/results"
mkdir -p "$RESULTS_DIR"

# Wrapper para correr PHP dentro del contenedor y capturar ORDER_ID
run_create_order() {
    local LABEL="$1"
    local EXTRA_ARGS="$2"
    echo "==> Creando orden variante: $LABEL"
    OUTPUT=$(ssh arm "docker exec -e PHPUNIT_TESTING=0 \
        -w /var/www/html/wp-content/plugins/woo2odoo \
        infra-php-1 php -d memory_limit=512M \
        tests/create-test-order.php --label $LABEL $EXTRA_ARGS" 2>&1)
    echo "$OUTPUT"
    ORDER_ID=$(echo "$OUTPUT" | grep '^ORDER_ID=' | tail -1 | cut -d= -f2 | tr -d '[:space:]')
    echo "ORDER_ID extraído: $ORDER_ID"
}

run_verify() {
    local ORDER_ID="$1"
    local OUT_LABEL="$2"
    echo "==> Verificando en Odoo (Order #$ORDER_ID)..."
    ssh arm "docker exec -e PHPUNIT_TESTING=1 \
        -w /var/www/html/wp-content/plugins/woo2odoo \
        infra-php-1 php -d memory_limit=256M \
        tests/verify-odoo-result.php $ORDER_ID $PLUGIN $OUT_LABEL" 2>&1
    # Copiar result JSON a local
    ssh arm "cat /srv/pinkmask/wp-content/plugins/woo2odoo/tests/results/${OUT_LABEL}-result.json" \
        > "$RESULTS_DIR/${OUT_LABEL}-result.json" 2>/dev/null || true
    echo "==> Resultado guardado: $RESULTS_DIR/${OUT_LABEL}-result.json"
}

SYNC_WAIT=20  # segundos para esperar sync a Odoo

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

# ---- VARIANTE 2: Anónimo, con PINK10 ----
LABEL="${PLUGIN}-anon-pink10"
run_create_order "$LABEL" "--coupon PINK10"
ORDER2="$ORDER_ID"
echo "Esperando ${SYNC_WAIT}s para sync Odoo..."
sleep $SYNC_WAIT
run_verify "$ORDER2" "$LABEL"

# ---- VARIANTE 3: Autenticado (ID=10), sin cupón ----
LABEL="${PLUGIN}-auth"
run_create_order "$LABEL" "--customer-id 10"
ORDER3="$ORDER_ID"
echo "Esperando ${SYNC_WAIT}s para sync Odoo..."
sleep $SYNC_WAIT
run_verify "$ORDER3" "$LABEL"

# ---- VARIANTE 4: Autenticado (ID=10), con PINK10 ----
LABEL="${PLUGIN}-auth-pink10"
run_create_order "$LABEL" "--customer-id 10 --coupon PINK10"
ORDER4="$ORDER_ID"
echo "Esperando ${SYNC_WAIT}s para sync Odoo..."
sleep $SYNC_WAIT
run_verify "$ORDER4" "$LABEL"

echo ""
echo "=========================================="
echo "  Completado. Resultados en: $RESULTS_DIR"
echo "  Orders: $ORDER1, $ORDER2, $ORDER3, $ORDER4"
echo "=========================================="
