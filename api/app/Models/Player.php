<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Player extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'team_id',
        'full_name',
        'email',
        'jersey',
        'position',
    ];

    /**
     * @return BelongsTo<Team, Player>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
