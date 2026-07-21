<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Uid\UuidV7;

/**
 * Verteilungsschlüssel als eigenes, entkoppeltes Modell (wie die Sperr-Policy):
 * bestimmt, wie ein gröberer Wert / der Rest nach UNTEN auf die feineren, leeren
 * Zellen verteilt wird. 'even' = gleichmäßig; 'seasonal' = nach 12 Monatsgewichten.
 * team_id null = global. An Pläne hängbar; sonst greift der Default.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('forecast_distribution_policies')) {
            Schema::create('forecast_distribution_policies', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('team_id')->nullable()->constrained('teams')->cascadeOnDelete();
                $table->string('name');
                $table->string('key')->default('even');        // even | seasonal
                $table->json('weights')->nullable();           // 12 Monatsgewichte (relativ) bei seasonal
                $table->boolean('is_default')->default(false);
                $table->json('config')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index('team_id');
            });
        }

        if (! Schema::hasColumn('forecast_plans', 'distribution_policy_id')) {
            Schema::table('forecast_plans', function (Blueprint $table) {
                $table->foreignId('distribution_policy_id')->nullable()->after('lock_policy_id')
                    ->constrained('forecast_distribution_policies')->nullOnDelete();
            });
        }

        // Globaler Default: gleichmäßig
        if (! DB::table('forecast_distribution_policies')->whereNull('team_id')->where('is_default', true)->exists()) {
            DB::table('forecast_distribution_policies')->insert([
                'uuid' => (string) UuidV7::generate(),
                'team_id' => null,
                'name' => 'Gleichmäßig',
                'key' => 'even',
                'weights' => null,
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Beispiel: Gastronomie-Saison (Sommer + Dezember stark), nicht Default
        if (! DB::table('forecast_distribution_policies')->whereNull('team_id')->where('key', 'seasonal')->exists()) {
            DB::table('forecast_distribution_policies')->insert([
                'uuid' => (string) UuidV7::generate(),
                'team_id' => null,
                'name' => 'Gastronomie (saisonal)',
                'key' => 'seasonal',
                'weights' => json_encode([0.6, 0.6, 0.8, 1.0, 1.2, 1.4, 1.5, 1.4, 1.0, 0.9, 0.8, 1.8]),
                'is_default' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('forecast_plans', 'distribution_policy_id')) {
            Schema::table('forecast_plans', function (Blueprint $table) {
                $table->dropConstrainedForeignId('distribution_policy_id');
            });
        }
        Schema::dropIfExists('forecast_distribution_policies');
    }
};
