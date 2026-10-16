<?php

namespace App\Services\Scm;

use App\Enums\BuildStatus;
use App\Models\Build;

/** Provider-neutral state + description for a build. */
class StatusPresenter
{
    /** @return array{state: 'pending'|'success'|'failure'|'error', description: string} */
    public static function present(Build $build): array
    {
        if ($build->status->isInProgress()) {
            return ['state' => 'pending', 'description' => 'Capturing snapshots…'];
        }
        $review = $build->changed_count + $build->new_count;

        return match ($build->status) {
            BuildStatus::Passed => ['state' => 'success', 'description' => 'No visual changes'],
            BuildStatus::Accepted => ['state' => 'success', 'description' => 'All changes accepted'],
            BuildStatus::Pending => ['state' => 'pending', 'description' => "{$review} ".($review === 1 ? 'change needs' : 'changes need').' review'],
            BuildStatus::Denied => ['state' => 'failure', 'description' => 'Changes denied'],
            BuildStatus::Broken => ['state' => 'failure', 'description' => "{$build->error_count} stories errored"],
            default => ['state' => 'error', 'description' => 'Build failed'],
        };
    }
}
