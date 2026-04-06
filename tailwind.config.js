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
        obsidian: "#050505",
        carbon: "#0d0f12",
        graphite: "#14181d",
        slate: "#1b2027",
        line: "#2c323b",
        ember: "#f4c95d",
        amberdeep: "#b9891e",
        mist: "#f8f6ef",
        pearl: "#d6d2c4",
        success: "#10b981",
        warning: "#f59e0b",
        danger: "#ef4444"
      },
      fontFamily: {
        sans: ["Montserrat", "ui-sans-serif", "system-ui", "sans-serif"]
      },
      boxShadow: {
        luxe: "0 24px 70px rgba(0, 0, 0, 0.45)",
        panel: "0 0 0 1px rgba(244, 201, 93, 0.08), 0 18px 50px rgba(0, 0, 0, 0.38)",
        glow: "0 0 0 1px rgba(244, 201, 93, 0.22), 0 18px 45px rgba(0, 0, 0, 0.45)"
      },
      backgroundImage: {
        "premium-grid":
          "radial-gradient(circle at top, rgba(244, 201, 93, 0.18), transparent 30%), radial-gradient(circle at 85% 10%, rgba(255, 255, 255, 0.08), transparent 20%), linear-gradient(180deg, #14181d 0%, #050505 100%)"
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
