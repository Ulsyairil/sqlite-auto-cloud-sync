<?php

declare(strict_types=1);

namespace SqliteSync\CakePHP\Middleware;

use Cake\Log\Log;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SqliteSync\CakePHP\Config\Sync as SyncSettings;
use SqliteSync\CakePHP\SqliteSync;

/**
 * Installs triggers before the request and optionally runs `sync()` after the
 * response is built.
 *
 * Register in `src/Application.php`:
 *
 * ```php
 * public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
 * {
 *     return $middlewareQueue->add(new \SqliteSync\CakePHP\Middleware\AutoSyncMiddleware());
 * }
 * ```
 *
 * For large datasets run sync from a queue/CLI instead of the request lifecycle.
 */
final class AutoSyncMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly ?SyncSettings $settings = null)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $settings = $this->settings ?? SqliteSync::settings();

        if ($settings->autoInstall) {
            SqliteSync::engine($settings);
        }

        $response = $handler->handle($request);

        if (!$settings->autoSync) {
            return $response;
        }

        try {
            SqliteSync::engine($settings)->sync();
        } catch (\Throwable $e) {
            Log::error('sqlite-sync: ' . $e->getMessage());
        }

        return $response;
    }
}
