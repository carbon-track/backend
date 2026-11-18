<?php

declare(strict_types=1);

namespace CarbonTrack\Tests\Support;

use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Connection;

final class DatabaseFixture
{
    public static function sqlite(string $database = ':memory:'): Connection
    {
        if ($database !== ':memory:' && !is_file($database)) {
            $file = fopen($database, 'c');
            if ($file === false) {
                throw new \RuntimeException('Could not create the test database.');
            }
            fclose($file);
        }

        $manager = new Manager();
        $manager->addConnection([
            'driver' => 'sqlite',
            'database' => $database,
            'prefix' => '',
        ]);

        return $manager->getConnection();
    }
}
