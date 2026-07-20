<?php

namespace Platform\Forecast\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Platform\Forecast\Reconciliation\Mode;
use Symfony\Component\Uid\UuidV7;

/**
 * Plan-Instanz. Sitzt an einem Org-Knoten (organization_entity_id) und rollt
 * über den Org-Baum auf; org_mode steuert, wie sie in den Elternknoten läuft.
 */
class ForecastPlan extends Model
{
    use SoftDeletes;

    protected $table = 'forecast_plans';

    protected $fillable = [
        'uuid', 'team_id', 'user_id', 'plan_type_id', 'organization_entity_id',
        'name', 'base_level', 'period_start', 'period_end', 'org_mode',
        'lock_policy_id', 'current_version', 'metadata',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'org_mode' => Mode::class,
        'current_version' => 'integer',
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) UuidV7::generate();
            }
        });
    }

    public function team()
    {
        return $this->belongsTo(\Platform\Core\Models\Team::class);
    }

    public function user()
    {
        return $this->belongsTo(\Platform\Core\Models\User::class);
    }

    public function planType()
    {
        return $this->belongsTo(ForecastPlanType::class, 'plan_type_id');
    }

    public function lockPolicy()
    {
        return $this->belongsTo(ForecastLockPolicy::class, 'lock_policy_id');
    }

    /** Instanz-eigene Zeilen (Ergänzungen). */
    public function rows()
    {
        return $this->hasMany(ForecastRow::class, 'plan_id');
    }

    public function entries()
    {
        return $this->hasMany(ForecastEntry::class, 'plan_id');
    }

    public function changes()
    {
        return $this->hasMany(ForecastChange::class, 'plan_id');
    }

    public function snapshots()
    {
        return $this->hasMany(ForecastSnapshot::class, 'plan_id');
    }

    /** Org-Knoten (nur nutzbar, wenn Organization-Modul vorhanden). */
    public function organizationEntity()
    {
        return $this->belongsTo(\Platform\Organization\Models\OrganizationEntity::class, 'organization_entity_id');
    }

    /**
     * Aufgelöste Zeilen: Typ-Zeilen + Instanz-Zeilen; Instanz überschreibt bei
     * gleichem key ("Typ definiert + Instanz kann ergänzen").
     *
     * @return Collection<int, ForecastRow>
     */
    public function resolvedRows(): Collection
    {
        $typeRows = $this->planType?->rows()->orderBy('order')->get() ?? collect();
        $instanceRows = $this->rows()->orderBy('order')->get();

        $byKey = [];
        foreach ($typeRows as $row) {
            $byKey[$row->key] = $row;
        }
        foreach ($instanceRows as $row) {
            $byKey[$row->key] = $row; // Instanz gewinnt
        }

        return collect(array_values($byKey));
    }
}
