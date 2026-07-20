<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Benannte Snapshots — materialisierter Voll-Stand einer Planung zu einer Version.
 * Auf Wunsch erzeugt (nicht bei jeder Änderung) als Baseline / "Stand vor X".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('forecast_snapshots')) {
            return;
        }

        Schema::create('forecast_snapshots', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('forecast_plans')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('name');
            $table->unsignedInteger('version');    // plan.current_version zum Zeitpunkt
            $table->json('payload');               // vollständige Zellen-Kopie
            $table->text('note')->nullable();

            $table->timestamps();

            $table->index('plan_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forecast_snapshots');
    }
};
