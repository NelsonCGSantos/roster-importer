<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportJob extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_READY = 'ready';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'user_id',
        'original_filename',
        'stored_path',
        'file_hash',
        'status',
        'total_rows',
        'created_count',
        'updated_count',
        'error_count',
        'column_map',
        'error_report_path',
        'processed_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'column_map' => 'array',
        'processed_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Team, ImportJob>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<User, ImportJob>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<ImportRow>
     */
    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class);
    }
}
