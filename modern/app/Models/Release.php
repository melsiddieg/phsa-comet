<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Release extends Model
{
    protected $table = 'releases';
    public $timestamps = false;
    protected $guarded = [];

    public function maps() { return $this->hasMany(ReleaseMap::class); }
}
