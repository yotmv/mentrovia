<?php

namespace App\Services;

use App\Enums\BusinessProfileSection;
use App\Enums\BusinessProfileVersionSource;
use App\Models\Business;
use App\Models\BusinessProfileVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

final class BusinessProfileVersionService
{
    public function __construct(
        private BusinessProfileSnapshot $snapshots,
        private BusinessProfileFingerprint $fingerprints,
    ) {}

    public function issue(Business $business): string
    {
        return $this->fingerprints->make($this->snapshots->capture($business));
    }

    public function ensureBaselineLocked(Business $business): BusinessProfileVersion
    {
        $this->requiresTransaction();
        $existing = BusinessProfileVersion::query()
            ->whereBelongsTo($business)
            ->orderByDesc('revision')
            ->lockForUpdate()
            ->first();

        if ($existing instanceof BusinessProfileVersion) {
            $business->unsetRelation('profileAnswers');
            $liveFingerprint = $this->issue($business);

            if (! hash_equals($existing->fingerprint, $liveFingerprint)) {
                throw new LogicException('The live business profile does not match its latest immutable version.');
            }

            if ($business->profile_revision !== $existing->revision || $business->profile_fingerprint !== $existing->fingerprint) {
                $business->forceFill([
                    'profile_revision' => $existing->revision,
                    'profile_fingerprint' => $existing->fingerprint,
                ])->save();
            }

            return $existing;
        }

        return $this->createLocked($business, BusinessProfileVersionSource::Backfill, null, [], [], null);
    }

    /**
     * @param  list<string>  $changedFieldKeys
     * @param  list<BusinessProfileSection|string>  $sections
     * @param  array<string, mixed>|null  $metadata
     */
    public function recordLocked(
        Business $business,
        BusinessProfileVersionSource $source,
        ?User $creator,
        array $changedFieldKeys,
        array $sections,
        ?array $metadata = null,
    ): BusinessProfileVersion {
        $this->requiresTransaction();
        $snapshot = $this->snapshots->capture($business);
        $fingerprint = $this->fingerprints->make($snapshot);
        $latest = BusinessProfileVersion::query()
            ->whereBelongsTo($business)
            ->orderByDesc('revision')
            ->lockForUpdate()
            ->first();

        if ($latest instanceof BusinessProfileVersion && hash_equals($latest->fingerprint, $fingerprint)) {
            if ($business->profile_revision !== $latest->revision || $business->profile_fingerprint !== $latest->fingerprint) {
                $business->forceFill([
                    'profile_revision' => $latest->revision,
                    'profile_fingerprint' => $latest->fingerprint,
                ])->save();
            }

            return $latest;
        }

        return $this->createLocked($business, $source, $creator, $changedFieldKeys, $sections, $metadata, $snapshot, $fingerprint);
    }

    /**
     * @param  list<string>  $changedFieldKeys
     * @param  list<BusinessProfileSection|string>  $sections
     * @param  array<string, mixed>|null  $metadata
     * @param  array<string, mixed>|null  $snapshot
     */
    private function createLocked(
        Business $business,
        BusinessProfileVersionSource $source,
        ?User $creator,
        array $changedFieldKeys,
        array $sections,
        ?array $metadata,
        ?array $snapshot = null,
        ?string $fingerprint = null,
    ): BusinessProfileVersion {
        $snapshot ??= $this->snapshots->capture($business);
        $fingerprint ??= $this->fingerprints->make($snapshot);
        $nextRevision = ((int) BusinessProfileVersion::query()
            ->whereBelongsTo($business)
            ->lockForUpdate()
            ->max('revision')) + 1;
        $sectionValues = collect($sections)
            ->map(fn (BusinessProfileSection|string $section): string => $section instanceof BusinessProfileSection ? $section->value : $section)
            ->unique()
            ->sort()
            ->values()
            ->all();

        $version = BusinessProfileVersion::query()->create([
            'business_id' => $business->id,
            'revision' => $nextRevision,
            'fingerprint' => $fingerprint,
            'schema_version' => BusinessProfileSnapshot::SCHEMA_VERSION,
            'source' => $source,
            'sections' => $sectionValues,
            'changed_field_keys' => collect($changedFieldKeys)->unique()->sort()->values()->all(),
            'snapshot' => $snapshot,
            'source_metadata' => $metadata,
            'created_by_user_id' => $creator?->id,
        ]);

        $business->forceFill([
            'profile_revision' => $version->revision,
            'profile_fingerprint' => $version->fingerprint,
        ])->save();

        return $version;
    }

    private function requiresTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Business profile versioning requires a database transaction.');
        }
    }
}
