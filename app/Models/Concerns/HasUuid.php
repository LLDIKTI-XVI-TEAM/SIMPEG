<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * UUID v4 primary key trait (PRD §5.3).
 *
 * Implements the same contract as Laravel's HasUuids but
 * directly, to avoid nested-trait initialization issues.
 */
trait HasUuid
{
    public function initializeHasUuid(): void
    {
        $this->usesUniqueIds = true;
    }

    public function uniqueIds(): array
    {
        return [$this->getKeyName()];
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid();
    }

    public function getIncrementing(): bool
    {
        return false;
    }

    public function getKeyType(): string
    {
        return 'string';
    }
}
