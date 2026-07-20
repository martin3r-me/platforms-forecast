<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zeile bekommt Einheit + Richtung. direction ist drei-wertig:
 * income (+) | expense (−) | neutral (Messgröße ohne Vorzeichen).
 * Betrag bleibt im Modell positiv — Vorzeichen ist Anzeige/Aggregation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forecast_rows', function (Blueprint $table) {
            if (! Schema::hasColumn('forecast_rows', 'unit_id')) {
                $table->foreignId('unit_id')->nullable()->after('kind')->constrained('forecast_units')->nullOnDelete();
            }
            if (! Schema::hasColumn('forecast_rows', 'direction')) {
                $table->string('direction')->default('neutral')->after('unit_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('forecast_rows', function (Blueprint $table) {
            if (Schema::hasColumn('forecast_rows', 'unit_id')) {
                $table->dropConstrainedForeignId('unit_id');
            }
            if (Schema::hasColumn('forecast_rows', 'direction')) {
                $table->dropColumn('direction');
            }
        });
    }
};
