<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan-Typ = Vorlage. Definiert (zusammen mit forecast_rows) die Zeilen-Struktur,
 * die alle Instanzen dieses Typs teilen (z.B. "Forecast Veranstaltung").
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('forecast_plan_types')) {
            return;
        }

        Schema::create('forecast_plan_types', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('key');                 // stabiler Schlüssel je Team
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('config')->nullable();    // Typ-Ebene Zusatzkonfiguration

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['team_id', 'key']);
            $table->index('team_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forecast_plan_types');
    }
};
