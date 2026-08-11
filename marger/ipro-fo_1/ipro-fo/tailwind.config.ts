import type { Config } from 'tailwindcss';
import forms from '@tailwindcss/forms';
import typography from '@tailwindcss/typography';

export default {
    darkMode: 'class',
    content: [
        './resources/views/**/*.blade.php',
        './resources/js/**/*.{ts,tsx,vue,js}',
        './app/Http/Controllers/**/*.php',
    ],
    theme: {
        extend: {
            fontFamily: {
                sans: ['Inter', 'ui-sans-serif', 'system-ui', '-apple-system', 'BlinkMacSystemFont', 'Segoe UI', 'Roboto', 'sans-serif'],
                mono: ['JetBrains Mono', 'ui-monospace', 'SFMono-Regular', 'Menlo', 'Monaco', 'Consolas', 'monospace'],
            },
            colors: {
                // Brand utama — Orange #FF8C00 (DarkOrange) — anchor di shade 500 & 600
                primary: {
                    50:  '#fff8eb',
                    100: '#ffedcc',
                    200: '#ffdb99',
                    300: '#ffc466',
                    400: '#ffaa33',
                    500: '#ff8c00',  // ← #FF8C00 (warna brand utama — accent, focus, active)
                    600: '#ff8c00',  // ← #FF8C00 juga (tombol & logo agar tampil persis)
                    700: '#cc7000',
                    800: '#9e5600',
                    900: '#6b3a00',
                    950: '#3d2000',
                },
                accent: { 500: '#ff8c00' },
                // Palet status — RAMP PENUH 50–950. Sebelumnya hanya 500 & 600, sehingga kelas
                // seperti `bg-danger-50` / `text-success-700` yang dipakai di ratusan tempat tidak
                // pernah menghasilkan CSS (warnanya diam-diam hilang). Shade 500 & 600 SENGAJA
                // dipertahankan persis seperti semula agar tampilan lama tidak bergeser; sisanya
                // mengikuti ramp bawaan Tailwind yang anchor-nya memang sama
                // (success=emerald · warning=amber · danger=red · info=sky).
                success: {
                    50:  '#ecfdf5', 100: '#d1fae5', 200: '#a7f3d0', 300: '#6ee7b7', 400: '#34d399',
                    500: '#10b981', 600: '#059669', 700: '#047857', 800: '#065f46', 900: '#064e3b', 950: '#022c22',
                },
                warning: {
                    50:  '#fffbeb', 100: '#fef3c7', 200: '#fde68a', 300: '#fcd34d', 400: '#fbbf24',
                    500: '#f59e0b', 600: '#d97706', 700: '#b45309', 800: '#92400e', 900: '#78350f', 950: '#451a03',
                },
                danger: {
                    50:  '#fef2f2', 100: '#fee2e2', 200: '#fecaca', 300: '#fca5a5', 400: '#f87171',
                    500: '#ef4444', 600: '#dc2626', 700: '#b91c1c', 800: '#991b1b', 900: '#7f1d1d', 950: '#450a0a',
                },
                info: {
                    50:  '#f0f9ff', 100: '#e0f2fe', 200: '#bae6fd', 300: '#7dd3fc', 400: '#38bdf8',
                    500: '#0ea5e9', 600: '#0284c7', 700: '#0369a1', 800: '#075985', 900: '#0c4a6e', 950: '#082f49',
                },
                neutral: {
                    50:  '#f8fafc',
                    100: '#f1f5f9',
                    200: '#e2e8f0',
                    300: '#cbd5e1',
                    400: '#94a3b8',
                    500: '#64748b',
                    600: '#475569',
                    700: '#334155',
                    800: '#1e293b',
                    900: '#0f172a',
                    950: '#020617',
                },
                status: {
                    available:  '#10b981',
                    hold:       '#14b8a6',
                    booked:     '#f59e0b',
                    sold:       '#7c3aed',
                    cancelled:  '#94a3b8',
                    overdue:    '#ef4444',
                    paid:       '#10b981',
                    partial:    '#f59e0b',
                    void:       '#94a3b8',
                    draft:      '#64748b',
                    pending:    '#f59e0b',
                    approved:   '#10b981',
                    rejected:   '#ef4444',
                },
            },
            spacing: {
                '18': '4.5rem',
                '88': '22rem',
                '128': '32rem',
            },
            borderRadius: {
                'sm':  '4px',
                DEFAULT: '8px',
                'lg':  '10px',
                'xl':  '12px',
                '2xl': '16px',
            },
            boxShadow: {
                'card': '0 1px 3px 0 rgb(0 0 0 / 0.05), 0 1px 2px -1px rgb(0 0 0 / 0.05)',
                'card-hover': '0 4px 6px -1px rgb(0 0 0 / 0.07), 0 2px 4px -2px rgb(0 0 0 / 0.05)',
            },
            animation: {
                'fade-in':   'fadeIn 0.2s ease-out',
                'slide-up':  'slideUp 0.3s ease-out',
                'shimmer':   'shimmer 1.5s linear infinite',
            },
            keyframes: {
                fadeIn:  { '0%': { opacity: '0' }, '100%': { opacity: '1' } },
                slideUp: { '0%': { opacity: '0', transform: 'translateY(8px)' }, '100%': { opacity: '1', transform: 'translateY(0)' } },
                shimmer: { '0%': { backgroundPosition: '-200% 0' }, '100%': { backgroundPosition: '200% 0' } },
            },
            container: {
                center: true,
                padding: { DEFAULT: '1rem', sm: '1.5rem', lg: '2rem' },
                screens: { '2xl': '1440px' },
            },
        },
    },
    plugins: [forms, typography],
} satisfies Config;
