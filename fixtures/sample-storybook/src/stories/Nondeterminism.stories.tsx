import type { Meta, StoryObj } from '@storybook/react-vite';

const meta = { title: 'Edge Cases/Nondeterminism' } satisfies Meta;
export default meta;

export const CurrentDate: StoryObj = {
  render: () => (
    <div>
      <p>Now: {new Date().toISOString()}</p>
      <p>Locale: {new Date().toLocaleString()}</p>
    </div>
  ),
};

export const RandomBars: StoryObj = {
  render: () => (
    <div style={{ display: 'flex', alignItems: 'flex-end', gap: 4, height: 100 }}>
      {Array.from({ length: 20 }, (_, i) => (
        <div key={i} style={{ width: 10, height: Math.round(Math.random() * 100), background: '#7c3aed' }} />
      ))}
    </div>
  ),
};

export const IgnoredRegion: StoryObj = {
  render: () => (
    <div>
      <p>Stable text</p>
      <p className="tintype-ignore" style={{ background: '#fee2e2' }}>
        Ignored: {String(crypto.getRandomValues(new Uint32Array(1))[0])}
      </p>
    </div>
  ),
};
