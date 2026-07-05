<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vocabulary extends Model
{
    protected $table = 'vocabularies';
    protected $primaryKey = 'vocabulary_id';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;
    protected $guarded = [];

}
