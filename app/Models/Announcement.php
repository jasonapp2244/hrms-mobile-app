<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * An announcement HR broadcasts to staff (B5.5).
 *
 * A draft until `published_at` is set, and **immutable from that moment on**.
 * That is not tidiness: publishing writes a copy into everybody's notification
 * table and pushes it to every registered handset, and neither can be recalled.
 * Editing the row afterwards would leave this register disagreeing with what
 * two hundred people actually read — which is worse than not being able to fix
 * a typo, because the register is the thing you consult when it matters.
 *
 * @property-read bool $is_published
 */
class Announcement extends Model
{
    /** Everyone in the company. */
    public const ALL = 'all';

    /** One department, wherever they work. */
    public const DEPARTMENT = 'department';

    /** One office or branch, whatever they do. */
    public const OFFICE = 'office';

    public const AUDIENCES = [
        self::ALL        => 'Everyone',
        self::DEPARTMENT => 'One department',
        self::OFFICE     => 'One office',
    ];

    /**
     * What a push can carry before FCM starts refusing it.
     *
     * The whole announcement is written to the notification row and the app
     * shows it in full; the lock screen gets an opening. A push is a tap on the
     * shoulder — see PushMessage — and a four-kilobyte data payload is a failed
     * send for every recipient rather than a long notification.
     */
    public const PUSH_PREVIEW_CHARS = 180;

    protected $fillable = [
        'company_id', 'created_by', 'author_label', 'title', 'body',
        'audience', 'department_id', 'office_id', 'published_at', 'recipients_count',
    ];

    protected $casts = [
        'published_at' => 'datetime',
    ];

    /**
     * A published announcement is a record of something that happened.
     *
     * Same rule the attendance and activity trails keep. `publish()` is the one
     * caller allowed past it, and it goes through `saveQuietly()`.
     */
    protected static function booted(): void
    {
        static::updating(function (self $announcement) {
            if ($announcement->getOriginal('published_at') !== null) {
                throw new RuntimeException(
                    'A published announcement cannot be changed — it is already on people\'s phones.'
                );
            }
        });

        static::deleting(function (self $announcement) {
            if ($announcement->published_at !== null) {
                throw new RuntimeException(
                    'A published announcement cannot be deleted — deleting the record would not unsend it.'
                );
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function getIsPublishedAttribute(): bool
    {
        return $this->published_at !== null;
    }

    public function scopeDrafts(Builder $query): Builder
    {
        return $query->whereNull('published_at');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull('published_at');
    }

    /** How the register describes who this went to. */
    public function audienceLabel(): string
    {
        return match ($this->audience) {
            self::DEPARTMENT => $this->department?->name ?? 'A department that no longer exists',
            self::OFFICE     => $this->office?->name ?? 'An office that no longer exists',
            default          => self::AUDIENCES[self::ALL],
        };
    }

    /**
     * The people this would reach, right now.
     *
     * **Users, not employees.** A notification is addressed to an account, and
     * an announcement about the office closing is for whoever works there —
     * including the HR administrator with no employee record, who would
     * otherwise be the one person not told about the thing they announced.
     *
     * That is also why the two narrowed audiences use `whereHas`: an account
     * with no employee record has no department and no office, so it falls out
     * of a departmental announcement on its own, without a special case.
     *
     * Inactive accounts are excluded. Somebody who has left does not need to
     * hear about next week's shutdown, and their tokens are gone anyway.
     *
     * @return Collection<int, User>
     */
    public function recipients(): Collection
    {
        $users = User::where('company_id', $this->company_id)
            ->where('is_active', true);

        if ($this->audience === self::DEPARTMENT) {
            $users->whereHas('employee', fn ($q) => $q->where('department_id', $this->department_id));
        }

        if ($this->audience === self::OFFICE) {
            $users->whereHas('employee', fn ($q) => $q->where('office_id', $this->office_id));
        }

        return $users->get();
    }
}
