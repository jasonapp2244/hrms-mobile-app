<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One step on one person's joining or leaving list (A3.12). */
class EmployeeChecklistItem extends Model
{
    protected $fillable = [
        'company_id', 'employee_id', 'checklist_template_id', 'kind',
        'title', 'description', 'owner', 'due_on',
        'completed_at', 'completed_by_user_id', 'note', 'position',
    ];

    protected $casts = [
        'due_on'       => 'date',
        'completed_at' => 'datetime',
        'position'     => 'integer',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ChecklistTemplate::class, 'checklist_template_id');
    }

    public function isDone(): bool
    {
        return $this->completed_at !== null;
    }

    /**
     * Outstanding and past its date.
     *
     * A completed item is never overdue however late it was done — the point of
     * the flag is to show what still needs chasing, and a permanently red tick
     * against a finished task trains people to ignore the colour.
     */
    public function isOverdue(): bool
    {
        return ! $this->isDone()
            && $this->due_on !== null
            && $this->due_on->isPast();
    }

    public function scopeOutstanding($query)
    {
        return $query->whereNull('completed_at');
    }

    public function scopeOfKind($query, string $kind)
    {
        return $query->where('kind', $kind);
    }
}
