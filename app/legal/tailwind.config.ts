import type { Config } from "tailwindcss";

/**
 * Colors map 1:1 to the CSS variables defined in app/globals.css, which are
 * taken verbatim from the reference HTML mockups (light + dark). The reference
 * is the visual source of truth, so we consume its exact token names here.
 */
const config: Config = {
  content: ["./app/**/*.{ts,tsx}", "./components/**/*.{ts,tsx}"],
  theme: {
    extend: {
      colors: {
        bg: {
          primary: "var(--color-background-primary)",
          secondary: "var(--color-background-secondary)",
          tertiary: "var(--color-background-tertiary)",
          info: "var(--color-background-info)",
          success: "var(--color-background-success)",
          warning: "var(--color-background-warning)",
          danger: "var(--color-background-danger)",
        },
        ink: {
          primary: "var(--color-text-primary)",
          secondary: "var(--color-text-secondary)",
          tertiary: "var(--color-text-tertiary)",
          info: "var(--color-text-info)",
          success: "var(--color-text-success)",
          warning: "var(--color-text-warning)",
          danger: "var(--color-text-danger)",
        },
        line: {
          tertiary: "var(--color-border-tertiary)",
          secondary: "var(--color-border-secondary)",
          info: "var(--color-border-info)",
          success: "var(--color-border-success)",
          warning: "var(--color-border-warning)",
          danger: "var(--color-border-danger)",
        },
      },
      borderRadius: { md: "8px", lg: "12px", xl: "16px" },
      fontFamily: {
        sans: ["system-ui", "-apple-system", "Segoe UI", "Roboto", "Helvetica", "Arial", "sans-serif"],
      },
    },
  },
  plugins: [],
};

export default config;
