<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Uid\UuidV7;

/**
 * Einheiten mit Umrechnung. Innerhalb einer Dimension (money/time/count/…) wird
 * über factor_to_base umgerechnet. Dimensionsübergreifend (h→€) läuft später über
 * Raten auf Plan-/Zeilen-Ebene, NICHT hier. team_id null = global.
 * Pflege später über Settings (UI aktuell read-only).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('forecast_units')) {
            Schema::create('forecast_units', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('team_id')->nullable()->constrained('teams')->cascadeOnDelete();
                $table->string('code');
                $table->string('name');
                $table->string('symbol');
                $table->string('dimension');                 // money | time | count | personnel | ratio
                $table->decimal('factor_to_base', 20, 8)->default(1);
                $table->boolean('is_base')->default(false);
                $table->integer('sort_order')->default(0);
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['team_id', 'code']);
                $table->index('dimension');
            });
        }

        // Globale Default-Einheiten
        $defaults = [
            ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'dimension' => 'money', 'factor_to_base' => 1, 'is_base' => true, 'sort_order' => 1],
            ['code' => 'KEUR', 'name' => 'Tausend Euro', 'symbol' => 'k€', 'dimension' => 'money', 'factor_to_base' => 1000, 'is_base' => false, 'sort_order' => 2],
            ['code' => 'H', 'name' => 'Stunden', 'symbol' => 'h', 'dimension' => 'time', 'factor_to_base' => 1, 'is_base' => true, 'sort_order' => 3],
            ['code' => 'MIN', 'name' => 'Minuten', 'symbol' => 'min', 'dimension' => 'time', 'factor_to_base' => 0.01666667, 'is_base' => false, 'sort_order' => 4],
            ['code' => 'FTE', 'name' => 'Vollzeitäquivalent', 'symbol' => 'FTE', 'dimension' => 'personnel', 'factor_to_base' => 1, 'is_base' => true, 'sort_order' => 5],
            ['code' => 'PCS', 'name' => 'Stück', 'symbol' => 'Stk', 'dimension' => 'count', 'factor_to_base' => 1, 'is_base' => true, 'sort_order' => 6],
            ['code' => 'PCT', 'name' => 'Prozent', 'symbol' => '%', 'dimension' => 'ratio', 'factor_to_base' => 1, 'is_base' => true, 'sort_order' => 7],
        ];

        foreach ($defaults as $u) {
            $exists = DB::table('forecast_units')->whereNull('team_id')->where('code', $u['code'])->exists();
            if (! $exists) {
                DB::table('forecast_units')->insert(array_merge($u, [
                    'uuid' => (string) UuidV7::generate(),
                    'team_id' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('forecast_units');
    }
};
