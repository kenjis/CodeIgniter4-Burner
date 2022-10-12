<?php

$pathsConfig = '';
if (file_exists(__DIR__ . DIRECTORY_SEPARATOR . '../../../../autoload.php')) {
    require_once realpath(__DIR__ . DIRECTORY_SEPARATOR . '../../../../autoload.php');
    if (! defined('FCPATH')) {
        define('FCPATH', __DIR__ . DIRECTORY_SEPARATOR);
    }
    chdir(FCPATH);
    $pathsConfig = realpath(FCPATH . '../../../../../app/Config/Paths.php');
} elseif (file_exists(__DIR__ . DIRECTORY_SEPARATOR . '../../dev/vendor/autoload.php')) {
    require_once __DIR__ . DIRECTORY_SEPARATOR . '../../dev/vendor/autoload.php';
    if (! defined('FCPATH')) {
        define('FCPATH', __DIR__ . DIRECTORY_SEPARATOR);
    }
    chdir(FCPATH);
    $pathsConfig = realpath(FCPATH . '../../dev/app/Config/Paths.php');
}
define('BURNER_DRIVER', 'WorkerMan');

use CodeIgniter\Config\Factories;
use Config\Paths;
use Monken\CIBurner\Workerman\Config;
use Nyholm\Psr7\ServerRequest as PsrRequest;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use Workerman\Worker;

// CodeIgniter4 init
// Override is_cli()
if (! function_exists('is_cli')) {
    function is_cli(): bool
    {
        return false;
    }
}

// Ci4 4.2.0 init
require_once realpath($pathsConfig) ?: $pathsConfig;
$paths     = new Paths();
$botstorap = rtrim($paths->systemDirectory, '\\/ ') . DIRECTORY_SEPARATOR . 'bootstrap.php';
require_once realpath($botstorap);
require_once SYSTEMPATH . 'Config/DotEnv.php';
(new \CodeIgniter\Config\DotEnv(ROOTPATH))->load();
$app = \Config\Services::codeigniter();
$app->initialize();
$app->setContext('web');

/** @var \Config\Workerman */
$workermanConfig = Factories::config('Workerman');
if ($workermanConfig->autoReload) {
    require_once 'FileMonitor.php';
}
Config::staticSetting($workermanConfig);
$webWorker = new Worker(
    'http://0.0.0.0:' . $workermanConfig->listeningPort,
    $workermanConfig->ssl ? [
        'ssl' => [
            'local_cert'        => $workermanConfig->sslCertFilePath,
            'local_pk'          => $workermanConfig->sslKeyFilePath,
            'verify_peer'       => $workermanConfig->sslVerifyPeer,
            'allow_self_signed' => $workermanConfig->sslAllowSelfSigned,
        ],
    ] : []
);
$webWorker->name = 'CodeIgniter4';
Config::instanceSetting($webWorker, $workermanConfig);

// Worker 進入點
$webWorker->onMessage = static function (TcpConnection $connection, Request $request) use ($workermanConfig) {
    $workermanConfig->runtimeTcpConnection($connection);

    // Static File
    $response = \Monken\CIBurner\Workerman\StaticFile::withFile($request);
    if ((null === $response) === false) {
        $connection->send($response);

        return;
    }

    // init psr7 request
    $_SERVER['HTTP_USER_AGENT'] = $request->header('User-Agent');
    $psrRequest                 = (new PsrRequest(
        $request->method(),
        $request->uri(),
        $request->header(),
        $request->rawBody(),
        $request->protocolVersion(),
        $_SERVER
    ))->withQueryParams($request->get())
        ->withCookieParams($request->cookie())
        ->withParsedBody($request->post() ?? [])
        ->withUploadedFiles($request->file() ?? []);
    unset($request);

    // process response
    if ($response === null) {
        /** @var \Psr\Http\Message\ResponseInterface */
        $response = \Monken\CIBurner\App::run($psrRequest);
    }

    $workermanResponse = new Response(
        $response->getStatusCode(),
        $response->getHeaders(),
        $response->getBody()->getContents()
    );

    $connection->send($workermanResponse);
    \Monken\CIBurner\App::clean();
};

Worker::runAll();
