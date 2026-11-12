import { defineConfig } from 'vite'

/*
 * In development the browser talks to Vite only; Vite passes the server's
 * paths on. The sign-in cookie then belongs to this origin, as it will when
 * Fahoba serves the built files itself. FAHOBA_URL is your server — a DDEV
 * checkout is https://fahoba.test.
 */
const server = process.env.FAHOBA_URL ?? 'https://fahoba.test'
const proxy = { target: server, changeOrigin: true, secure: false }

export default defineConfig({
  server: {
    proxy: { '/api': proxy, '/broadcasting': proxy, '/emoji': proxy },
  },
  build: {
    // Fahoba serves index.html at / and everything else from /assets/.
    outDir: 'dist',
    assetsDir: 'assets',
    emptyOutDir: true,
  },
})
