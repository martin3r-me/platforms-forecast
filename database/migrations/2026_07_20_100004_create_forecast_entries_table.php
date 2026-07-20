<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Die "sparse" Zellen — aktueller Stand. Nur tatsächlich eingegebene Zellen
 * existieren; alles andere ist impliziter Rest. Eine Zelle je (plan, row, bucket).
 * mode = detail | plus (die zentrale Entscheidung bei jeder Eingabe).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('forecast_entries')) {
            return;
        }

        Schema::create('forecast_entries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('forecast_plans')->cascadeOnDelete();

            $table->string('row_key');
            $table->string('bucket_key');          // z.B. "2026-07-12"
            $table->string('level');               // year | month | day | hour
            $table->decimal('value', 20, 4);
            $table->string('mode')->default('detail'); // detail | plus

            $table->timestamps();

            $table->unique(['plan_id', 'row_key', 'bucket_key']);
            $table->index(['plan_id', 'row_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forecast_entries');
    }
};
