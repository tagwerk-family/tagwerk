import { describe, expect, it } from 'vitest'
import { createClient, needsPin, FahobaError } from '../src'

type Call = { url: string, init: RequestInit }

function server(status: number, body: unknown, calls: Call[] = []): typeof fetch {
  return async (input, init) => {
    calls.push({ url: String(input), init: init ?? {} })

    return new Response(body === null ? '' : JSON.stringify(body), { status })
  }
}

describe('createClient', () => {
  it('sends the cookie along and JSON in, JSON out', async () => {
    const calls: Call[] = []
    const fahoba = createClient({ fetch: server(200, { kind: 'device' }, calls) })

    expect(await fahoba.session()).toEqual({ kind: 'device' })
    expect(calls[0]?.url).toBe('/api/session')
    expect(calls[0]?.init.credentials).toBe('same-origin')
  })

  it('builds the agenda query and leaves empty parameters out', async () => {
    const calls: Call[] = []
    const fahoba = createClient({ baseURL: 'https://fahoba.example/', fetch: server(200, {}, calls) })

    await fahoba.agenda('2026-11-02', '2026-11-08')
    await fahoba.request('/api/wall/tasks', { query: { from: '2026-11-02', person: null } })

    expect(calls[0]?.url).toBe('https://fahoba.example/api/wall/agenda?from=2026-11-02&to=2026-11-08')
    expect(calls[1]?.url).toBe('https://fahoba.example/api/wall/tasks?from=2026-11-02')
  })

  it('trims a typed pairing code', async () => {
    const calls: Call[] = []

    await createClient({ fetch: server(200, {}, calls) }).pair(' ab12cd ')

    expect(JSON.parse(String(calls[0]?.init.body))).toEqual({ code: 'ab12cd' })
  })
})

describe('refusals', () => {
  it.each([
    [401, 'unauthenticated'],
    [402, 'read-only'],
    [403, 'forbidden'],
    [404, 'not-found'],
    [422, 'invalid'],
    [423, 'pin'],
    [429, 'throttled'],
    [500, 'unavailable'],
  ])('%i means %s', async (status, kind) => {
    const failure = await createClient({ fetch: server(status, { message: 'Nein' }) }).me().catch((error: unknown) => error)

    expect(failure).toBeInstanceOf(FahobaError)
    expect((failure as FahobaError).kind).toBe(kind)
  })

  it('names the first field error for a form', async () => {
    const failure = await createClient({ fetch: server(422, { message: 'Invalid', errors: { email: ['Unknown address'] } }) })
      .login('x@example.org', 'secret')
      .catch((error: FahobaError) => error)

    expect((failure as FahobaError).firstMessage).toBe('Unknown address')
  })

  it('tells a PIN refusal apart', async () => {
    const failure = await createClient({ fetch: server(423, null) }).request('/api/wall/tasks', { method: 'POST' }).catch((error: unknown) => error)

    expect(needsPin(failure)).toBe(true)
    expect(needsPin(new Error('x'))).toBe(false)
  })

  it('reports an unreachable server as unavailable', async () => {
    const failure = await createClient({ fetch: async () => { throw new TypeError('offline') } }).session().catch((error: FahobaError) => error)

    expect((failure as FahobaError).kind).toBe('unavailable')
    expect((failure as FahobaError).status).toBe(0)
  })
})
