<?php

declare(strict_types=1);

namespace Tgrwirapmbd\CRUDGenerator\Tests;

use Orchestra\Testbench\TestCase as Testbench;
use Tgrwirapmbd\CRUDGenerator\CrudServiceProvider;

abstract class TestCase extends Testbench
{
    protected function getPackageProviders($app): array
    {
        return [
            CrudServiceProvider::class,
        ];
    }
}
