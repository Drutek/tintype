# Capture determinism

Tintype only works if the same Storybook produces the same pixels on every run.
This file records how we measure that and what we found.

## Method

```
tintype-capture determinism --storybook <storybook-static> --runs 20
```

The tool captures every story/mode N times from scratch and compares each run
with the first. It uses the strictest settings: threshold 0 and anti-aliased
pixels counted. A snapshot is **consistent** when every run is byte-identical, or
when the runs differ only inside declared ignore regions.

Render-error snapshots (Storybook's red error overlay) are excluded. The
overlay prints a stack trace containing the Storybook URL and port, which
changes every run. The service never diffs error snapshots, so excluding them
loses nothing.

The fixture (`fixtures/sample-storybook`) contains 26 stories built to trip up
capture:
- web fonts, emoji and CJK/RTL text;
- infinite CSS animation, `pauseAnimationAtEnd`, and a transition triggered by
  a timer;
- `Date.now()` / `toLocaleString()`, `Math.random`, and `crypto` random values
  inside an ignore region;
- lazy images below the fold, SVG, gradients with blur;
- a portal modal, a fullscreen long scroll, modes (viewport + theme), legacy
  `viewports`;
- `play` functions (one passing, one failing), and a story that throws.

## Results

| Date | Environment | Runs | Result |
|---|---|---|---|
| 2026-10-16 | `tintype/capture-chromium:dev` (linux/amd64 under Rosetta, Apple `container` 1.1.0), Chromium 156.0.8078.4 | 20 | **27/27 comparable snapshots identical.** The 2 others: 1 render-error overlay (excluded) and 1 that differed only inside an ignore region (masked). |
| 2026-10-16 | `tintype/capture-firefox:dev` (linux/amd64, Rosetta), Firefox 157.0 | 5 | **28/28 identical** (1 render error excluded, 1 masked-only) |
| 2026-10-16 | `tintype/capture-webkit:dev` (linux/amd64, Rosetta), WebKit 27.2 | 5 | **31/31 identical** (fixture now includes accessibility stories) |
| 2026-10-16 | Native macOS arm64, Chromium / Firefox / WebKit | 3 each | All identical (dev only, not a supported baseline environment) |
| 2026-10-16 | GitHub Actions `ubuntu-latest`, **native x86_64**, Docker, Chromium 156.0.8078.4 | 20 | **31/31 identical** (run 38966711560); re-runs on every capture change |

## What makes it deterministic

The full list is in `packages/capture/src/capture.ts` and `page-scripts.ts`.

- **Pinned environment:**
  - the base image is pinned by digest, and fonts come from a dated Ubuntu
    snapshot (`apt-get --snapshot`);
  - fontconfig is forced to grayscale anti-aliasing with no hinting;
  - generic families are aliased to the bundled fonts.
- **Chromium flags:** software GL (SwiftShader), `--font-render-hinting=none`,
  `--disable-lcd-text`, `--disable-font-subpixel-positioning`, sRGB colour
  profile, partial raster disabled.
- **Time and randomness:**
  - `Date` is frozen with Playwright's clock (`setFixedTime`), so timers still
    run;
  - `Math.random` is a mulberry32 PRNG seeded from story id + mode;
  - locale and timezone are fixed.
- **Animations:**
  - CSS transitions get zero duration;
  - CSS/Web animations are paused at frame 0, or finished when
    `pauseAnimationAtEnd` is set;
  - videos are paused at frame 0.
- **Waiting before the shot:**
  - Storybook's `storyFinished` event, which includes `play`;
  - `document.fonts.ready`, plus every image loaded and decoded (lazy images
    are made eager);
  - 200 ms of network quiet, then the `delay` parameter, then two animation
    frames.
- **Network:** external requests are blocked unless allow-listed. Allow-listed
  responses are cached per session, so retakes get identical bytes.

## Known limits

- **`performance.now()` is not frozen.** Freezing it would break code that
  measures elapsed time and animation frames. Stories must not render it, or
  anything derived from load timing. Wrap such output in an ignore region
  (`.tintype-ignore`).

- **Rosetta vs native x86:** on 2026-10-16, a capture in the amd64 image under
  Rosetta (Apple `container`) was **byte-identical** to the native x86_64 CI
  capture for all 30 comparable snapshots. The 2 that differed were the
  render-error overlay (it prints a URL) and the random text inside an ignore
  region. This is a single data point, not a guarantee. Workers report their architecture, and the server refuses non-x86
  results unless `TINTYPE_ALLOW_ANY_ARCH=true` (development only).
- **Upgrades:** a new browser or font package changes pixels. Bump the capture
  image's major version; builds then show "capture environment changed" and an
  admin can run *Rebaseline*.
