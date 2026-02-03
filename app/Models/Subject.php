<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subject extends Model
{
    protected $fillable = [
        'key',
        'name',
    ];

    /**
     * Get all scores for this subject
     */
    public function scores(): HasMany
    {
        return $this->hasMany(Score::class);
    }
}
