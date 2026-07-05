<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SourceTerm extends Model
{
    protected $table = 'source_terms';
    protected $guarded = [];

    public function sheet() { return $this->belongsTo(Sheet::class); }
    public function codes() { return $this->hasMany(SourceTermCode::class)->orderBy('spot'); }
    public function maps() { return $this->hasMany(MapEntry::class); }
    public function suggestedTargets() { return $this->hasMany(SuggestedTarget::class); }
    public function comment() { return $this->hasOne(SourceTermComment::class); }
}
