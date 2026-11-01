<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Attachment extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['path'];

    public static function safeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name);

        return mb_substr($name ?: 'piece-jointe', 0, 200);
    }

    public function message()
    {
        return $this->belongsTo(Message::class);
    }
}
