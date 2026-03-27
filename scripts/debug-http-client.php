<?php
define('STDIN', fopen('php://stdin', 'r'));
$_SERVER['APP_ENV'] = 'dev';
$_SERVER['APP_DEBUG'] = '1';
require '/var/www/app/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv('/var/www/app/.env');
$kernel = new App\Kernel('dev', false);
$kernel->boot();
$c = $kernel->getContainer();

// Find all http client related services
$ids = $c->getServiceIds();
foreach ($ids as $id) {
    if (stripos($id, 'http_client') !== false || stripos($id, 'HttpClient') !== false) {
        echo $id . PHP_EOL;
    }
}

// Check what gets injected into PgpKeyService
$svc = $c->get('App\Service\PgpKeyService');
$r = new ReflectionClass($svc);
$p = $r->getProperty('httpClient');
$p->setAccessible(true);
$client = $p->getValue($svc);
echo "Injected class: " . get_class($client) . PHP_EOL;

// Try making a real request to see what URL gets built
try {
    $resp = $client->request('GET', 'https://keys.openpgp.org/pks/lookup', [
        'query' => ['op' => 'get', 'search' => 'test@test.com'],
        'timeout' => 3,
    ]);
    echo "Request URL OK, status: " . $resp->getStatusCode() . PHP_EOL;
} catch (Throwable $e) {
    echo "Request error: " . $e->getMessage() . PHP_EOL;
}
