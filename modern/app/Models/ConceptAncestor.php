<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConceptAncestor extends Model
{
    protected $table = 'concept_ancestors';
    public $timestamps = false;
    public $incrementing = false;
    protected $guarded = [];
}
