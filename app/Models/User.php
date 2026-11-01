<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

class User extends Authenticatable
{
    public const PERMISSIONS = ['read_emails', 'send_emails', 'sync_mailboxes', 'view_send_activity', 'view_read_activity', 'view_dashboard', 'manage_users', 'manage_mailboxes'];

    public const DEFAULT_PERMISSIONS = ['read_emails', 'send_emails', 'sync_mailboxes'];

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $attributes = ['is_active' => true, 'session_version' => 0];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'company_id',
        'role',
        'email',
        'password',
        'permissions',
        'signature',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'session_version',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    public function company() { return $this->belongsTo(Company::class); }

    public function mailboxes() { return $this->belongsToMany(Mailbox::class); }

    public function hasPermission(string $permission): bool
    {
        return $this->is_active && in_array($permission, self::PERMISSIONS, true)
            && ($this->role === 'admin' || in_array($permission, $this->permissions ?? self::DEFAULT_PERMISSIONS, true));
    }

    public function revokeSessions(): void
    {
        $this->increment('session_version', 1, ['remember_token' => Str::random(60)]);
        $this->refresh();
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'permissions' => 'array',
            'is_active' => 'boolean',
            'session_version' => 'integer',
        ];
    }
}
