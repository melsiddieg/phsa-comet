<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property-read Sheet $sheet
 * @property-read Collection<int, SourceTermCode> $codes
 * @property-read Collection<int, MapEntry> $maps
 * @property-read Collection<int, SuggestedTarget> $suggestedTargets
 * @property-read SourceTermComment|null $comment
 */
class SourceTerm extends Model
{
    protected $table = 'source_terms';

    protected $guarded = [];

    public function sheet(): BelongsTo
    {
        return $this->belongsTo(Sheet::class);
    }

    public function codes(): HasMany
    {
        return $this->hasMany(SourceTermCode::class)->orderBy('spot');
    }

    public function maps(): HasMany
    {
        return $this->hasMany(MapEntry::class);
    }

    public function suggestedTargets(): HasMany
    {
        return $this->hasMany(SuggestedTarget::class);
    }

    public function comment(): HasOne
    {
        return $this->hasOne(SourceTermComment::class);
    }
}
