<?php

namespace App\Models;

use App\Enums\BusinessProfileVersionSource;
use Database\Factories\BusinessProfileVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $revision
 * @property string $fingerprint
 * @property int $schema_version
 * @property BusinessProfileVersionSource $source
 * @property list<string> $sections
 * @property list<string> $changed_field_keys
 * @property array<string, mixed> $snapshot
 * @property array<string, mixed>|null $source_metadata
 */
#[Fillable([
    'business_id', 'revision', 'fingerprint', 'schema_version', 'source',
    'sections', 'changed_field_keys', 'snapshot', 'source_metadata', 'created_by_user_id',
])]
class BusinessProfileVersion extends Model
{
    /** @use HasFactory<BusinessProfileVersionFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $attributes = ['schema_version' => 1];

    protected $hidden = ['snapshot', 'source_metadata'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'schema_version' => 'integer',
            'source' => BusinessProfileVersionSource::class,
            'sections' => 'array',
            'changed_field_keys' => 'array',
            'snapshot' => 'encrypted:array',
            'source_metadata' => 'encrypted:array',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Business profile versions are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Business profile versions are immutable.'));
    }

    /** @return BelongsTo<Business, $this> */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
