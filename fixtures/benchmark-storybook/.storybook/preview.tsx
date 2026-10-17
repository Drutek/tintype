import type { Preview } from '@storybook/react-vite';
import '@fontsource/inter/400.css';
import '@fontsource/inter/700.css';

// Every story is captured in two modes (benchmark target: 2 modes x 2 browsers).
const preview: Preview = {
  parameters: {
    tintype: {
      modes: {
        mobile: { viewport: { width: 375, height: 667 } },
        desktop: { viewport: { width: 1280, height: 800 } },
      },
    },
  },
  decorators: [(Story) => <div style={{ fontFamily: 'Inter, sans-serif', padding: 16 }}><Story /></div>],
};
export default preview;
