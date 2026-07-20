<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Eine Zeile gehört ENTWEDER zu einem Typ (Vorlage; plan_type_id gesetzt,
 * plan_id null) ODER zu einer Instanz (Ergänzung; plan_id gesetzt).
 * Auflösung einer Planung: Typ-Zeilen + Instanz-Zeilen (Instanz überschreibt
 * bei gleichem key). So gilt: "Typ definiert + Instanz kann ergänzen".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('forecast_rows')) {
            return;
        }

        Schema::create('forecast_rows', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('plan_type_id')->nullable()->constrained('forecast_plan_types')->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained('forecast_plans')->cascadeOnDelete();

            $table->string('key');                 // Adresse der Zeile innerhalb der Planung
            $table->string('label');
            $table->string('kind')->default('input'); // input | sum | percent | reference
            $table->json('config')->nullable();       // Formel-Konfiguration
            $table->integer('order')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index('plan_type_id');
            $table->index('plan_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forecast_rows');
    }
};
