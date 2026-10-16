<?php

namespace App\Services;

use App\Enums\BuildStatus;
use App\Enums\ReviewState;
use App\Events\BuildUpdated;
use App\Models\Build;
use App\Models\Review;
use App\Models\Snapshot;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ReviewService
{
    public function __construct(private readonly BuildFinalizer $finalizer) {}

    /**
     * Apply a decision to snapshots of one build.
     *
     * @param  iterable<Snapshot>  $snapshots
     */
    public function decide(Build $build, iterable $snapshots, string $decision, ?User $user, string $source = 'user'): int
    {
        if (! in_array($decision, ['accepted', 'denied', 'pending'], true)) {
            throw new InvalidArgumentException("Unknown decision {$decision}");
        }
        if (! in_array($build->status, [BuildStatus::Pending, BuildStatus::Accepted, BuildStatus::Denied, BuildStatus::Broken], true)) {
            throw new InvalidArgumentException('Build is not reviewable');
        }
        $state = ReviewState::from($decision);
        $count = 0;
        DB::transaction(function () use ($build, $snapshots, $state, $user, $source, &$count) {
            foreach ($snapshots as $snap) {
                if ($snap->build_id !== $build->id || $snap->review_state === null || $snap->review_state === $state) {
                    continue;
                }
                $snap->update([
                    'review_state' => $state,
                    'reviewed_by' => $state === ReviewState::Pending ? null : $user?->id,
                    'reviewed_at' => $state === ReviewState::Pending ? null : now(),
                ]);
                Review::create([
                    'snapshot_id' => $snap->id,
                    'user_id' => $user?->id,
                    'decision' => $state === ReviewState::Pending ? 'reset' : $state->value,
                    'source' => $source,
                ]);
                $count++;
            }
            $build->refresh();
            $build->forceFill(['status' => $this->finalizer->statusFor($build)])->save();
        });
        if ($count) {
            BuildUpdated::dispatch($build);
            $this->finalizer->afterStatusChange($build);
        }

        return $count;
    }
}
