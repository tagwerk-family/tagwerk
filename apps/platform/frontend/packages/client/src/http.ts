/**
 * Talking to a Fahoba server — from a page on the same origin.
 *
 * The sign-in lives in httpOnly cookies the server sets (`fahoba_device_token`,
 * `fahoba_user_token`), so a frontend never sees a token: it sends
 * `credentials: 'same-origin'` and the cookie goes along. That is also why a
 * frontend must be served by the Fahoba server itself — from another origin
 * the cookie would not travel.
 */

/** What a refusal means for the person in front of the screen. */
export type ErrorKind =
  /** Nobody signed in, or the device was unpaired: show the start page. */
  | 'unauthenticated'
  /** The household is read-only; `message` explains why (a notice). */
  | 'read-only'
  /** An account may not do this. Nothing helps but someone else. */
  | 'forbidden'
  /** Gone, or the area is switched off for this household. */
  | 'not-found'
  /** A wall needs the parent PIN for this: ask for it, then repeat. */
  | 'pin'
  /** The input was refused; `errors` names the fields. */
  | 'invalid'
  /** Too many tries; wait. */
  | 'throttled'
  /** The server failed or could not be reached. */
  | 'unavailable'

const KINDS: Record<number, ErrorKind> = {
  401: 'unauthenticated',
  402: 'read-only',
  403: 'forbidden',
  404: 'not-found',
  422: 'invalid',
  423: 'pin',
  429: 'throttled',
}

export class FahobaError extends Error {
  readonly kind: ErrorKind

  constructor(
    readonly status: number,
    readonly data: { message?: string, errors?: Record<string, string[]>, [key: string]: unknown } | null,
  ) {
    super(data?.message ?? `Fahoba answered ${status}`)
    this.name = 'FahobaError'
    this.kind = KINDS[status] ?? 'unavailable'
  }

  /** The first field error, else the server's message — what a form shows. */
  get firstMessage(): string | null {
    return Object.values(this.data?.errors ?? {})[0]?.[0] ?? this.data?.message ?? null
  }
}

export interface RequestOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'
  /** Sent as JSON; `FormData` as it is (uploads). */
  body?: unknown
  query?: Record<string, string | number | boolean | null | undefined>
  signal?: AbortSignal
}

export interface Response<T> {
  status: number
  data: T
  headers: Headers
}

export interface ClientOptions {
  /** Where the server is; the default, '', is the page's own origin. */
  baseURL?: string
  /** Defaults to the global `fetch`; tests pass their own. */
  fetch?: typeof globalThis.fetch
}

export type RequestFn = <T = unknown>(path: string, options?: RequestOptions) => Promise<T>

export interface Http {
  request: RequestFn
  /** The same, with status and headers (the service worker marks stale answers there). */
  raw: <T = unknown>(path: string, options?: RequestOptions) => Promise<Response<T>>
}

export function createHttp(options: ClientOptions = {}): Http {
  const base = (options.baseURL ?? '').replace(/\/$/, '')
  const send = options.fetch ?? ((...args: Parameters<typeof fetch>) => globalThis.fetch(...args))

  async function raw<T>(path: string, request: RequestOptions = {}): Promise<Response<T>> {
    const query = new URLSearchParams()

    for (const [key, value] of Object.entries(request.query ?? {})) {
      if (value !== null && value !== undefined) {
        query.set(key, String(value))
      }
    }

    const form = typeof FormData !== 'undefined' && request.body instanceof FormData
    const headers: Record<string, string> = { Accept: 'application/json' }

    if (request.body !== undefined && !form) {
      headers['Content-Type'] = 'application/json'
    }

    let response: globalThis.Response

    try {
      response = await send(`${base}${path}${query.size > 0 ? `?${query}` : ''}`, {
        method: request.method ?? 'GET',
        headers,
        body: request.body === undefined ? undefined : form ? request.body as FormData : JSON.stringify(request.body),
        credentials: 'same-origin',
        signal: request.signal,
      })
    }
    catch {
      throw new FahobaError(0, null)
    }

    const text = await response.text()
    let data: unknown = null

    if (text !== '') {
      try {
        data = JSON.parse(text)
      }
      catch {
        data = text
      }
    }

    if (!response.ok) {
      throw new FahobaError(response.status, typeof data === 'object' ? data as FahobaError['data'] : null)
    }

    return { status: response.status, data: data as T, headers: response.headers }
  }

  return {
    raw,
    request: async <T>(path: string, request?: RequestOptions) => (await raw<T>(path, request)).data,
  }
}

/** Whether a failed call means the PIN, with or without the client's error class. */
export function needsPin(error: unknown): boolean {
  return error instanceof FahobaError && error.kind === 'pin'
}
