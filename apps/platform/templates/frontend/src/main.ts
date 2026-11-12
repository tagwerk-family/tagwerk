import { createClient, FahobaError, type Agenda } from '@fahoba/client'
import './style.css'

/*
 * A deliberately small frontend: who is this (wall or account), sign in or
 * pair if nobody, then the coming week. Replace all of it — what stays is
 * the pattern: the server decides, the screen shows, realtime says when to
 * load again.
 */
const fahoba = createClient()
const app = document.querySelector<HTMLElement>('#app')!

function element<K extends keyof HTMLElementTagNameMap>(tag: K, text?: string, attributes: Record<string, string> = {}): HTMLElementTagNameMap[K] {
  const node = document.createElement(tag)

  if (text !== undefined) {
    node.textContent = text
  }

  for (const [name, value] of Object.entries(attributes)) {
    node.setAttribute(name, value)
  }

  return node
}

/** Days are the household's: `YYYY-MM-DD` in its time zone, never the device's. */
function today(timeZone: string): string {
  return new Intl.DateTimeFormat('en-CA', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date())
}

function addDays(date: string, days: number): string {
  const day = new Date(`${date}T00:00:00Z`)

  day.setUTCDate(day.getUTCDate() + days)

  return day.toISOString().slice(0, 10)
}

async function start(): Promise<void> {
  try {
    await fahoba.session()
  }
  catch (error) {
    if (error instanceof FahobaError && error.kind === 'unauthenticated') {
      showEntry()

      return
    }

    throw error
  }

  // The household's zone comes with the agenda; a first call for today in UTC finds it.
  const first = await fahoba.agenda(today('UTC'), today('UTC'))
  const zone = first.household.timezone
  const load = async (): Promise<void> => {
    const from = today(zone)

    showAgenda(await fahoba.agenda(from, addDays(from, 6)))
  }

  await load()
  await fahoba.realtime(() => load())
}

function showEntry(): void {
  const pairing = element('form')
  const code = element('input', undefined, { placeholder: 'Pairing code from the admin', autocomplete: 'off' })
  const signIn = element('form')
  const email = element('input', undefined, { type: 'email', placeholder: 'Email', autocomplete: 'username' })
  const password = element('input', undefined, { type: 'password', placeholder: 'Password', autocomplete: 'current-password' })
  const message = element('p', '', { class: 'error', role: 'alert' })

  pairing.append(element('h2', 'Wall tablet'), code, element('button', 'Pair'))
  signIn.append(element('h2', 'Account'), email, password, element('button', 'Sign in'))

  pairing.addEventListener('submit', async (event) => {
    event.preventDefault()
    await fahoba.pair(code.value).then(start, (error: FahobaError) => { message.textContent = error.firstMessage ?? 'Pairing failed.' })
  })

  signIn.addEventListener('submit', async (event) => {
    event.preventDefault()
    const answer = await fahoba.login(email.value, password.value).catch((error: FahobaError) => error)

    if (answer instanceof FahobaError) {
      message.textContent = answer.firstMessage ?? 'Sign-in failed.'
    }
    else if ('two_factor' in answer) {
      message.textContent = 'This account needs its authenticator code — not built into this template yet.'
    }
    else {
      await start()
    }
  })

  app.replaceChildren(element('h1', 'Fahoba'), pairing, signIn, message)
}

function showAgenda(agenda: Agenda): void {
  const zone = agenda.household.timezone
  const people = new Map(agenda.people.map(person => [person.id, person]))
  const dayName = new Intl.DateTimeFormat(navigator.language, { timeZone: zone, weekday: 'long', day: 'numeric', month: 'long' })
  const time = new Intl.DateTimeFormat(navigator.language, { timeZone: zone, hour: '2-digit', minute: '2-digit' })
  const days = new Map<string, HTMLUListElement>()
  const nodes: HTMLElement[] = [element('h1', agenda.household.name)]

  for (const notice of agenda.household.notices) {
    nodes.push(element('p', notice.text, { class: 'notice', role: 'status' }))
  }

  for (const event of agenda.events) {
    const starts = new Date(event.starts_at)
    const day = new Intl.DateTimeFormat('en-CA', { timeZone: zone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(starts)

    if (!days.has(day)) {
      days.set(day, element('ul'))
      nodes.push(element('h2', dayName.format(starts)), days.get(day)!)
    }

    const row = element('li')
    const names = event.people.map(id => people.get(id)?.name).filter(Boolean).join(', ')
    const dot = element('span', undefined, { class: 'dot', 'aria-hidden': 'true' })

    dot.style.background = event.color ?? 'currentColor'
    row.append(dot, `${event.all_day ? '' : `${time.format(starts)} `}${event.title}${names === '' ? '' : ` · ${names}`}`)
    days.get(day)!.append(row)
  }

  if (days.size === 0) {
    nodes.push(element('p', 'Nothing planned this week.'))
  }

  app.replaceChildren(...nodes)
}

start().catch((error: unknown) => {
  app.replaceChildren(element('p', `Fahoba is not reachable: ${String(error)}`, { class: 'error' }))
})
