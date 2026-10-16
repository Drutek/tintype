import type { Meta, StoryObj } from '@storybook/react-vite';
import { useEffect, useState } from 'react';

const meta = { title: 'Edge Cases/Motion' } satisfies Meta;
export default meta;

const keyframes = `@keyframes spin { from { transform: rotate(0deg) } to { transform: rotate(360deg) } }
@keyframes slide { from { transform: translateX(0) } to { transform: translateX(200px) } }`;

export const Spinner: StoryObj = {
  render: () => (
    <>
      <style>{keyframes}</style>
      <div style={{ width: 40, height: 40, border: '4px solid #ddd', borderTopColor: '#2563eb', borderRadius: '50%', animation: 'spin 1s linear infinite' }} />
    </>
  ),
};

export const SlideInPausedAtEnd: StoryObj = {
  parameters: { tintype: { pauseAnimationAtEnd: true } },
  render: () => (
    <>
      <style>{keyframes}</style>
      <div style={{ width: 60, height: 60, background: '#16a34a', animation: 'slide 2s ease-out forwards' }} />
    </>
  ),
};

function FadeIn() {
  const [shown, setShown] = useState(false);
  useEffect(() => {
    const t = setTimeout(() => setShown(true), 50);
    return () => clearTimeout(t);
  }, []);
  return (
    <div style={{ opacity: shown ? 1 : 0, transition: 'opacity 800ms', padding: 20, background: '#fde68a' }}>
      Faded in after mount
    </div>
  );
}
export const TransitionOnMount: StoryObj = { render: () => <FadeIn /> };

export const WithDelay: StoryObj = {
  parameters: { tintype: { delay: 300 } },
  render: () => <FadeIn />,
};
