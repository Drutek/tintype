import { createReadStream } from 'node:fs';
import { stat } from 'node:fs/promises';
import { createServer, type Server } from 'node:http';
import { extname, join, normalize, resolve } from 'node:path';
import type { StoryIndexEntry } from './types.js';

const MIME: Record<string, string> = {
  '.html': 'text/html; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.mjs': 'text/javascript; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.svg': 'image/svg+xml',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg',
  '.gif': 'image/gif',
  '.webp': 'image/webp',
  '.avif': 'image/avif',
  '.ico': 'image/x-icon',
  '.woff': 'font/woff',
  '.woff2': 'font/woff2',
  '.ttf': 'font/ttf',
  '.otf': 'font/otf',
  '.mp4': 'video/mp4',
  '.webm': 'video/webm',
  '.wasm': 'application/wasm',
  '.map': 'application/json',
};

/** Serve a static Storybook directory on an ephemeral localhost port. */
export async function serveDirectory(dir: string): Promise<{ url: string; close: () => Promise<void>; server: Server }> {
  const root = resolve(dir);
  const server = createServer(async (req, res) => {
    try {
      const path = decodeURIComponent(new URL(req.url ?? '/', 'http://x').pathname);
      let file = normalize(join(root, path));
      if (!file.startsWith(root)) {
        res.writeHead(403).end();
        return;
      }
      let st = await stat(file).catch(() => null);
      if (st?.isDirectory()) {
        file = join(file, 'index.html');
        st = await stat(file).catch(() => null);
      }
      if (!st) {
        res.writeHead(404).end('Not found');
        return;
      }
      res.writeHead(200, {
        'content-type': MIME[extname(file).toLowerCase()] ?? 'application/octet-stream',
        'content-length': st.size,
        'cache-control': 'no-cache',
      });
      createReadStream(file).pipe(res);
    } catch {
      res.writeHead(500).end();
    }
  });
  await new Promise<void>((r) => server.listen(0, '127.0.0.1', r));
  const addr = server.address();
  const port = typeof addr === 'object' && addr ? addr.port : 0;
  return {
    url: `http://127.0.0.1:${port}/`,
    server,
    close: () => new Promise((r) => server.close(() => r())),
  };
}

/** Fetch and parse a Storybook `index.json`, returning only story entries. */
export async function loadStoryIndex(storybookUrl: string): Promise<StoryIndexEntry[]> {
  const base = storybookUrl.endsWith('/') ? storybookUrl : `${storybookUrl}/`;
  const res = await fetch(`${base}index.json`);
  if (!res.ok) throw new Error(`Could not load ${base}index.json (${res.status})`);
  const json = (await res.json()) as { v: number; entries?: Record<string, StoryIndexEntry>; stories?: Record<string, StoryIndexEntry> };
  const entries = Object.values(json.entries ?? json.stories ?? {});
  return entries.filter((e) => (e.type ?? 'story') === 'story');
}

/** Minimal glob: `*` matches any run of characters. Matches id or "Title / Name". */
export function matchesStory(entry: StoryIndexEntry, patterns: string[] | undefined): boolean {
  if (!patterns?.length) return true;
  const candidates = [entry.id, `${entry.title}/${entry.name}`];
  return patterns.some((p) => {
    const re = new RegExp(`^${p.split('*').map((s) => s.replace(/[.+?^${}()|[\]\\]/g, '\\$&')).join('.*')}$`, 'i');
    return candidates.some((c) => re.test(c));
  });
}
