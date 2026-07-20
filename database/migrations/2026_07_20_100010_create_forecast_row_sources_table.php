<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Uid\UuidV7;

/**
 * Promotion der Formel-Definition vom JSON-Blob (`forecast_rows.config`) zu echtem
 * Datenmodell: `forecast_rows.agg` (Spalte) + `forecast_row_sources` (Quell-Relation).
 * source_plan_id (nullable) ist forward-kompatibel für Plan-Verweise/Konsolidierung.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('forecast_rows', 'agg')) {
            Schema::table('forecast_rows', function (Blueprint $table) {
                $table->string('agg')->nullable()->after('kind');
            });
        }

        if (! Schema::hasTable('forecast_row_sources')) {
            Schema::create('forecast_row_sources', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('row_id')->constrained('forecast_rows')->cascadeOnDelete();
                $table->foreignId('source_plan_id')->nullable()->constrained('forecast_plans')->nullOnDelete(); // null = selbe Planung
                $table->string('source_row_key');
                $table->decimal('weight', 12, 4)->default(1);
                $table->integer('sort_order')->default(0);
                $table->timestamps();

                $table->index('row_id');
                $table->index('source_plan_id');
            });
        }

        // Daten-Migration: bestehende config-Blobs → agg-Spalte + row_sources
        foreach (DB::table('forecast_rows')->where('kind', 'formula')->get() as $row) {
            $cfg = json_decode($row->config ?? '{}', true) ?: [];
            DB::table('forecast_rows')->where('id', $row->id)->update(['agg' => $cfg['agg'] ?? 'sum']);

            $exists = DB::table('forecast_row_sources')->where('row_id', $row->id)->exists();
            if ($exists) {
                continue;
            }
            $i = 0;
            foreach (($cfg['sources'] ?? []) as $src) {
                DB::table('forecast_row_sources')->insert([
                    'uuid' => (string) UuidV7::generate(),
                    'row_id' => $row->id,
                    'source_plan_id' => null,
                    'source_row_key' => $src,
                    'weight' => 1,
                    'sort_order' => $i++,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('forecast_row_sources');
        if (Schema::hasColumn('forecast_rows', 'agg')) {
            Schema::table('forecast_rows', function (Blueprint $table) {
                $table->dropColumn('agg');
            });
        }
    }
};
