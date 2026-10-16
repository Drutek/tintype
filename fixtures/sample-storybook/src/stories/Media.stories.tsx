import type { Meta, StoryObj } from '@storybook/react-vite';

const meta = { title: 'Edge Cases/Media' } satisfies Meta;
export default meta;

export const Image: StoryObj = { render: () => <img src="./gradient.png" width={240} height={160} alt="gradient" /> };

export const LazyImageBelowFold: StoryObj = {
  render: () => (
    <div>
      <div style={{ height: 900, background: 'linear-gradient(#eee, #ccc)' }}>Spacer</div>
      <img src="./gradient.png" loading="lazy" width={120} height={80} alt="lazy" />
    </div>
  ),
};

export const Svg: StoryObj = {
  render: () => (
    <svg width="120" height="120" viewBox="0 0 120 120">
      <circle cx="60" cy="60" r="50" fill="none" stroke="#2563eb" strokeWidth="6" />
      <path d="M35 62 L54 80 L86 42" fill="none" stroke="#16a34a" strokeWidth="8" strokeLinecap="round" />
    </svg>
  ),
};

export const Gradients: StoryObj = {
  render: () => (
    <div style={{ width: 300, height: 120, borderRadius: 12, background: 'radial-gradient(circle at 30% 30%, #f472b6, #6366f1 60%, #0f172a)', boxShadow: '0 10px 30px rgba(0,0,0,.35)', filter: 'blur(0.5px)' }} />
  ),
};
