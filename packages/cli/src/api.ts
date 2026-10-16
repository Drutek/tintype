export interface UploadTarget {
  method: string;
  url: string;
  headers: Record<string, string>;
  authenticated?: boolean;
}

export interface BuildSummary {
  id: number;
  number: number;
  status: string;
  exitCode: number;
  url: string;
  storybookUrl: string;
  error: string | null;
  baseline: { strategy: string | null; buildIds: number[] | null };
  progress: { jobs: number; done: number };
  counts: Record<string, number>;
  autoAccepted: string | null;
  changes: { storyId: string; title: string; name: string; mode: string; browser: string; status: string; review: string | null; error: string | null }[];
}

export class ApiError extends Error {
  constructor(message: string, readonly status: number) {
    super(message);
  }
}

export class Api {
  constructor(private readonly server: string, private readonly token: string) {}

  private url(path: string): string {
    return `${this.server.replace(/\/$/, '')}/api/v1/${path}`;
  }

  async request<T>(method: string, path: string, body?: unknown): Promise<T> {
    let lastError: unknown;
    for (let attempt = 0; attempt < 4; attempt++) {
      try {
        const res = await fetch(this.url(path), {
          method,
          headers: { Authorization: `Bearer ${this.token}`, Accept: 'application/json', 'Content-Type': 'application/json' },
          body: body === undefined ? undefined : JSON.stringify(body),
        });
        const text = await res.text();
        if (!res.ok) {
          let message = text;
          try {
            message = (JSON.parse(text) as { message?: string }).message ?? text;
          } catch {
            /* not json */
          }
          if (res.status >= 500 && attempt < 3) throw new ApiError(message, res.status);
          throw new ApiError(`${method} ${path} → ${res.status}: ${message}`, res.status);
        }
        return (text ? JSON.parse(text) : {}) as T;
      } catch (e) {
        lastError = e;
        if (e instanceof ApiError && e.status < 500) throw e;
        await new Promise((r) => setTimeout(r, 1000 * 2 ** attempt));
      }
    }
    throw lastError;
  }

  createBuild(body: unknown) {
    return this.request<{ id: number; number: number; url: string; upload: UploadTarget; statusUrl: string }>('POST', 'builds', body);
  }

  baselineCommits(body: unknown) {
    return this.request<{ commits: string[]; storybookStats: boolean }>('POST', 'baseline-commits', body);
  }

  async upload(target: UploadTarget, bytes: Uint8Array): Promise<void> {
    const headers = { ...target.headers, ...(target.authenticated ? { Authorization: `Bearer ${this.token}` } : {}) };
    for (let attempt = 0; ; attempt++) {
      const res = await fetch(target.url, { method: target.method, headers, body: bytes as unknown as BodyInit }).catch((e: Error) => e);
      if (!(res instanceof Error) && res.ok) return;
      if (attempt >= 3) throw new Error(`Upload failed: ${res instanceof Error ? res.message : `${res.status} ${await res.text()}`}`);
      await new Promise((r) => setTimeout(r, 1000 * 2 ** attempt));
    }
  }

  uploaded(id: number) {
    return this.request<{ ok: boolean }>('POST', `builds/${id}/uploaded`, {});
  }

  status(id: number) {
    return this.request<BuildSummary>('GET', `builds/${id}`);
  }
}
