<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SourceTermComment extends Model
{
    protected $table = 'source_term_comments';
    protected $primaryKey = 'source_term_id';
    public $incrementing = false;
    protected $guarded = [];

}
