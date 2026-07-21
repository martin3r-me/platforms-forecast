<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Konsolidierung: ein Plan kann Kind-Instanzen haben (gleicher Typ). Der Eltern-
 * (Konsolidierungs-)Plan aggregiert seine Kinder Zeile für Zeile je Bucket;
 * Formel-Zeilen werden dabei auf den konsolidierten Eingaben neu gerechnet.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('forecast_plans', 'parent_plan_id')) {
            Schema::table('forecast_plans', function (Blueprint $table) {
                $table->foreignId('parent_plan_id')->nullable()->after('plan_type_id')
                    ->constrained('forecast_plans')->nullOnDelete();
                $table->index('parent_plan_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('forecast_plans', 'parent_plan_id')) {
            Schema::table('forecast_plans', function (Blueprint $table) {
                $table->dropConstrainedForeignId('parent_plan_id');
            });
        }
    }
};
