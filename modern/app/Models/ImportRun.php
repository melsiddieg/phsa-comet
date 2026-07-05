<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read Sheet|null $sheet
 */
class ImportRun extends Model
{
    protected $table = 'import_runs';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function sheet(): BelongsTo
    {
        return $this->belongsTo(Sheet::class);
    }
}
