module.exports = {
  content: [
    "./*.php",
    "./frontend/**/*.{html,js}",
    "./assets/**/*.{php,js}",
    "./backend/**/*.php"
  ],
  theme: {
    extend: {
      colors: {
        primary: "#0F4C81",
        primaryDark: "#0B3960",
        secondary: "#F3F4F6",
        secondaryDark: "#E5E7EB",
        accent: "#14B8A6",
        accentHover: "#0D9488",
        surface: "#FFFFFF",
        textMain: "#1F2937",
        textMuted: "#6B7280",
        border: "#E5E7EB",
        success: "#10b981",
        warning: "#f59e0b",
        danger: "#ef4444"
      },
      fontFamily: {
        sans: ["Montserrat", "ui-sans-serif", "system-ui", "sans-serif"]
      },
      boxShadow: {
        luxe: "0 10px 25px rgba(0, 0, 0, 0.05)",
        panel: "0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06)",
        glow: "0 0 15px rgba(20, 184, 166, 0.5)"
      },
      backgroundImage: {
        "premium-grid":
          "radial-gradient(circle at top, rgba(15, 76, 129, 0.05), transparent 30%), linear-gradient(180deg, #FFFFFF 0%, #F9FAFB 100%)"
      },
      keyframes: {
        float: {
          "0%, 100%": { transform: "translateY(0)" },
          "50%": { transform: "translateY(-6px)" }
        }
      },
      animation: {
        float: "float 6s ease-in-out infinite"
      }
    }
  },
  plugins: []
};
