# MCP server

Tintype includes a [Model Context Protocol](https://modelcontextprotocol.io) server, so an
AI assistant (Claude Code, Cursor, and other MCP clients) can look at builds, view the
new image, baseline and diff for a snapshot, and accept, deny or approve changes.

## Connect

Add the server URL, `https://<your-tintype>/mcp`, to your client. The first time it
connects, the client opens your browser; sign in to Tintype if needed and click
**Authorize**. The client then holds an OAuth token and refreshes it by itself.

Claude Code:

```sh
claude mcp add --transport http tintype https://tintype.example.com/mcp
```

then run `/mcp`, pick `tintype` and choose **Authenticate**.

Cursor, VS Code and other clients with a JSON config:

```json
{
  "mcpServers": {
    "tintype": { "type": "http", "url": "https://tintype.example.com/mcp" }
  }
}
```

The approval page names the client and where the access is sent; only approve a
request you started yourself. Access tokens last a day and refresh for 90 days of use;
after that the client asks you to approve again.

### Headless clients

Scripts and agents that can't open a browser can use a personal access token instead:
**Settings → API tokens** creates one (shown once), sent as
`Authorization: Bearer tu_…`. Revoke it on the same page. Project tokens (`tt_…`, used
by the CLI in CI) are not accepted, so a CI job can't approve its own changes.

### Access

Either way, the client acts as you, with your role in each organization: viewers can
read, and reviewers and above can also decide. Decisions show in each snapshot's
history with the source `mcp`.

### Self-hosting notes

OAuth works with no extra configuration. Tintype generates the token-signing keys on
first use and stores them encrypted (with `APP_KEY`) in the database, so every web
replica shares them. To manage the keys yourself, set `PASSPORT_PRIVATE_KEY` and
`PASSPORT_PUBLIC_KEY`. `APP_URL` must be the public HTTPS URL, since it is the OAuth
issuer. Allowed redirect URIs are set in `config/mcp.php`: any `http(s)` URL, plus the
`cursor://` and `vscode://` schemes.

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
