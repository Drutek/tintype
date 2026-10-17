// Generates a large, deterministic Storybook for throughput benchmarks:
// COMPONENTS components x STORIES stories each (default 40 x 8 = 320 stories).
import { mkdirSync, rmSync, writeFileSync } from 'node:fs';

const COMPONENTS = Number(process.env.BENCH_COMPONENTS ?? 40);
const STORIES = Number(process.env.BENCH_STORIES ?? 8);
const palette = ['#2563eb', '#16a34a', '#dc2626', '#9333ea', '#ea580c', '#0891b2', '#4f46e5', '#be123c'];

rmSync('src', { recursive: true, force: true });
mkdirSync('src', { recursive: true });
for (let c = 0; c < COMPONENTS; c++) {
  const name = `Widget${String(c).padStart(2, '0')}`;
  const stories = Array.from({ length: STORIES }, (_, s) => {
    const color = palette[(c + s) % palette.length];
    return `export const Variant${s}: Story = { args: { title: '${name} variant ${s}', color: '${color}', items: ${2 + ((c + s) % 5)}, dense: ${s % 2 === 0} } };`;
  }).join('\n');
  writeFileSync(`src/${name}.stories.tsx`, `import type { Meta, StoryObj } from '@storybook/react-vite';

function ${name}({ title, color, items, dense }: { title: string; color: string; items: number; dense: boolean }) {
  return (
    <section style={{ border: '1px solid #e5e7eb', borderRadius: 12, padding: dense ? 8 : 16, display: 'grid', gap: 8 }}>
      <h3 style={{ margin: 0, color }}>{title}</h3>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(140px, 1fr))', gap: 8 }}>
        {Array.from({ length: items }, (_, i) => (
          <div key={i} style={{ padding: 12, borderRadius: 8, background: color + '1a', border: '1px solid ' + color }}>
            <strong>Item {i + 1}</strong>
            <p style={{ margin: '4px 0 0', fontSize: 13 }}>Lorem ipsum dolor sit amet, consectetur.</p>
          </div>
        ))}
      </div>
      <button type="button" style={{ justifySelf: 'start', background: color, color: '#fff', border: 0, borderRadius: 6, padding: '6px 12px' }}>Action</button>
    </section>
  );
}

const meta = { title: 'Benchmark/${name}', component: ${name} } satisfies Meta<typeof ${name}>;
export default meta;
type Story = StoryObj<typeof meta>;

${stories}
`);
}
console.log(`Generated ${COMPONENTS * STORIES} stories in ${COMPONENTS} files`);
