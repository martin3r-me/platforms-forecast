<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Uid\UuidV7;

/**
 * Sperr-Regeln als eigenes, entkoppeltes Modell (nicht mehr als JSON-Blob am Plan).
 * Benannt, wiederverwendbar, an Pläne hängbar. team_id null = global.
 * Vergangenheit zu · Vorlauf öffnet vor Start · Nachlauf hält nach Ende offen ·
 * Entscheidung auf period_level, feinere Ebenen erben (Kaskade).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('forecast_lock_policies')) {
            Schema::create('forecast_lock_policies', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('team_id')->nullable()->constrained('teams')->cascadeOnDelete();
                $table->string('name');
                $table->string('period_level')->default('month');   // year|quarter|month|day
                $table->integer('lead_days')->default(40);          // Vorlauf
                $table->integer('grace_days')->default(10);         // Nachlauf
                $table->boolean('freeze_past')->default(true);
                $table->boolean('is_default')->default(false);
                $table->json('config')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index('team_id');
            });
        }

        if (! Schema::hasColumn('forecast_plans', 'lock_policy_id')) {
            Schema::table('forecast_plans', function (Blueprint $table) {
                $table->foreignId('lock_policy_id')->nullable()->after('org_mode')
                    ->constrained('forecast_lock_policies')->nullOnDelete();
            });
        }

        // Globale Default-Policy
        if (! DB::table('forecast_lock_policies')->whereNull('team_id')->where('is_default', true)->exists()) {
            DB::table('forecast_lock_policies')->insert([
                'uuid' => (string) UuidV7::generate(),
                'team_id' => null,
                'name' => 'Standard (Monat · Vorlauf 40 / Nachlauf 10)',
                'period_level' => 'month',
                'lead_days' => 40,
                'grace_days' => 10,
                'freeze_past' => true,
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('forecast_plans', 'lock_policy_id')) {
            Schema::table('forecast_plans', function (Blueprint $table) {
                $table->dropConstrainedForeignId('lock_policy_id');
            });
        }
        Schema::dropIfExists('forecast_lock_policies');
    }
};
