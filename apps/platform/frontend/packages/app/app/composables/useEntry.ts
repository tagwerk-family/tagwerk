import { type Entry, entryFromUrl, entryWithoutAnswer } from '~/utils/entry'

// A convenience for starting without a network, not a token.
const REMEMBERED = 'fahoba-entry'

function remembered(): string | null {
  try {
    return localStorage.getItem(REMEMBERED)
  }
  catch {
    return null
  }
}

function remember(entry: Entry): void {
  try {
    localStorage.setItem(REMEMBERED, entry)
  }
  catch {
    // Blocked storage: then only the server decides.
  }
}

/**
 * Wall or phone shell. Asked once at start; the start page switches by hand
 * ("Am Handy anmelden"), and the sign-in screen back.
 */
export function useEntry() {
  const entry = useState<Entry | null>('entry', () => null)

  async function decide(): Promise<void> {
    const linked = entryFromUrl(window.location.search)

    if (linked !== null) {
      entry.value = linked

      return
    }

    try {
      const { kind } = await $fetch<{ kind: Entry }>('/api/session', {
        baseURL: '/',
        credentials: 'same-origin',
      })

      remember(kind)
      entry.value = kind
    }
    catch (error: unknown) {
      entry.value = entryWithoutAnswer((error as { status?: number }).status, remembered())
    }
  }

  function choose(next: Entry): void {
    entry.value = next
  }

  return { entry, decide, choose }
}
