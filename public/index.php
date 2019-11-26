<?php

use Doctrine\DBAL\Exception\ConnectionException;
use PackageVersions\Versions;
use Shopware\Core\Framework\Plugin\KernelPluginLoader\DbalKernelPluginLoader;
use Shopware\Core\Framework\Routing\RequestTransformerInterface;
use Shopware\Production\Kernel;
use Shopware\Storefront\Framework\Cache\CacheStore;
use Shopware\Storefront\Framework\Csrf\CsrfPlaceholderHandler;
use Symfony\Component\Debug\Debug;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpCache\HttpCache;

$classLoader = require __DIR__.'/../vendor/autoload.php';

// The check is to ensure we don't use .env if APP_ENV is defined
if (!isset($_SERVER['APP_ENV']) && !isset($_ENV['APP_ENV'])) {
    if (!class_exists(Dotenv::class)) {
        throw new \RuntimeException('APP_ENV environment variable is not defined. You need to define environment variables for configuration or add "symfony/dotenv" as a Composer dependency to load variables from a .env file.');
    }
    $envFile = __DIR__.'/../.env';
    if (file_exists($envFile)) {
        (new Dotenv(true))->load($envFile);
    }
}

$appEnv = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? 'dev';
$debug = (bool) ($_SERVER['APP_DEBUG'] ?? $_ENV['APP_DEBUG'] ?? ('prod' !== $appEnv));

if ($debug) {
    umask(0000);

    Debug::enable();
}

if ($trustedProxies = $_SERVER['TRUSTED_PROXIES'] ?? $_ENV['TRUSTED_PROXIES'] ?? false) {
    Request::setTrustedProxies(explode(',', $trustedProxies), Request::HEADER_X_FORWARDED_ALL ^ Request::HEADER_X_FORWARDED_HOST);
}

if ($trustedHosts = $_SERVER['TRUSTED_HOSTS'] ?? $_ENV['TRUSTED_HOSTS'] ?? false) {
    Request::setTrustedHosts(explode(',', $trustedHosts));
}

// resolve SEO urls
$request = Request::createFromGlobals();
$connection = Kernel::getConnection();

if ($appEnv === 'dev') {
    $connection->getConfiguration()->setSQLLogger(
        new \Shopware\Core\Profiling\Doctrine\DebugStack()
    );
}

try {
    $shopwareVersion = Versions::getVersion('shopware/core');

    $pluginLoader = new DbalKernelPluginLoader($classLoader, null, $connection);

    $kernel = new Kernel($appEnv, $debug, $pluginLoader, $_SERVER['SW_CACHE_ID'] ?? null, $shopwareVersion);
    $kernel->boot();

    // resolves seo urls and detects storefront sales channels
    $request = $kernel->getContainer()
        ->get(RequestTransformerInterface::class)
        ->transform($request);

    $csrfTokenHelper = $kernel->getContainer()->get(CsrfPlaceholderHandler::class);
    $enabled = $kernel->getContainer()->getParameter('shopware.http.cache.enabled');
    if ($enabled) {
        $store = $kernel->getContainer()->get(CacheStore::class);

        $kernel = new HttpCache($kernel, $store, null, ['debug' => $debug]);
    }

    $response = $kernel->handle($request);

    // replace csrf placeholder with fresh tokens
    $response = $csrfTokenHelper->replaceCsrfToken($response);
} catch (ConnectionException $e) {
    throw new RuntimeException($e->getMessage());
}

$response->send();
$kernel->terminate($request, $response);
