<?php

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        Tenant::firstOrCreate(['code' => 'GLOBALE_SANTE'], [
            'name' => 'Globale Santé',
            'address' => 'Lot N°30 25615 Dakar Fann',
            'phone' => '+221 33 860 81 81',
        ]);
    }
}
