import type { Meta, StoryObj } from '@storybook/react-vite';

const meta = { title: 'Foundations/Typography' } satisfies Meta;
export default meta;

export const WebFont: StoryObj = {
  render: () => (
    <div>
      <h1 style={{ fontWeight: 700, margin: 0 }}>The quick brown fox</h1>
      <p style={{ fontWeight: 400 }}>jumps over the lazy dog. 0123456789 — “quotes” &amp; ligatures fi fl</p>
      <p style={{ fontFamily: 'serif' }}>Serif fallback text</p>
      <p style={{ fontFamily: 'monospace' }}>const mono = true;</p>
    </div>
  ),
};

export const Emoji: StoryObj = {
  render: () => <p style={{ fontSize: 32 }}>🎨 🚀 ✅ 👍🏽 🇬🇧</p>,
};

export const Unicode: StoryObj = {
  render: () => (
    <div style={{ fontSize: 18 }}>
      <p>Ελληνικά · Русский · 日本語 · 中文 · 한국어 · العربية · עברית</p>
    </div>
  ),
};
