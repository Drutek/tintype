import type { ButtonHTMLAttributes } from 'react';

export interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: 'primary' | 'secondary' | 'danger';
  size?: 'sm' | 'md' | 'lg';
}

const colors = {
  primary: { background: '#2563eb', color: '#fff' },
  secondary: { background: '#e5e7eb', color: '#111' },
  danger: { background: '#dc2626', color: '#fff' },
};
const sizes = { sm: '4px 8px', md: '8px 16px', lg: '12px 24px' };

export function Button({ variant = 'primary', size = 'md', style, ...rest }: ButtonProps) {
  return (
    <button
      {...rest}
      style={{
        ...colors[variant],
        padding: sizes[size],
        border: 0,
        borderRadius: 6,
        fontFamily: 'Inter, sans-serif',
        fontSize: 14,
        fontWeight: 700,
        cursor: 'pointer',
        transition: 'background 300ms ease',
        ...style,
      }}
    />
  );
}
