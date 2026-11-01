<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Mailbox extends Model
{
    protected $fillable = ['company_id', 'name', 'email', 'imap_host', 'imap_port', 'smtp_host', 'smtp_port', 'password', 'sent_folder'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['password' => 'encrypted', 'last_synced_at' => 'datetime', 'resend_enabled' => 'boolean'];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }
}
