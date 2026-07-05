<?php

namespace App\Services;

use App\Models\SourceTerm;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Lightweight term claims: a mapper claims a term while working it so others
 * don't collide. Claims auto-expire (config('comet.claim_ttl_hours')); an
 * expired claim is treated as unclaimed and can be taken over.
 */
class ClaimService
{
    private function ttlHours(): int
    {
        return (int) config('comet.claim_ttl_hours', 4);
    }

    public function staleBefore(): Carbon
    {
        return now()->subHours($this->ttlHours());
    }

    /** Claim for $user unless a *fresh* claim by someone else exists. Returns held-by. */
    public function claim(SourceTerm $term, string $user): string
    {
        $stale = $this->staleBefore();
        if ($term->claimed_by && $term->claimed_by !== $user && $term->claimed_at && $term->claimed_at->gt($stale)) {
            return $term->claimed_by; // someone else holds a fresh claim
        }

        $term->update(['claimed_by' => $user, 'claimed_at' => now()]);

        return $user;
    }

    public function release(SourceTerm $term, string $user): void
    {
        if ($term->claimed_by === $user) {
            $term->update(['claimed_by' => null, 'claimed_at' => null]);
        }
    }

    /** True if held by someone other than $user with a non-expired claim. */
    public function heldByOther(SourceTerm $term, string $user): bool
    {
        return $term->claimed_by
            && $term->claimed_by !== $user
            && $term->claimed_at
            && $term->claimed_at->gt($this->staleBefore());
    }

    public function inFlightCount(): int
    {
        return DB::table('source_terms')
            ->whereNotNull('claimed_by')
            ->where('claimed_at', '>', $this->staleBefore())
            ->count();
    }
}
