<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaestroWorkerEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'maestro_worker_id',
        'event_id',
        'worker_id',
        'event_type',
        'queue_name',
        'job_type',
        'job_id',
        'outcome',
        'notes',
        'payload_json',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(MaestroWorker::class, 'maestro_worker_id');
    }
}
