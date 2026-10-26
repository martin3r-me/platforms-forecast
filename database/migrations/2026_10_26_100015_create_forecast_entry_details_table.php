<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unterzeilen / Aufschlüsselung einer Zelle.
 *
 * Eine aufgeschlüsselte Zelle hält keinen eigenen Direktwert mehr, sondern eine Liste
 * benannter Teilbeträge (Label + Betrag). Der Zellwert in forecast_entries wird beim
 * Schreiben synchron als Σ der Unterzeilen gepflegt — Reconciliation, Zeitverteilung,
 * Rollup und Export sehen damit unverändert nur den fertigen Zellwert.
 *
 * Granularität wie forecast_entries: je (plan, row, bucket, channel). Die Unterzeilen
 * erben die Zeit-Ebene der Zelle (level/bucket_key), auf der aufgeschlüsselt wird.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forecast_entry_details', function (Blueprint $table) {
            $table->id();

            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('forecast_plans')->cascadeOnDelete();

            $table->string('row_key');
            $table->string('bucket_key');          // dieselbe Zeit-Ebene wie die Oberzelle
            $table->string('channel')->default('plan'); // plan | actual
            $table->string('level');               // year | month | day | hour

            $table->string('label');               // „Bange Profi", „Reifenwechsel", …
            $table->decimal('value', 20, 4);       // vorzeichenerhaltend (Entlastung = negativ)
            $table->unsignedInteger('ordinal')->default(0); // Reihenfolge im Modal

            $table->timestamps();

            $table->index(['plan_id', 'row_key', 'bucket_key', 'channel'], 'fed_cell_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forecast_entry_details');
    }
};
