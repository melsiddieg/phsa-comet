<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MapAudit extends Model
{
    protected $table = 'map_audits';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array { return ['source_snapshot' => 'array', 'approved_at' => 'datetime', 'created_at' => 'datetime']; }
}
