#!/usr/bin/env python3
"""
swap-plugin.py — Activa/desactiva plugins de Odoo en WP DB del ARM staging
Uso: python3 tests/swap-plugin.py <activate_plugin> [deactivate_plugin]

Ejemplo:
  python3 tests/swap-plugin.py wc2odoo woo2odoo    # activa wc2odoo, desactiva woo2odoo
  python3 tests/swap-plugin.py woo2odoo wc2odoo    # activa woo2odoo, desactiva wc2odoo

El script se corre en el BASTION y conecta a MariaDB via docker exec sobre SSH.
Requiere: alias SSH "arm" configurado.
"""
import subprocess
import sys
import re
import time
import os
from pathlib import Path


def _load_env_file(path: str) -> None:
    """Carga key=value desde un archivo .env sin dependencias externas."""
    p = Path(path)
    if not p.exists():
        return
    with p.open() as f:
        for line in f:
            line = line.strip()
            if line and not line.startswith('#') and '=' in line:
                key, _, val = line.partition('=')
                val = val.strip().strip('"').strip("'")
                os.environ.setdefault(key.strip(), val)


# Cargar .env.arm desde la raíz del repo (un nivel arriba de tests/)
_load_env_file(str(Path(__file__).parent.parent / '.env.arm'))

SSH_HOST          = os.getenv('ARM_SSH_ALIAS', 'arm')
PHP_CONTAINER     = os.getenv('ARM_PHP_CONTAINER', 'infra-php-1')
MARIADB_CONTAINER = os.getenv('ARM_MARIADB_CONTAINER', 'infra-mariadb-1')
REDIS_CONTAINER   = os.getenv('ARM_REDIS_CONTAINER', 'infra-redis-1')
DB_USER           = os.getenv('ARM_DB_USER', 'pinkmask')
DB_PASS           = os.getenv('ARM_DB_PASS', '')
DB_NAME           = os.getenv('ARM_DB_NAME', 'pinkmask_wp')
REDIS_PASS        = os.getenv('ARM_REDIS_PASS', '')

PLUGIN_PATHS = {
    'wc2odoo':  'wc2odoo/wc2odoo.php',
    'woo2odoo': 'woo2odoo/woo2odoo.php',
}

def run_sql_remote(sql):
    import base64
    encoded = base64.b64encode(sql.encode()).decode()
    cmd = (
        f"echo {encoded} | base64 -d | "
        f"docker exec -i {MARIADB_CONTAINER} mysql -u{DB_USER} -p{DB_PASS} {DB_NAME} -s"
    )
    r = subprocess.run(['ssh', SSH_HOST, cmd], capture_output=True, text=True)
    if r.returncode != 0:
        raise RuntimeError(f"SQL error: {r.stderr}")
    return r.stdout.strip()

def escape_sql(s):
    return s  # no longer needed — using base64 pipe

def get_plugins():
    raw = run_sql_remote('SELECT option_value FROM wp_options WHERE option_name="active_plugins";')
    return raw

def parse_php_array(serialized):
    """Extrae plugin paths de PHP serialized array."""
    return re.findall(r's:\d+:"([^"]+\.php)"', serialized)

def build_php_array(plugins):
    """Construye PHP serialized array desde lista de paths."""
    parts = []
    for i, p in enumerate(plugins):
        parts.append(f'i:{i};s:{len(p)}:"{p}"')
    return 'a:' + str(len(plugins)) + ':{' + ';'.join(parts) + ';}'

def flush_redis():
    subprocess.run(
        ['ssh', SSH_HOST, f'docker exec {REDIS_CONTAINER} redis-cli -a {REDIS_PASS} FLUSHALL'],
        capture_output=True,
    )

def restart_php():
    subprocess.run(['ssh', SSH_HOST, f'docker restart {PHP_CONTAINER}'], capture_output=True)
    time.sleep(5)

def swap(activate, deactivate=None):
    activate_path = PLUGIN_PATHS.get(activate)
    deactivate_path = PLUGIN_PATHS.get(deactivate) if deactivate else None

    if not activate_path:
        raise ValueError(f"Plugin desconocido: {activate}")

    raw = get_plugins()
    plugins = parse_php_array(raw)
    print(f"Plugins activos actuales ({len(plugins)}):")
    for p in plugins:
        if 'odoo' in p.lower() or 'wc2odoo' in p.lower():
            print(f"  [ODOO] {p}")

    # Quitar el plugin a desactivar
    if deactivate_path and deactivate_path in plugins:
        plugins.remove(deactivate_path)
        print(f"✗ Desactivado: {deactivate_path}")

    # Agregar el plugin a activar (si no está ya)
    if activate_path not in plugins:
        plugins.append(activate_path)
        print(f"✓ Activado: {activate_path}")
    else:
        print(f"= Ya activo: {activate_path}")

    # Re-indexar y serializar
    new_serialized = build_php_array(plugins)

    # Actualizar DB
    run_sql_remote(f"UPDATE wp_options SET option_value='{new_serialized}' WHERE option_name='active_plugins';")
    print("DB actualizada.")

    # Flush Redis + restart PHP
    flush_redis()
    print("Redis flushed.")
    restart_php()
    print("PHP-FPM reiniciado.")

    # Verificar
    raw2 = get_plugins()
    print(f"\nVerificación:")
    print(f"  woo2odoo activo: {'woo2odoo/woo2odoo.php' in raw2}")
    print(f"  wc2odoo activo:  {'wc2odoo/wc2odoo.php' in raw2}")

if __name__ == '__main__':
    if len(sys.argv) < 2:
        print(__doc__)
        sys.exit(1)
    activate = sys.argv[1]
    deactivate = sys.argv[2] if len(sys.argv) > 2 else None
    swap(activate, deactivate)
