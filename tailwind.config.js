module.exports = {
  content: ['./src/**/*.{js,jsx,ts,tsx}'],
  theme: {
    extend: {
      // Tailwind's default gray is blue-tinted; ServeNest neutrals carry the
      // teal undertone instead. This also drives the default border and
      // placeholder colours from preflight.
      colors: {
        gray: {
          50: '#f9fafb',
          100: '#f3f4f6',
          200: '#e4e8e8',
          300: '#d1d6d6',
          400: '#9ba5a5',
          500: '#697574',
          600: '#4a5757',
          700: '#354443',
          800: '#1e2b2b',
          900: '#0d1b1b',
          950: '#020909',
        },
      },
      ringColor: {
        DEFAULT: '#069494',
      },
    },
  },
  plugins: [],
};
