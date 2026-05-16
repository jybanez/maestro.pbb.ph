<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class MaestroTelemetryToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'maestro_application_id',
        'label',
        'token_hash',
        'last_used_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public static function makePlainTextToken(): string
    {
        return Str::random(64);
    }

    public static function hashToken(string $plainTextToken): string
    {
        return hash('sha256', $plainTextToken);
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(MaestroApplication::class, 'maestro_application_id');
    }
}
