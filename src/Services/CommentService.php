<?php

namespace Platform\Forecast\Services;

use Platform\Forecast\Models\ForecastComment;
use Platform\Forecast\Models\ForecastPlan;

/**
 * Kommentar-Threads an Zellen/Zeilen einer Planung. Orthogonal zur Konsolidierung:
 * reine Annotation, keine neue Plan-Version. Anker = (plan, row_key, bucket_key),
 * bucket_key = null → Kommentar an der ganzen Zeile.
 */
class CommentService
{
    /** Der Thread (chronologisch) an einem Anker, inkl. Autor-Name. */
    public function thread(ForecastPlan $plan, string $rowKey, ?string $bucketKey): array
    {
        return ForecastComment::query()
            ->with('user')
            ->where('team_id', $plan->team_id)
            ->where('plan_id', $plan->id)
            ->where('row_key', $rowKey)
            ->where(function ($q) use ($bucketKey) {
                $bucketKey === null ? $q->whereNull('bucket_key') : $q->where('bucket_key', $bucketKey);
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (ForecastComment $c) => [
                'id' => (int) $c->id,
                'uuid' => (string) $c->uuid,
                'body' => (string) $c->body,
                'user_id' => (int) $c->user_id,
                'user_name' => $c->user?->name ?? 'Unbekannt',
                'created_label' => $c->created_at?->format('d.m.Y H:i'),
                'created_human' => $c->created_at?->diffForHumans(),
            ])
            ->all();
    }

    public function add(ForecastPlan $plan, string $rowKey, ?string $bucketKey, string $body, int $userId): ForecastComment
    {
        return ForecastComment::create([
            'team_id' => $plan->team_id,
            'plan_id' => $plan->id,
            'row_key' => $rowKey,
            'bucket_key' => $bucketKey,
            'user_id' => $userId,
            'body' => $body,
        ]);
    }

    public function delete(ForecastComment $comment): void
    {
        $comment->delete();
    }

    /**
     * Marker-Zählung für den ganzen Plan, damit das Grid Kommentar-Anker anzeigen kann.
     * Form: ['row' => [row_key => n], 'cell' => [row_key => [bucket_key => n]]].
     */
    public function counts(ForecastPlan $plan): array
    {
        $out = ['row' => [], 'cell' => []];

        $rows = ForecastComment::query()
            ->where('team_id', $plan->team_id)
            ->where('plan_id', $plan->id)
            ->selectRaw('row_key, bucket_key, COUNT(*) as n')
            ->groupBy('row_key', 'bucket_key')
            ->get();

        foreach ($rows as $r) {
            if ($r->bucket_key === null) {
                $out['row'][$r->row_key] = (int) $r->n;
            } else {
                $out['cell'][$r->row_key][$r->bucket_key] = (int) $r->n;
            }
        }

        return $out;
    }
}
