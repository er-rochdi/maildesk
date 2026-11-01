<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['to' => 'array', 'cc' => 'array', 'bcc' => 'array', 'message_at' => 'datetime', 'sent_at' => 'datetime'];
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

    public function notes() { return $this->hasMany(MessageNote::class); }

    public function visibleActivityFor(User $user): static
    {
        if (! $user->hasPermission('view_send_activity')) {
            $this->makeHidden(['sender_user_id', 'sender'])->unsetRelation('sender');
        }
        if (! $user->hasPermission('view_read_activity')) {
            $this->makeHidden('readers')->unsetRelation('readers');
        }

        return $this;
    }
}
