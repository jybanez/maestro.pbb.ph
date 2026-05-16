<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MaestroWorker extends Model
{
    use HasFactory;

    protected $fillable = [
        'maestro_application_id',
        'worker_id',
        'host_name',
        'queue_name',
        'process_id',
        'status',
        'started_at',
        'last_heartbeat_at',
        'last_job_started_at',
        'last_job_finished_at',
        'current_job_type',
        'current_job_id',
        'processed_count',
        'failed_count',
        'memory_mb',
        'stopped_at',
        'meta_json',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'last_heartbeat_at' => 'datetime',
            'last_job_started_at' => 'datetime',
            'last_job_finished_at' => 'datetime',
            'memory_mb' => 'decimal:2',
            'stopped_at' => 'datetime',
            'meta_json' => 'array',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(MaestroApplication::class, 'maestro_application_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(MaestroWorkerEvent::class);
    }
}
