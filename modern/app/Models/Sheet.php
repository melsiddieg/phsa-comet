<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * @property-read Collection<int, SheetSourceColumn> $sourceColumns
 * @property-read Collection<int, SheetAttribute> $sheetAttributes
 */
class Sheet extends Model
{
    protected $table = 'sheets';
    protected $guarded = [];

    public function sourceColumns() { return $this->hasMany(SheetSourceColumn::class)->orderBy('spot'); }
    public function sheetAttributes() { return $this->hasMany(SheetAttribute::class)->orderBy('col_position'); }
    public function vocabularyNames() { return $this->hasMany(SheetVocabulary::class); }
    public function domains() { return $this->hasMany(SheetDomain::class); }
    public function terms() { return $this->hasMany(SourceTerm::class); }
}
