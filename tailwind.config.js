/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    "./public/**/*.php",
    "./includes/**/*.php",
  ],
  theme: {
    extend: {
      colors: {
        brand: {
          50:'#eef4ff',100:'#dbe6ff',200:'#b8ccff',300:'#8aa9ff',
          400:'#5c82ff',500:'#3b63f5',600:'#2947d1',700:'#2138a8',
          800:'#1c2f85',900:'#1a2b6b'
        }
      },
      fontFamily: {
        sans: ['Inter', 'ui-sans-serif', 'system-ui', '-apple-system', 'Segoe UI', 'Roboto', 'sans-serif'],
      }
    }
  },
  plugins: [],
}
