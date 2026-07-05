<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MapEntry extends Model
{
    protected $table = 'maps';
    protected $guarded = [];

    public function sourceTerm() { return $this->belongsTo(SourceTerm::class); }
    public function targetConcept() { return $this->belongsTo(Concept::class, 'target_concept_id', 'concept_id'); }
}
