import type { Config } from "tailwindcss";

// Palette mirrors the approved prototype (Sales Hub - Internal System.html).
const config: Config = {
  content: ["./src/**/*.{ts,tsx}"],
  theme: {
    extend: {
      colors: {
        bg: "#e8f6f5",
        panel: "#ffffff",
        ink: "#41505f",
        sub: "#9aa7b6",
        line: "#eef4f5",
        main: "#f0a94e", // warm orange — primary brand color
        teal: "#4ec3c7",
        green: "#5cc2a8",
        amber: "#f4b46b",
        red: "#f58c8c",
        blue: "#7db8e8",
        purple: "#b19cd9",
      },
      borderRadius: {
        card: "18px",
      },
      boxShadow: {
        soft: "0 6px 22px rgba(79,195,199,.10), 0 2px 6px rgba(65,80,95,.05)",
      },
      fontFamily: {
        sans: ["Inter", "ui-sans-serif", "system-ui", "-apple-system", "Segoe UI", "sans-serif"],
      },
    },
  },
  plugins: [],
};

export default config;
