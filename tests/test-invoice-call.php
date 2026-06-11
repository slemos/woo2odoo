<?php
/**
 * Test directo de llamada _create_invoices via JSON-RPC a Odoo.
 * Sin dependencia de WordPress — usa .env del plugin.
 * Uso: php tests/test-invoice-call.php [so_id]
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

$url  = getenv('ODOO_URL');
$db   = getenv('ODOO_DBNAME');
$user = getenv('ODOO_USER');
$pass = getenv('ODOO_PASSWORD');

echo "Conectando a Odoo: $url / $db" . PHP_EOL;

$client = new \winternet\odoo\JsonRpcClient($url, $db, $user, $pass);
$client->isDebug = true;

$so_id = isset($argv[1]) ? (int) $argv[1] : 2403;

echo "=== Test: _create_invoices en sale.order id=$so_id ===" . PHP_EOL;
try {
    $result = $client->execute('sale.order', '_create_invoices', [[$so_id]]);
    echo "Resultado: " . json_encode($result) . PHP_EOL;
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . PHP_EOL;
}

echo PHP_EOL . "=== Log del cliente ===" . PHP_EOL;
$logFile = __DIR__ . '/../winternetOdooPhpJsonRpcClient.log';
if (file_exists($logFile)) {
    $lines = file($logFile);
    $recent = array_slice($lines, -20);
    echo implode('', $recent);
}
