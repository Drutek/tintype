import type { Meta, StoryObj } from '@storybook/react-vite';
import { createPortal } from 'react-dom';
import { Button } from '../components/Button';

const meta = { title: 'Edge Cases/Layout' } satisfies Meta;
export default meta;

export const ModalPortal: StoryObj = {
  render: () =>
    createPortal(
      <div style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,.5)', display: 'grid', placeItems: 'center' }}>
        <div style={{ background: '#fff', padding: 24, borderRadius: 8, width: 280 }}>
          <h3 style={{ marginTop: 0 }}>Portal modal</h3>
          <Button>OK</Button>
        </div>
      </div>,
      document.body,
    ),
};

export const LongScrollFullscreen: StoryObj = {
  parameters: { layout: 'fullscreen' },
  render: () => (
    <div>
      {Array.from({ length: 40 }, (_, i) => (
        <div key={i} style={{ padding: 12, borderBottom: '1px solid #ddd' }}>Row {i + 1}</div>
      ))}
    </div>
  ),
};

export const Responsive: StoryObj = {
  parameters: {
    tintype: {
      modes: {
        mobile: { viewport: { width: 375, height: 667 } },
        desktop: { viewport: { width: 1280, height: 800 } },
        'dark desktop': { viewport: { width: 1280, height: 800 }, theme: 'dark' },
      },
    },
  },
  render: () => (
    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(160px, 1fr))', gap: 8 }}>
      {Array.from({ length: 6 }, (_, i) => (
        <div key={i} style={{ padding: 16, border: '1px solid currentColor', borderRadius: 6 }}>Card {i + 1}</div>
      ))}
    </div>
  ),
};

export const LegacyViewports: StoryObj = {
  parameters: { tintype: { viewports: [320, 1024] } },
  render: () => <p>Legacy viewports parameter</p>,
};
