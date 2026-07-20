<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan-Instanz. Sitzt an genau einem Organisations-Knoten und rollt über den
 * Org-Baum auf. org_mode bestimmt, wie diese Instanz in den Elternknoten läuft
 * (default: detail — verfeinert das Ziel des Elternknotens; plus = zusätzlich).
 *
 * Hinweis: organization_entity_id ist bewusst OHNE DB-FK (nur Index), um die
 * Migration vom Organization-Modul zu entkoppeln; die Relation lebt im Model.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('forecast_plans')) {
            return;
        }

        Schema::create('forecast_plans', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('plan_type_id')->constrained('forecast_plan_types')->cascadeOnDelete();

            $table->unsignedBigInteger('organization_entity_id')->nullable();

            $table->string('name');
            $table->string('base_level')->default('month');   // Standard-Zoom
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->string('org_mode')->default('detail');     // detail | plus
            $table->unsignedInteger('current_version')->default(0);
            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('team_id');
            $table->index('plan_type_id');
            $table->index('organization_entity_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forecast_plans');
    }
};
