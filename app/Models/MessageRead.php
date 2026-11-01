<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class MessageRead extends Pivot
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }
}
