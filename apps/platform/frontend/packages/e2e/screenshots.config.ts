import { defineConfig, devices } from '@playwright/test'

/*
 * The README's pictures, taken from the built app with the demo household:
 *   pnpm run screenshots    (after `pnpm build` and `migrate:fresh --seed`)
 * Writes docs/screenshots/*.png. Not part of `pnpm run e2e`.
 */
export default defineConfig({
  testDir: './screenshots',
  workers: 1,
  reporter: [['list']],
  globalTeardown: './tests/cleanup.ts',
  use: {
    baseURL: 'https://fahoba.test',
    ignoreHTTPSErrors: true,
    channel: 'chrome',
    timezoneId: 'Europe/Vienna',
    locale: 'de-AT',
  },
  projects: [
    { name: 'wall', testMatch: 'wall.ts', use: { ...devices['Desktop Chrome'], viewport: { width: 1280, height: 800 } } },
    { name: 'website', testMatch: 'website.ts', use: { ...devices['Desktop Chrome'], viewport: { width: 1280, height: 800 }, deviceScaleFactor: 2 } },
    { name: 'phone', testMatch: 'phone.ts', use: { ...devices['Pixel 7'] } },
  ],
})
