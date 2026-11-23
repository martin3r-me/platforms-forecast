<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kommentare (Threads) an einer Planungs-Zelle ODER einer ganzen Zeile.
 *
 * Anker = (plan_id, row_key, bucket_key): bucket_key gesetzt → Kommentar an EINER Zelle
 * (Zeile × Zeitraum), bucket_key NULL → Kommentar an der ganzen Zeile. Jeder Datensatz ist
 * EIN Beitrag im Thread; mehrere Beiträge am selben Anker bilden den Verlauf. Reine Annotation
 * — keine neue Plan-Version, keine Konsolidierung.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forecast_comments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('team_id')->index();
            $table->unsignedBigInteger('plan_id')->index();
            $table->string('row_key');
            $table->string('bucket_key')->nullable(); // NULL = Kommentar an der ganzen Zeile
            $table->unsignedBigInteger('user_id')->index();
            $table->text('body');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['plan_id', 'row_key', 'bucket_key'], 'fc_comments_anchor_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('forecast_comments');
    }
};
