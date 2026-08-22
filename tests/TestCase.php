<?php

declare(strict_types=1);

namespace cieplik206\BirRegon\Tests;

use cieplik206\BirRegon\BirRegonServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\LaravelData\LaravelDataServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            LaravelDataServiceProvider::class,
            BirRegonServiceProvider::class,
        ];
    }
}
