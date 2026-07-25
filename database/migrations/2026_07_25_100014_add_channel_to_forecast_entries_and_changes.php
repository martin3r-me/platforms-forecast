<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zwei Wert-Kanäle je Zelle: 'plan' (Planung, Default) und 'actual' (Ist). Ist-Werte sind
 * einfach Einträge mit channel='actual' — sie rollen mit derselben Reconciliation hoch.
 * Unique je (plan, row, bucket, channel), damit Plan & Ist unabhängig nebeneinander existieren.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('forecast_entries') && ! Schema::hasColumn('forecast_entries', 'channel')) {
            Schema::table('forecast_entries', function (Blueprint $table) {
                $table->string('channel')->default('plan')->after('bucket_key'); // plan | actual
            });
            Schema::table('forecast_entries', function (Blueprint $table) {
                $table->dropUnique(['plan_id', 'row_key', 'bucket_key']);
                $table->unique(['plan_id', 'row_key', 'bucket_key', 'channel']);
            });
        }

        if (Schema::hasTable('forecast_changes') && ! Schema::hasColumn('forecast_changes', 'channel')) {
            Schema::table('forecast_changes', function (Blueprint $table) {
                $table->string('channel')->default('plan')->after('bucket_key');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('forecast_entries') && Schema::hasColumn('forecast_entries', 'channel')) {
            Schema::table('forecast_entries', function (Blueprint $table) {
                $table->dropUnique(['plan_id', 'row_key', 'bucket_key', 'channel']);
                $table->unique(['plan_id', 'row_key', 'bucket_key']);
                $table->dropColumn('channel');
            });
        }
        if (Schema::hasTable('forecast_changes') && Schema::hasColumn('forecast_changes', 'channel')) {
            Schema::table('forecast_changes', function (Blueprint $table) {
                $table->dropColumn('channel');
            });
        }
    }
};
