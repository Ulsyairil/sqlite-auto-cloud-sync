<?php

declare(strict_types=1);

namespace SqliteSync\Yii;

use yii\base\BootstrapInterface;

/**
 * Register in `config/main.php`:
 *
 * ```php
 * 'bootstrap' => [\SqliteSync\Yii\Bootstrap::class],
 * ```
 */
final class Bootstrap implements BootstrapInterface
{
    public function bootstrap($app): void
    {
        SqliteSync::boot();
    }
}
