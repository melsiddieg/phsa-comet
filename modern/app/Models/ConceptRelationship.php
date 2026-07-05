<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConceptRelationship extends Model
{
    protected $table = 'concept_relationships';
    public $incrementing = false;
    public $timestamps = false;
    protected $guarded = [];

}
