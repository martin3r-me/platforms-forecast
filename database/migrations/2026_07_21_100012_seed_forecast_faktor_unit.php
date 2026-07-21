<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Uid\UuidV7;

/**
 * FAKTOR-Einheit: eingegebener Anteil (0–1, Anzeige als %). Dimension 'ratio' →
 * wird — wie alle Quoten — NICHT über Ordner/Zeit aufsummiert, sondern multipliziert.
 * Idempotent, global (team_id null).
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('forecast_units')->whereNull('team_id')->where('code', 'FAKTOR')->exists();
        if (! $exists) {
            DB::table('forecast_units')->insert([
                'uuid' => (string) UuidV7::generate(),
                'team_id' => null,
                'code' => 'FAKTOR',
                'name' => 'Faktor (Anteil)',
                'symbol' => '%',
                'dimension' => 'ratio',
                'factor_to_base' => 1,
                'is_base' => false,
                'sort_order' => 8,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('forecast_units')->whereNull('team_id')->where('code', 'FAKTOR')->delete();
    }
};
