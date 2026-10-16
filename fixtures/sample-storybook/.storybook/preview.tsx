import type { Preview } from '@storybook/react-vite';
import '@fontsource/inter/400.css';
import '@fontsource/inter/700.css';
import './preview.css';

const preview: Preview = {
  globalTypes: {
    theme: { description: 'Theme', toolbar: { items: ['light', 'dark'] } },
  },
  initialGlobals: { theme: 'light' },
  decorators: [
    (Story, ctx) => (
      <div className={`theme-${ctx.globals.theme ?? 'light'}`} style={{ padding: 16 }}>
        <Story />
      </div>
    ),
  ],
};
export default preview;
