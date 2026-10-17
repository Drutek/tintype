# MCP server

Tintype includes a [Model Context Protocol](https://modelcontextprotocol.io) server, so an
AI assistant (Claude Code, Cursor, and other MCP clients) can look at builds, view the
new image, baseline and diff for a snapshot, and accept, deny or approve changes.

## Connect

1. In Tintype, open **Settings → API tokens** and create a token. It is shown once.
2. Add the server to your client. The endpoint is `https://<your-tintype>/mcp`, with the
   token sent as a bearer header.

Claude Code:

```sh
claude mcp add --transport http tintype https://tintype.example.com/mcp \
  --header "Authorization: Bearer tu_xxxxxxxx_…"
```

Clients with a JSON config:

```json
{
  "mcpServers": {
    "tintype": {
      "type": "http",
      "url": "https://tintype.example.com/mcp",
      "headers": { "Authorization": "Bearer tu_xxxxxxxx_…" }
    }
  }
}
```

The client acts as you, with your role in each organization: viewers can read, and
reviewers and above can also decide. Decisions show in each snapshot's history with the
source `mcp`. Revoke a token on the same settings page. Project tokens (`tt_…`, used by
the CLI in CI) are not accepted here.

## Tools

| Tool | What it does | Role |
|---|---|---|
| `list-projects` | Organizations and projects you can access, with the latest build | any |
| `list-builds` | Recent builds; filter by branch or status | any |
| `get-build` | A build's status, counts and the snapshots that need attention | any |
| `get-snapshot` | One snapshot with its new, baseline and diff images | any |
| `review-snapshots` | Accept, deny or reset snapshots (ids, a component, or all pending) | reviewer |
| `comment-on-build` | Comment on a build or a snapshot | reviewer |
| `list-pull-requests` | Pull requests with their UI Review status | any |
| `get-pull-request` | Everything a PR changes visually, and how many snapshots are still pending | any |
| `review-pull-request` | Approve, request changes or comment; `accept_pending` also accepts the head build's pending snapshots | reviewer |

Projects are named `organization/project` (the slugs in the URL); the project slug alone
works when it is unambiguous.

## Example prompts

- "What changed visually in PR #12 of drutek/drutek-ds? Show me the diffs."
- "Accept the Hero changes in build 42 if they only affect padding."
- "Approve the UI review for PR #12 and accept everything pending."

Accepting makes the new images the baseline for later builds, so have the assistant
look at the images (`get-snapshot`) before accepting.
