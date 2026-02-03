<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Student extends Model
{
    protected $fillable = [
        'sbd',
        'ma_ngoai_ngu',
        'group_a_score',
    ];

    protected $casts = [
        'group_a_score' => 'decimal:2',
    ];

    /**
     * Get all scores for this student
     */
    public function scores(): HasMany
    {
        return $this->hasMany(Score::class);
    }
}
