<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read SourceTerm|null $sourceTerm
 * @property-read Concept|null $targetConcept
 */
class MapEntry extends Model
{
    protected $table = 'maps';

    protected $guarded = [];

    public function sourceTerm(): BelongsTo
    {
        return $this->belongsTo(SourceTerm::class);
    }

    public function targetConcept(): BelongsTo
    {
        return $this->belongsTo(Concept::class, 'target_concept_id', 'concept_id');
    }
}
