import { defineConfig, devices } from '@playwright/test'
import { PHONE_STATE } from './tests/helpers'

/*
 * Tests Fahoba the way nginx serves it: the built app (wall and phone) under
 * /, Laravel under /api and /admin. So run `pnpm build`
 * first — otherwise the run tests the previous build.
 *
 * Runs from the host, not from the container: Playwright and its browsers
 * already live there. The version of @playwright/test is therefore pinned to
 * the host's -- otherwise the runner looks for browsers under another version.
 */
export default defineConfig({
  testDir: './tests',
  fullyParallel: false,
  workers: 1,
  reporter: [['list']],

  // Remove the throwaway account and paired test devices, even after red runs.
  globalTeardown: './tests/cleanup.ts',

  use: {
    baseURL: 'https://fahoba.test',

    // DDEV uses a self-signed certificate.
    ignoreHTTPSErrors: true,

    trace: 'retain-on-failure',

    // The Google Chrome installed on the host instead of a separate
    // download: the host's browser cache belongs to older Playwright
    // versions.
    channel: 'chrome',

    // The interfaces compute in the household's time zone. A different
    // device time zone in the test keeps that separation honest.
    timezoneId: 'UTC',
    locale: 'de-AT',
  },

  projects: [
    {
      name: 'addresses',
      testMatch: 'addresses.spec.ts',
      use: { ...devices['Desktop Chrome'], viewport: { width: 1920, height: 1080 } },
    },
    {
      name: 'admin',
      testMatch: 'admin.spec.ts',
      use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 900 } },
    },
    {
      name: 'security',
      testMatch: 'security.spec.ts',
      use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 900 } },
    },
    {
      // The target device is a 15.6-inch wall tablet in landscape.
      name: 'wall',
      testMatch: 'wall.spec.ts',
      use: { ...devices['Desktop Chrome'], viewport: { width: 1920, height: 1080 } },
    },
    {
      // axe over every area: serious and critical findings fail.
      name: 'a11y',
      testMatch: 'a11y.spec.ts',
      use: { ...devices['Desktop Chrome'], viewport: { width: 1920, height: 1080 } },
    },
    {
      // A plugin's area end to end: menu, view from its layer, switch.
      name: 'plugins',
      testMatch: 'plugins.spec.ts',
      use: { ...devices['Desktop Chrome'], viewport: { width: 1920, height: 1080 } },
    },
    {
      // A language pack's first text: nothing German before French, offline too.
      name: 'languages',
      testMatch: 'languages.spec.ts',
      use: { ...devices['Desktop Chrome'], viewport: { width: 1920, height: 1080 } },
    },
    {
      // The installed app: cold start without network, notifications.
      // After `admin`: pairing leaves devices named “E2E”, and the admin
      // test looks for exactly one until the teardown removes them.
      name: 'pwa',
      testMatch: 'pwa.spec.ts',
      use: { ...devices['Desktop Chrome'], viewport: { width: 1920, height: 1080 } },
    },
    {
      // Sign in once and pass the cookies on: sign-in is throttled to five
      // attempts per minute.
      name: 'mobile-login',
      testMatch: 'mobile.setup.ts',
      use: { ...devices['Pixel 7'] },
    },
    {
      name: 'mobile',
      testMatch: 'mobile.spec.ts',
      dependencies: ['mobile-login'],
      use: { ...devices['Pixel 7'], storageState: PHONE_STATE },
    },
    /*
     * One interface for both: the width decides the layout, the sign-in
     * what is offered. These two cross over — a tablet narrow, an account
     * wide — so neither rule can lean on the other.
     */
    {
      name: 'narrow-device',
      testMatch: 'responsive.spec.ts',
      use: { ...devices['Pixel 7'] },
    },
    {
      name: 'wide-account',
      testMatch: 'responsive.spec.ts',
      dependencies: ['mobile-login'],
      use: { ...devices['Desktop Chrome'], viewport: { width: 1280, height: 800 }, storageState: PHONE_STATE },
    },
  ],
})
