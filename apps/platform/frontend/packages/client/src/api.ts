import { createHttp, type ClientOptions, type Http } from './http.ts'
import type { Language } from './rules/language.ts'
import type { HouseholdNotice } from './rules/notice.ts'
import type { ThemeState } from './rules/theme.ts'
import { connectRealtime } from './realtime.ts'

/** Who this browser is to the server: a paired wall tablet or a signed-in account. */
export type Viewer = 'device' | 'user'

export interface Account {
  id: number
  name: string
  email: string
  person_id: number | null
  /** What to offer; the server checks every write again. */
  permissions: string[]
  locale: string
  language?: Language
  locale_preference: string | null
  locales: Record<string, string>
  households?: Array<{ id: number, name: string, current: boolean }>
  invitations?: Array<{ id: number, name: string }>
  notices?: HouseholdNotice[]
}

/** Password or link were right, but the account also needs its authenticator code. */
export interface SecondFactor {
  two_factor: string
}

export interface PairedDevice {
  household_id: number
  device: { id: number, name: string, kind: string }
}

export interface Person {
  id: number
  name: string
  color: string | null
  avatar: string | null
  role: string
}

export interface CalendarEvent {
  id: number
  title: string
  location: string | null
  /** ISO 8601 with offset; an all-day event starts at midnight in the household's zone. */
  starts_at: string
  ends_at: string
  all_day: boolean
  calendar_id: number
  /** Assigned people, else the calendar's; empty means the family. */
  people: number[]
  /** The first person's colour, else the calendar's. */
  color: string | null
  /** Decided by the server for whoever asks. */
  can: { edit: boolean }
}

export interface Area {
  feature: string
  mode: string
  label: string
  icon: string
}

/**
 * The agenda — the one call that knows the household: people, areas,
 * notices, language and what happens in a period. The fields named here
 * are the stable ones; the rest of the answer may grow.
 */
export interface Agenda {
  household: {
    name: string
    timezone: string
    notices: HouseholdNotice[]
    areas: Area[]
    [key: string]: unknown
  }
  language: Language
  generated_at: string
  /** Changes when the server is updated: reload the page then. */
  app_version: string
  viewer: Viewer
  range: { from: string, to: string }
  people: Person[]
  events: CalendarEvent[]
  [key: string]: unknown
}

export interface FahobaClient extends Http {
  /** 401 (as `FahobaError`) means nobody: show pairing or sign-in. */
  session: () => Promise<{ kind: Viewer }>
  /** Redeems a pairing code; the server sets the device cookie. */
  pair: (code: string) => Promise<PairedDevice>
  login: (email: string, password: string) => Promise<Account | SecondFactor>
  confirmSecondFactor: (ticket: string, code: string) => Promise<Account | SecondFactor>
  me: () => Promise<Account>
  logout: () => Promise<void>
  /** Public: the language to start in, before any text shows. */
  language: () => Promise<Language>
  theme: () => Promise<ThemeState>
  /** Dates as `YYYY-MM-DD` in the household's time zone. */
  agenda: (from: string, to: string) => Promise<Agenda>
  realtime: (onChange: (areas: string[]) => unknown) => ReturnType<typeof connectRealtime>
}

/**
 * The client for a frontend served by a Fahoba server.
 *
 * ```ts
 * const fahoba = createClient()
 * const { kind } = await fahoba.session()   // 'device' | 'user', or 401
 * const agenda = await fahoba.agenda('2026-11-02', '2026-11-08')
 * await fahoba.realtime(areas => reload(areas))
 * ```
 *
 * Everything else is `fahoba.request(path, options)` against the routes in
 * `/api/openapi.json`; refusals throw `FahobaError` with a `kind`.
 */
export function createClient(options: ClientOptions = {}): FahobaClient {
  const http = createHttp(options)
  const { request } = http

  return {
    ...http,
    session: () => request('/api/session'),
    pair: code => request('/api/devices/pair', { method: 'POST', body: { code: code.trim() } }),
    login: (email, password) => request('/api/auth/login', { method: 'POST', body: { email, password } }),
    confirmSecondFactor: (ticket, code) => request('/api/auth/two-factor', { method: 'POST', body: { ticket, code } }),
    me: () => request('/api/auth/me'),
    logout: async () => {
      await request('/api/auth/logout', { method: 'POST' })
    },
    language: () => request('/api/language'),
    theme: () => request('/api/theme'),
    agenda: (from, to) => request('/api/wall/agenda', { query: { from, to } }),
    realtime: onChange => connectRealtime(request, onChange),
  }
}
