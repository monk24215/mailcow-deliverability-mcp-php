<?php

declare(strict_types=1);

/*
 * Streamable HTTP entry point, for PHP's built-in server or any PHP-FPM setup:
 *
 *   php -S 127.0.0.1:8080 public/index.php
 *
 * The MCP endpoint is http://127.0.0.1:8080/mcp. Each request is a new PHP
 * process, so sessions are kept on disk in var/sessions.
 */

require dirname(__DIR__).'/vendor/autoload.php';

use MailcowDeliverability\Config;
use MailcowDeliverability\ServerFactory;
use Mcp\Server\Session\FileSessionStore;
use Mcp\Server\Transport\StreamableHttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', \PHP_URL_PATH);
if ('/mcp' !== $path) {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo "MCP endpoint is /mcp\n";

    return;
}

$psr17 = new Psr17Factory();
$request = (new ServerRequestCreator($psr17, $psr17, $psr17, $psr17))->fromGlobals();

$server = ServerFactory::builder(Config::fromEnvironment())
    ->setSession(new FileSessionStore(dirname(__DIR__).'/var/sessions'))
    ->build();

$response = $server->run(new StreamableHttpTransport($request, $psr17, $psr17));

http_response_code($response->getStatusCode());
foreach ($response->getHeaders() as $name => $values) {
    foreach ($values as $value) {
        header(sprintf('%s: %s', $name, $value), false);
    }
}
$body = $response->getBody();
if ($body->isSeekable()) {
    $body->rewind();
}
while (!$body->eof()) {
    echo $body->read(8192);
    flush();
}
