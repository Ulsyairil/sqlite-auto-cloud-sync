<?php

declare(strict_types=1);

namespace SqliteSync\Symfony;

use SqliteSync\Symfony\DependencyInjection\SqliteSyncExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class SqliteSyncBundle extends Bundle
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new SqliteSyncExtension();
    }
}
