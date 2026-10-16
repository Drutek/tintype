import type { Meta, StoryObj } from '@storybook/react-vite';
import { useState } from 'react';
import { expect, userEvent, within } from 'storybook/test';

function LoginForm() {
  const [submitted, setSubmitted] = useState<string | null>(null);
  return (
    <form
      onSubmit={(e) => {
        e.preventDefault();
        setSubmitted(new FormData(e.currentTarget).get('email') as string);
      }}
      style={{ display: 'grid', gap: 8, width: 260 }}
    >
      <input name="email" aria-label="Email" placeholder="you@example.com" style={{ padding: 8 }} />
      <button type="submit">Sign in</button>
      {submitted && <p role="status">Welcome {submitted}</p>}
    </form>
  );
}

const meta = { title: 'Edge Cases/Interactions', component: LoginForm } satisfies Meta<typeof LoginForm>;
export default meta;

export const FilledIn: StoryObj<typeof meta> = {
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement);
    await userEvent.type(canvas.getByLabelText('Email'), 'ada@example.com');
    await userEvent.click(canvas.getByRole('button', { name: 'Sign in' }));
    await expect(canvas.getByRole('status')).toHaveTextContent('Welcome ada@example.com');
  },
};

export const FailingPlay: StoryObj<typeof meta> = {
  tags: ['!autodocs'],
  play: async ({ canvasElement }) => {
    const canvas = within(canvasElement);
    await expect(canvas.queryByRole('status')).toBeInTheDocument();
  },
};

export const ThrowsOnRender: StoryObj<typeof meta> = {
  render: () => {
    throw new Error('Intentional render error');
  },
};
