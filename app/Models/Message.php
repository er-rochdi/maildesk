<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['to' => 'array', 'cc' => 'array', 'message_at' => 'datetime', 'sent_at' => 'datetime'];
    }

    public function mailbox()
    {
        return $this->belongsTo(Mailbox::class);
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_user_id')->select('id', 'name');
    }

    public function readers()
    {
        return $this->belongsToMany(User::class, 'message_reads')->using(MessageRead::class)->withPivot('read_at')->orderByPivot('read_at')->select('users.id', 'users.name');
    }

    public function attachments()
    {
        return $this->hasMany(Attachment::class);
    }
}
