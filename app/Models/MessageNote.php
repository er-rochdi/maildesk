<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MessageNote extends Model
{
    protected $fillable = ['user_id', 'body'];

    public function author() { return $this->belongsTo(User::class, 'user_id'); }
}
