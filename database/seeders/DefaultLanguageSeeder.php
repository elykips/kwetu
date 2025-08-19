<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class DefaultLanguageSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $exists = DB::table('languages')
            ->where('code', 'br')
            ->where('tenant_id', null)
            ->exists();

        if (! $exists) {
            DB::table('languages')->insert([
                'name' => 'Portuguese',
                'code' => 'br',
                'status' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $source = public_path('lang').'/br.json';
            $destination = resource_path('lang/translations/br.json');

            if (File::exists($source)) {
                File::ensureDirectoryExists(dirname($destination));
                File::copy($source, $destination);
            } else {
                // Create empty JSON file if source doesn't exist
                File::ensureDirectoryExists(dirname($destination));
                File::put($destination, '{}');
            }
        }
    }
}
