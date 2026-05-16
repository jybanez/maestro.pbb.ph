<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MaestroApplication extends Model
{
    use HasFactory;

    protected $fillable = [
        'app_code',
        'display_name',
        'environment',
        'base_url',
        'is_active',
        'meta_json',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'meta_json' => 'array',
        ];
    }

    public function telemetryTokens(): HasMany
    {
        return $this->hasMany(MaestroTelemetryToken::class);
    }

    public function workers(): HasMany
    {
        return $this->hasMany(MaestroWorker::class);
    }
}
