<?php

namespace App\Services\Scm;

use App\Models\Build;

/** A source-control provider that can show build state on a commit. */
interface CommitStatusReporter
{
    public function supports(Build $build): bool;

    public function report(Build $build): void;
}
