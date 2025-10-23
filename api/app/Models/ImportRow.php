<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportRow extends Model
{
    use HasFactory;

    public const ACTION_CREATE = 'create';
    public const ACTION_UPDATE = 'update';
    public const ACTION_ERROR = 'error';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'import_job_id',
        'player_id',
        'row_number',
        'payload',
        'action',
        'errors',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'payload' => 'array',
        'errors' => 'array',
    ];

    /**
     * @return BelongsTo<ImportJob, ImportRow>
     */
    public function importJob(): BelongsTo
    {
        return $this->belongsTo(ImportJob::class);
    }

    /**
     * @return BelongsTo<Player, ImportRow>
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }
}
