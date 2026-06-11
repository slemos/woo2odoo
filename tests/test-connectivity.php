<?php
/**
 * Smoke test: conectividad JSON-RPC con Odoo.
 * Corre desde PHP CLI sin WordPress.
 * Uso: php tests/test-connectivity.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        [$key, $val] = explode('=', $line, 2);
        putenv(trim($key) . '=' . trim($val));
    }
}

$url      = getenv('ODOO_URL');
$dbname   = getenv('ODOO_DBNAME');
$user     = getenv('ODOO_USER');
$password = getenv('ODOO_PASSWORD');

echo "URL:    $url\n";
echo "DB:     $dbname\n";
echo "User:   $user\n\n";

try {
    $client = new \winternet\odoo\JsonRpcClient($url, $dbname, $user, $password);
    echo "Cliente instanciado. Autenticando...\n";

    $uid = $client->uid;
    echo "UID autenticado: $uid\n\n";

    echo "Buscando res.company...\n";
    $companies = $client->searchRead('res.company', [
        'where'  => [],
        'fields' => ['id', 'name', 'country_id'],
        'offset' => 0,
        'limit'  => 10,
        'order'  => 'id',
    ]);
    echo "Compañías encontradas: " . count($companies) . "\n";
    foreach ($companies as $c) {
        $country = is_array($c->country_id) ? $c->country_id[1] : 'N/A';
        echo "  - [{$c->id}] {$c->name} ($country)\n";
    }
    echo "\n✅ Conectividad OK\n";
} catch (\Throwable $e) {
    echo "❌ Error: " . get_class($e) . ": " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
