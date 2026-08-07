<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A file held against an employee record (A3.8).
 */
class EmployeeDocument extends Model
{
    protected $fillable = [
        'company_id', 'employee_id', 'type', 'title',
        'path', 'original_name', 'mime_type', 'size_bytes',
        'issued_on', 'expires_on', 'notes',
        'uploaded_by_user_id', 'expiry_notified_at',
    ];

    protected $casts = [
        'issued_on'          => 'date',
        'expires_on'         => 'date',
        'expiry_notified_at' => 'datetime',
    ];

    /** The kinds of document HR actually files, and what to call them. */
    public const TYPES = [
        'contract'      => 'Contract',
        'id'            => 'ID / Passport',
        'right_to_work' => 'Right to Work / Visa',
        'certificate'   => 'Certificate / Qualification',
        'medical'       => 'Medical',
        'policy'        => 'Signed Policy',
        'other'         => 'Other',
    ];

    /**
     * How long before an expiry somebody should be told.
     *
     * Thirty days, because that is roughly the lead time on renewing a visa or
     * a certification. A warning on the day it expires is not a warning.
     */
    public const WARN_DAYS = 30;

    /** The disk these live on. Private — see the migration. */
    public const DISK = 'local';

    /**
     * The file is deleted with the row.
     *
     * Done here rather than left to the controller because a document can be
     * removed through a cascade — deleting an employee takes their documents —
     * and an orphaned passport scan sitting on disk after the record is gone is
     * the worst possible thing to leave behind.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $document) {
            if ($document->path) {
                Storage::disk(self::DISK)->delete($document->path);
            }
        });
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }

    public function hasExpired(): bool
    {
        return $this->expires_on !== null && $this->expires_on->isPast();
    }

    public function expiresSoon(): bool
    {
        return $this->expires_on !== null
            && ! $this->hasExpired()
            && $this->expires_on->lessThanOrEqualTo(now()->addDays(self::WARN_DAYS));
    }

    /** For the badge on the list. */
    public function getExpiryStateAttribute(): string
    {
        return match (true) {
            $this->expires_on === null => 'none',
            $this->hasExpired()        => 'expired',
            $this->expiresSoon()       => 'soon',
            default                    => 'valid',
        };
    }

    /** A readable size, because 4823718 tells nobody anything. */
    public function getSizeLabelAttribute(): string
    {
        $bytes = (int) $this->size_bytes;

        return match (true) {
            $bytes >= 1048576 => round($bytes / 1048576, 1) . ' MB',
            $bytes >= 1024    => round($bytes / 1024) . ' KB',
            default           => $bytes . ' B',
        };
    }

    /** Documents with a date, due for chasing and not yet chased. */
    public function scopeNeedingExpiryWarning($query, int $companyId)
    {
        return $query->where('company_id', $companyId)
            ->whereNotNull('expires_on')
            ->whereDate('expires_on', '<=', now()->addDays(self::WARN_DAYS)->toDateString())
            // Already told, and the date has not moved since. A nightly job that
            // re-sends every night is one people filter to a folder.
            ->where(fn ($q) => $q->whereNull('expiry_notified_at')
                ->orWhereColumn('expiry_notified_at', '<', 'updated_at'));
    }
}
