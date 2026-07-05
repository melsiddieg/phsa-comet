<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Concept extends Model
{
    protected $table = 'concepts';
    protected $primaryKey = 'concept_id';
    public $incrementing = false;
    public $timestamps = false;
    protected $guarded = [];

    public function synonyms() { return $this->hasMany(ConceptSynonym::class, 'concept_id', 'concept_id'); }

    public function isValidMapTarget(): bool
    {
        return $this->standard_concept === 'S' && $this->invalid_reason === null;
    }
}
