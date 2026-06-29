<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Database\Seeders\Core\DatabaseSeeder as CoreDatabaseSeeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(CoreDatabaseSeeder::class);
    }
}
