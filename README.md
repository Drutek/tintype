# Tintype

Open-source, self-hosted visual regression testing for Storybook.

Every screenshot is taken server-side, in a pinned Linux x86_64 container, so
the pixels are reproducible. Changes are diffed against git-aware baselines
and reviewed in a web UI, and the result is reported to GitHub as a check run.

> Status: all planned phases are complete. Supports Storybook 9 and 10. See
> [docs/progress.md](docs/progress.md) for what has been verified,
> [docs/determinism.md](docs/determinism.md) for capture-consistency results and
> [docs/benchmark.md](docs/benchmark.md) for throughput.

## Pieces

| Path | What |
|---|---|
| `apps/web` | Laravel 13 + Inertia/React control plane: API, review UI, baselines, GitHub App, [MCP server](docs/mcp.md) |
| `packages/capture` | Playwright capture engine and worker (`tintype-capture`) with its Docker images |
| `packages/diff` | Pixel diffing (pixelmatch), hash short-circuit, ignore regions, change clustering |
| `packages/cli` | `tintype` CLI for CI: builds and uploads Storybook, then waits for the result |
| `fixtures/sample-storybook` | Edge-case Storybook used for determinism and e2e tests |
| `deploy/` | docker compose and AWS Terraform |

## License

MIT © Drutek
