// Classic JSX: only import 'react', which Storybook's manager provides (avoids a second React copy).
import React, { useEffect, useState } from 'react';
import { addons, types, useChannel, useStorybookState } from 'storybook/manager-api';
import { ADDON_ID, EVENTS, PANEL_ID, type StoryStatusResponse } from './constants.js';

const LABELS: Record<string, string> = {
  new: 'New',
  unchanged: 'No changes',
  changed: 'Changed',
  error: 'Error',
  skipped: 'Skipped',
  removed: 'Removed',
  inconsistent: 'Inconsistent',
};

function Panel({ active }: { active: boolean }) {
  const { storyId } = useStorybookState();
  const [status, setStatus] = useState<{ data?: StoryStatusResponse; error?: string } | null>(null);
  const [log, setLog] = useState<string[]>([]);
  const [running, setRunning] = useState(false);
  const [view, setView] = useState<'new' | 'baseline' | 'diff'>('new');

  const emit = useChannel({
    [EVENTS.STATUS_RESULT]: (msg: { storyId: string; data?: StoryStatusResponse; error?: string }) => {
      if (msg.storyId === storyId) setStatus(msg);
    },
    [EVENTS.RUN_PROGRESS]: ({ line }: { line: string }) => setLog((l) => [...l.slice(-200), line]),
    [EVENTS.RUN_DONE]: ({ code, error }: { code: number; error?: string }) => {
      setRunning(false);
      setLog((l) => [...l, error ?? `Finished (exit ${code})`]);
      emit(EVENTS.STATUS_REQUEST, { storyId });
    },
  }, [storyId]);

  useEffect(() => {
    if (active && storyId) emit(EVENTS.STATUS_REQUEST, { storyId });
  }, [active, storyId]);

  if (!active) return null;
  const build = status?.data?.build;
  const snaps = status?.data?.snapshots ?? [];

  return (
    <div style={{ padding: 12, fontFamily: 'inherit', fontSize: 13, display: 'grid', gap: 12 }}>
      <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
        <strong>Visual tests</strong>
        {build && (
          <a href={build.url} target="_blank" rel="noreferrer">
            Build {build.number} · {build.status} · {build.branch}
          </a>
        )}
        <button
          type="button"
          style={{ marginLeft: 'auto' }}
          disabled={running}
          onClick={() => {
            setRunning(true);
            setLog([]);
            emit(EVENTS.RUN);
          }}
        >
          {running ? 'Running…' : 'Run visual tests'}
        </button>
      </div>
      {status?.error && <div style={{ color: '#b91c1c' }}>{status.error}</div>}
      {!status && <div>Loading…</div>}
      {status?.data && !build && <div>No builds on this branch yet. Run visual tests to create one.</div>}
      {build && !snaps.length && <div>This story has no snapshot in build {build.number}.</div>}
      {snaps.map((s) => (
        <div key={s.id} style={{ border: '1px solid rgba(0,0,0,.1)', borderRadius: 6, padding: 8, display: 'grid', gap: 6 }}>
          <div style={{ display: 'flex', gap: 8 }}>
            <span>
              {s.mode} · {s.browser}
            </span>
            <strong>{s.review === 'accepted' ? 'Accepted' : s.review === 'denied' ? 'Denied' : LABELS[s.status]}</strong>
            {s.diffRatio != null && <span>{(s.diffRatio * 100).toFixed(2)}% changed</span>}
            {s.a11y?.length ? <span style={{ color: '#b91c1c' }}>{s.a11y.length} a11y issues</span> : null}
            <a style={{ marginLeft: 'auto' }} href={s.reviewUrl} target="_blank" rel="noreferrer">
              Review
            </a>
          </div>
          {s.error && <pre style={{ whiteSpace: 'pre-wrap', color: '#b91c1c', margin: 0 }}>{s.error}</pre>}
          {s.baseline && (
            <div style={{ display: 'flex', gap: 4 }}>
              {(['new', 'baseline', 'diff'] as const).map((v) => (
                <button key={v} type="button" disabled={v === 'diff' && !s.diff} onClick={() => setView(v)} style={{ fontWeight: view === v ? 700 : 400 }}>
                  {v}
                </button>
              ))}
            </div>
          )}
          {(() => {
            const src = view === 'baseline' ? s.baseline : view === 'diff' ? s.diff : s.image;
            return src ? <img src={src} alt={`${s.mode} ${view}`} style={{ maxWidth: '100%', border: '1px solid rgba(0,0,0,.1)', background: view === 'diff' ? '#fff' : undefined }} /> : null;
          })()}
        </div>
      ))}
      {log.length > 0 && <pre style={{ maxHeight: 200, overflow: 'auto', background: 'rgba(0,0,0,.05)', padding: 8, margin: 0 }}>{log.join('\n')}</pre>}
    </div>
  );
}

addons.register(ADDON_ID, () => {
  addons.add(PANEL_ID, {
    type: types.PANEL,
    title: 'Visual tests',
    render: ({ active }) => <Panel active={Boolean(active)} />,
  });
});
