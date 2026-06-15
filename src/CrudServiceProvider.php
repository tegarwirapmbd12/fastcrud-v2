<?php

declare(strict_types=1);

namespace Tgrwirapmbd\CRUDGenerator;

use Tgrwirapmbd\CRUDGenerator\Commands\MakeCrud;
use Illuminate\Support\ServiceProvider;

class CrudServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Load package configuration
        $this->mergeConfigFrom(__DIR__.'/../config/crud_generator.php', 'crud_generator');

        // Register commands
        $this->commands([
            MakeCrud::class,
        ]);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/crud_generator.php' => config_path('crud_generator.php'),
        ], 'config');
    }
}
