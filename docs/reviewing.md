# Reviewing changes

## Builds

Each CLI run creates a **build**. Its status is one of:
- `capturing`;
- `pending`: changes are waiting for review;
- `accepted`;
- `denied`;
- `passed`: no changes;
- `broken`: a story errored;
- `failed`: an infrastructure error.

Inside a build, every story × browser × mode is a **snapshot**:

| Snapshot | Meaning |
|---|---|
| New | No baseline yet; needs review |
| Changed | Differs from the baseline beyond the threshold, and the retakes reproduced the difference; needs review |
| Unchanged | Matches the baseline (byte-identical, or within the threshold) |
| Inconsistent | Retakes disagreed with each other, so the story is unstable; not counted as a change |
| Error | Render error, failed `play` function, or timeout |
| Removed | In the baseline but no longer in the Storybook |

## Baselines

A snapshot's baseline is the most recent **accepted** snapshot of the same
story, browser and mode, taken from the nearest builds in the commit's git
ancestry. In practice:
- Accepting on a feature branch updates the baseline for that branch only.
- When the branch merges, its accepted baselines come along.
- Denied or unreviewed changes never move the baseline.
- Squash and rebase merges are recognised through GitHub PR webhooks, so the
  PR head's baselines carry over.
- Without enough history (shallow clones), Tintype falls back to the latest
  build on the same branch, then on the default branch. The build header shows
  which build(s) and which strategy were used.

## Review UI

| Key | Action |
|---|---|
| `j` / `k` | Next / previous snapshot |
| `a` / `d` / `u` | Accept / deny / undo |
| `1`–`5` | Diff, side by side, swipe, baseline, new |
| `s` | Spotlight the changed regions |
| `f` | Toggle between fit and 100% |

**Accept all** and **Deny all** apply to every pending snapshot in the build.
Each snapshot keeps a review history and a comment thread.

## UI Review (pull requests)

**Pull requests → #N** shows every visual difference between the PR's latest
build and the base-branch build it forked from. Reviewers can approve, request
changes or comment, which drives a separate **UI Review** GitHub check.

Approving a PR does not by itself accept the snapshots in its build, so the
**Visual Tests** check can stay open. When the head build has snapshots waiting
for a decision, the PR page says so, and the button becomes **Approve & accept
all**. That approves the PR and accepts those snapshots in one step, which
clears both checks.

## Accessibility

Every story is checked with axe-core after capture. Violations appear on the
snapshot, and the build counts how many are **new** compared with the
baseline. Turn the checks off per project, or per story with
`parameters.tintype.a11y = false`.
