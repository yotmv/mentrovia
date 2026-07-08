<?php

namespace App\Models;

use Database\Factories\TaskCompletionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $business_task_id
 * @property int $business_id
 * @property Carbon $completed_for
 * @property Carbon $completed_at
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['business_task_id', 'business_id', 'completed_for', 'completed_at', 'notes'])]
class TaskCompletion extends Model
{
    /** @use HasFactory<TaskCompletionFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'completed_for' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<BusinessTask, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(BusinessTask::class, 'business_task_id');
    }

    /**
     * @return BelongsTo<Business, $this>
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
