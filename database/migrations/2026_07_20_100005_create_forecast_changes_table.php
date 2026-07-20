<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only Event-Log. JEDE Änderung erzeugt eine neue Version (plan.current_version++).
 * Unveränderlich (nur created_at). Damit ist die komplette Historie rekonstruierbar.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('forecast_changes')) {
            return;
        }

        Schema::create('forecast_changes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('forecast_plans')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->unsignedInteger('version');
            $table->string('op');                  // set | clear | row_add | plan_create ...
            $table->string('row_key')->nullable();
            $table->string('bucket_key')->nullable();
            $table->string('level')->nullable();
            $table->decimal('old_value', 20, 4)->nullable();
            $table->string('old_mode')->nullable();
            $table->decimal('new_value', 20, 4)->nullable();
            $table->string('new_mode')->nullable();
            $table->json('payload')->nullable();

            $table->timestamp('created_at')->nullable();

            $table->index(['plan_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forecast_changes');
    }
};
