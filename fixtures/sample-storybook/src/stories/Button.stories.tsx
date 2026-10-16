import type { Meta, StoryObj } from '@storybook/react-vite';
import { Button } from '../components/Button';

const meta = { title: 'Components/Button', component: Button, args: { children: 'Click me' } } satisfies Meta<typeof Button>;
export default meta;
type Story = StoryObj<typeof meta>;

export const Primary: Story = {};
export const Secondary: Story = { args: { variant: 'secondary' } };
export const Danger: Story = { args: { variant: 'danger' } };
export const Large: Story = { args: { size: 'lg' } };
export const Skipped: Story = { parameters: { tintype: { disableSnapshot: true } } };
