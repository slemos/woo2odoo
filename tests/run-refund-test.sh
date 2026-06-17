#!/usr/bin/env bash
# run-refund-test.sh — Orquestador del test de refund (Fase 8)
#
# Uso:
#   bash tests/run-refund-test.sh [--customer-id N]
#
# Qué hace:
#   1. Ejecuta test-refund-flow.php en infra-php-1 (crea órdenes, confirma facturas, crea refunds)
#   2. Pausa 5s para que Odoo procese
#   3. Ejecuta verify-refund-result.php para cada orden
#   4. Imprime resumen final con los IDs de Odoo

set -euo pipefail
PLUGIN_DIR="/var/www/html/wp-content/plugins/woo2odoo"
CUSTOMER_ID="${2:-0}"

for arg in "$@"; do
    case $arg in
        --customer-id) CUSTOMER_ID="${2:-0}" ;;
    esac
done

CYAN='\033[0;36m'; GREEN='\033[0;32m'; RED='\033[0;31m'; NC='\033[0m'
info()    { echo -e "${CYAN}[run-refund-test]${NC} $*"; }
success() { echo -e "${GREEN}[run-refund-test]${NC} $*"; }
error()   { echo -e "${RED}[run-refund-test]${NC} $*" >&2; }

info "═══ Iniciando test de refund (Fase 8) ═══"
info "Customer ID: $CUSTOMER_ID"

# ── Paso 1: crear órdenes + refunds ─────────────────────────────────────────
info "Ejecutando test-refund-flow.php en infra-php-1..."
RAW=$(docker exec \
    -e PHPUNIT_TESTING=0 \
    -w "$PLUGIN_DIR" \
    infra-php-1 \
    php -d memory_limit=512M tests/test-refund-flow.php --customer-id "$CUSTOMER_ID" \
    2>&1)

echo "$RAW"

# Extraer IDs del output
FULL_ORDER_ID=$(echo "$RAW"    | grep '^FULL_ORDER_ID='    | cut -d= -f2 | tr -d '[:space:]')
FULL_REFUND_ID=$(echo "$RAW"   | grep '^FULL_REFUND_ID='   | cut -d= -f2 | tr -d '[:space:]')
PARTIAL_ORDER_ID=$(echo "$RAW" | grep '^PARTIAL_ORDER_ID=' | cut -d= -f2 | tr -d '[:space:]')
PARTIAL_REFUND_ID=$(echo "$RAW"| grep '^PARTIAL_REFUND_ID='| cut -d= -f2 | tr -d '[:space:]')

if [[ -z "$FULL_ORDER_ID" || -z "$PARTIAL_ORDER_ID" ]]; then
    error "No se pudieron extraer los IDs de las órdenes. Revisa el output de arriba."
    exit 1
fi

info "IDs obtenidos:"
info "  Refund total:   WC#$FULL_ORDER_ID  → Refund#$FULL_REFUND_ID"
info "  Refund parcial: WC#$PARTIAL_ORDER_ID → Refund#$PARTIAL_REFUND_ID"

# ── Paso 2: pausa breve ───────────────────────────────────────────────────────
info "Esperando 3s para que Odoo indexe los cambios..."
sleep 3

# ── Paso 3: verify refund total ───────────────────────────────────────────────
info "═══ Verificando refund total (WC#$FULL_ORDER_ID) ═══"
docker exec \
    -e PHPUNIT_TESTING=1 \
    -w "$PLUGIN_DIR" \
    infra-php-1 \
    php -d memory_limit=256M tests/verify-refund-result.php \
        "$FULL_ORDER_ID" "$FULL_REFUND_ID" \
    && success "✓ Refund total: OK" \
    || error   "✗ Refund total: FALLÓ (ver output)"

# ── Paso 4: verify refund parcial ────────────────────────────────────────────
info "═══ Verificando refund parcial (WC#$PARTIAL_ORDER_ID) ═══"
docker exec \
    -e PHPUNIT_TESTING=1 \
    -w "$PLUGIN_DIR" \
    infra-php-1 \
    php -d memory_limit=256M tests/verify-refund-result.php \
        "$PARTIAL_ORDER_ID" "$PARTIAL_REFUND_ID" \
    && success "✓ Refund parcial: OK" \
    || error   "✗ Refund parcial: FALLÓ (ver output)"

# ── Resumen final ─────────────────────────────────────────────────────────────
info ""
info "═══ RESUMEN FINAL ═══"
info "Refund total:   WC#$FULL_ORDER_ID   Refund WC#$FULL_REFUND_ID"
info "Refund parcial: WC#$PARTIAL_ORDER_ID Refund WC#$PARTIAL_REFUND_ID"
info "JSONs en: infra-php-1:$PLUGIN_DIR/tests/results/"
