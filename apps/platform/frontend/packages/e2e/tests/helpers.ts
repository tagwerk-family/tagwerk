import { execFileSync } from 'node:child_process'
import { randomBytes } from 'node:crypto'
import { readFileSync } from 'node:fs'
import { expect } from '@playwright/test'
import type { Page } from '@playwright/test'

const REPO = new URL('../../../../../..', import.meta.url).pathname

/** Sign-in state of the phone account, written by mobile.setup.ts. */
export const PHONE_STATE = new URL('../.auth/handy.json', import.meta.url).pathname

/** Credentials of the throwaway account, so stateless tests can sign in themselves. */
export const ACCOUNT_FILE = new URL('../.auth/konto.json', import.meta.url).pathname

export interface Account {
  email: string
  password: string
}

/**
 * Runs PHP in the container's Laravel context.
 *
 * Strings in the PHP use double quotes: the call passes the expression in
 * single quotes to the container's shell, and single quotes inside would
 * end its quoting.
 */
export function tinker(php: string): string {
  if (php.includes('\'')) {
    throw new Error('PHP for tinker must not contain single quotes.')
  }

  return execFileSync(
    'ddev',
    ['exec', `cd apps/platform && php artisan tinker --execute='${php}'`],
    { cwd: REPO, encoding: 'utf8' },
  )
}

/** Pairing codes are single-use and short-lived, so one per test. */
export function pairingCode(household?: number): string {
  const target = household === undefined
    ? 'App\\Models\\Household::orderBy("id")->first()'
    : `App\\Models\\Household::findOrFail(${household})`
  const output = tinker(
    'echo app(App\\Services\\DevicePairingService::class)'
    + `->createCode(${target}, "E2E")->pairing_code;`,
  )

  const hits = output.trim().match(/[A-Z2-9]{8}\s*$/)

  if (!hits) {
    throw new Error(`No pairing code in output: ${output}`)
  }

  return hits[0].trim()
}

export async function pair(page: Page, household?: number): Promise<void> {
  await page.goto('/')

  await expect(page.getByRole('heading', { name: 'Willkommen bei Fahoba' })).toBeVisible()
  await page.getByRole('button', { name: /Wandtablet einrichten/ }).click()

  await page.getByPlaceholder('ABCD1234').fill(pairingCode(household))
  await page.getByRole('button', { name: 'Verbinden' }).click()

  // A household of its own has no demo events to wait for.
  if (household !== undefined) {
    await expect(page.getByTestId('dashboard-grid')).toBeVisible()

    return
  }

  /*
   * The wall starts on the home page. The tests begin in the calendar -- it
   * is the area from which all others can be reached, and a visible event
   * proves that the data is really there.
   */
  await expect(page.getByTestId('dashboard-grid')).toBeVisible()
  await page.getByRole('button', { name: 'Kalender', exact: true }).click()

  await expect(page.getByTestId('event').first()).toBeVisible()
}

/**
 * Creates an account in the first household, linked to a person of the given
 * role. Address and password are new per run: sign-in
 * is throttled per address, and fixed credentials would stay in the database
 * permanently.
 */
export function createAccount(role: 'adult' | 'child' = 'adult'): Account {
  const account: Account = {
    email: `e2e-${randomBytes(6).toString('hex')}@fahoba.test`,
    password: randomBytes(16).toString('hex'),
  }

  tinker(
    '$haushalt = App\\Models\\Household::orderBy("id")->first();'
    + ' $person = App\\Models\\Person::withoutGlobalScope("household")'
    + `->where("household_id", $haushalt->id)->where("role", "${role}")->firstOrFail();`
    + ` $user = App\\Models\\User::create(["name" => "E2E", "email" => "${account.email}", "password" => "${account.password}"]);`
    + ' $user->memberships()->create(["household_id" => $haushalt->id, "person_id" => $person->id, "accepted_at" => now()]);',
  )

  return account
}

/**
 * Opens the admin for a new account the way the phone's “Verwaltung” does:
 * through the app's token cookie. Filament allows five form sign-ins a minute
 * per address, for every account together — admin.spec and security.spec
 * keep one form sign-in each, everything else comes in here.
 */
export async function adminSession(page: Page): Promise<void> {
  const account = createAccount()
  const output = tinker(
    `echo "token=" . App\\Models\\User::where("email", "${account.email}")->firstOrFail()->createToken("e2e")->plainTextToken;`,
  )
  const token = output.match(/token=(\S+)/)?.[1]

  if (!token) {
    throw new Error(`No token in output: ${output}`)
  }

  await page.context().addCookies([{
    name: 'fahoba_user_token',
    value: token,
    url: 'https://fahoba.test',
    secure: true,
    httpOnly: true,
    sameSite: 'Lax',
  }])
  await page.goto('/admin')
  await expect(page.locator('.fi-header-heading')).toBeVisible()
}

/**
 * A throwaway household with one parent (PIN 1234) and one child, made the
 * way the operator makes one. For tests that count what a person has: in
 * the demo household parallel tests book for the same children. Remove it
 * with dropHousehold(); the teardown catches what a crash leaves.
 */
export function ownHousehold(child: string): number {
  const output = tinker(
    '$parent = app(App\\Services\\HouseholdOnboarding::class)->create('
    + `"E2E ${randomBytes(4).toString('hex')}", "Europe/Vienna", "E2E Elternteil", "e2e-${randomBytes(6).toString('hex')}@fahoba.test", "${randomBytes(16).toString('hex')}");`
    + ' app(App\\Services\\ParentPin::class)->set($parent, "1234");'
    + ' $household = $parent->memberships()->sole()->household_id;'
    + ` App\\Models\\Person::create(["household_id" => $household, "name" => "${child}", "role" => "child", "birthday" => "2018-03-01", "color" => "#e11d48"]);`
    + ' echo "household=" . $household;',
  )

  const hit = output.match(/household=(\d+)/)

  if (!hit) {
    throw new Error(`No household in output: ${output}`)
  }

  return Number(hit[1])
}

export function dropHousehold(id: number): void {
  tinker(`App\\Models\\Household::findOrFail(${id})->delete();`)
}

export function storedAccount(): Account {
  const data: Account = JSON.parse(readFileSync(ACCOUNT_FILE, 'utf8'))

  return data
}

/** The start page's way in for a person, rather than a tablet. */
export async function openSignIn(page: Page): Promise<void> {
  await page.goto('/')
  await page.getByTestId('entry-phone').click()
  await expect(page.getByTestId('login')).toBeVisible()
}

export async function login(page: Page, account: Account): Promise<void> {
  await page.getByLabel('E-Mail').fill(account.email)
  await page.getByLabel('Passwort').fill(account.password)
  await page.getByTestId('login').click()

  // Signed in once the menu button is there: the areas themselves sit in the closed menu.
  await expect(page.getByTestId('menu-open')).toBeVisible()
}
