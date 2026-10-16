import type { Meta, StoryObj } from '@storybook/react-vite';

const meta = { title: 'Edge Cases/Accessibility' } satisfies Meta;
export default meta;

/** Deliberately broken: low contrast text and an image without alt text. */
export const Violations: StoryObj = {
  render: () => (
    <div>
      <button type="button" style={{ color: '#bbb', background: '#ccc', border: 0, padding: 8 }}>
        Low contrast
      </button>
      <img src="./gradient.png" width={60} height={40} />
    </div>
  ),
};

export const Clean: StoryObj = {
  render: () => (
    <button type="button" style={{ color: '#fff', background: '#1d4ed8', border: 0, padding: 8 }}>
      Readable
    </button>
  ),
};

export const ChecksDisabled: StoryObj = {
  parameters: { tintype: { a11y: false } },
  render: () => <img src="./gradient.png" width={60} height={40} />,
};
