<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Invitation model — represents a pending invite for a new user to join
 * a workspace. Created by existing users and consumed when the invitee
 * registers via the invitation link.
 */
class Invitation extends Model
{
    protected $fillable = [
        'workspace_id',
        'invited_by',
        'email',
        'token',
        'accepted_at',
        'role',
    ];

    protected $casts = [
        'accepted_at' => 'datetime',
    ];

    /** The workspace this invitation belongs to. */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** The user who sent this invitation. */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /** Check whether this invitation has already been accepted. */
    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    /** Check whether this invitation has expired (7-day window). */
    public function isExpired(): bool
    {
        return $this->created_at->addDays(7)->isPast();
    }
}
