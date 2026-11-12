import { fileURLToPath } from 'node:url'
import tailwindcss from '@tailwindcss/vite'
import { pluginLayers } from './plugin-layers'

// https://nuxt.com/docs/api/configuration/nuxt-config
export default defineNuxtConfig({
  compatibilityDate: '2025-07-15',

  // One app for wall and phone; design, theme and the views live in the layer.
  // Then the layers of the installed plugins (plugin-layers.ts).
  extends: ['../shared', ...pluginLayers(fileURLToPath(new URL('../../..', import.meta.url)))],
  // Off and removed by a pnpm override: devtools 3 needs a simple-git with
  // open advisories, and a wall app gains nothing from the inspector.
  devtools: { enabled: false },

  // A kiosk gains nothing from server-side rendering. Without SSR the
  // httpOnly cookies do not have to be passed to the renderer either.
  ssr: false,

  css: ['~/assets/css/main.css'],

  /*
   * An import map can only be inline, and its content changes with every
   * build — the Content Security Policy would need a new hash each time.
   * Without it the entry is loaded by its file name; see
   * modules/external-scripts.ts for the remaining inline scripts.
   */
  experimental: {
    entryImportMap: false,
  },

  app: {
    head: {
      title: 'Family Home Base',
      htmlAttrs: { lang: 'de' },
      link: [
        { rel: 'icon', type: 'image/svg+xml', href: '/favicon.svg' },
        { rel: 'manifest', href: '/manifest.webmanifest' },
      ],
      meta: [
        { name: 'theme-color', content: '#f6f6f4' },

        // Without viewport-fit and user-scalable the view jumps when
        // tapping into an input field on an iPhone.
        {
          name: 'viewport',
          content: 'width=device-width, initial-scale=1, viewport-fit=cover, user-scalable=no',
        },
      ],
    },
  },

  vite: {
    plugins: [tailwindcss()],
    /*
     * A plugin's layer lives outside the workspace and has no node_modules:
     * its compiled components import `vue` from the app's copy, not from
     * wherever resolution starting at the plugin would end.
     */
    resolve: {
      dedupe: ['vue', 'vue-router', '@vue/runtime-core', '@vue/shared'],
    },

    server: {
      // DDEV's router addresses the dev server under the project domain.
      // Otherwise Vite rejects foreign hosts with 403.
      allowedHosts: ['fahoba.test'],
    },
  },

  devServer: {
    // Without 0.0.0.0 the server only listens on localhost inside the
    // container, and the port exposed by DDEV would stay empty.
    host: '0.0.0.0',
    port: 3000,
  },

  nitro: {
    devProxy: {
      /*
       * The browser talks exclusively to the dev server and thus stays
       * same-origin. That is not a convenience: the device token lives in an
       * httpOnly cookie, and a request via a foreign origin would not send it
       * at all without CORS concessions.
       *
       * The target is the web server in the same container, hence no TLS.
       * changeOrigin stays off so the Host header is preserved and the cookie
       * is set on the same domain.
       */
      '/api': {
        target: 'http://127.0.0.1/api',
        changeOrigin: false,
      },

      // The WebSocket's channel authorization. It depends on the same device
      // cookie and therefore has to go through the same origin as /api.
      '/broadcasting': {
        target: 'http://127.0.0.1/broadcasting',
        changeOrigin: false,
      },
    },
  },
})
