import type { HouseholdNotice } from '../../../shared/app/utils/notice'
import type { PhotoDto } from '~/composables/usePhotos'
import type { AgendaPerson } from '../../../shared/app/utils/person'
import type { AgendaArea, Area, FeatureKey } from '~/utils/areas'
import { asAreas } from '~/utils/areas'
import { coversRange } from '~/utils/agenda'
import type { AgendaEvent, Holiday } from '~/utils/calendar'
import type { Language } from '../../../shared/app/utils/language'
import type { Lesson } from '../../../shared/app/utils/timetable'

export type { AgendaPerson } from '../../../shared/app/utils/person'

export interface AgendaCalendar {
  id: number
  summary: string
  color: string | null
  person_ids: number[]
  /** False when the account behind it is signed out. */
  writable: boolean
}

export interface Agenda {
  household: { name: string, timezone: string, features: FeatureKey[], areas: AgendaArea[], notices?: HouseholdNotice[] }
  /** The tablet's own language, else the household's. */
  locale: string
  /** The same with what switching needs; older servers send `locale` only. */
  language?: Language
  generated_at: string
  app_version: string
  viewer: 'device' | 'user'
  range: { from: string, to: string }
  people: AgendaPerson[]
  calendars: AgendaCalendar[]
  events: AgendaEvent[]
  holidays: Holiday[]
  /** School lessons of the range for the calendar band; empty when switched off. */
  lessons: Lesson[]
  /** A picture a phone sent to the walls; only a wall gets one. */
  shown_photo?: { key: string, photo: PhotoDto, until: string } | null
}

/**
 * Fetching the wall data.
 *
 * The wall's ground rule: it must never show an empty page. A failed request
 * therefore discards nothing — the last successfully loaded data stays and
 * is only marked as stale.
 */
/*
 * Only the latest request may paint. Paging fires one request per tap, and
 * their answers do not arrive in order: an older one landing last showed the
 * events of days no longer on screen, so the calendar looked empty.
 */
let latestRequest = 0

export function useAgenda() {
  const agenda = useState<Agenda | null>('agenda', () => null)
  const asOf = useState<string | null>('agenda-as-of', () => null)
  const stale = useState('agenda-stale', () => false)
  const version = useState<string | null>('agenda-version', () => null)

  // null = not checked yet. Without this third state the wall would briefly
  // show the pairing screen at start although the device is paired.
  const paired = useState<boolean | null>('agenda-paired', () => null)
  const { set: setActor } = useActor()
  const { user } = useAuth()

  async function load(from: string, until: string): Promise<void> {
    const request = ++latestRequest

    try {
      // raw, because the service worker's marker is in the header.
      const response = await $fetch.raw<Agenda>('/api/wall/agenda', {
        params: { from: from, to: until },
        credentials: 'same-origin',
      })

      const data = response._data

      if (!data || request !== latestRequest) {
        return
      }

      /*
       * Version check: a wall tablet is never reloaded by hand. Without this
       * step an old version would stay stuck there after a deploy.
       */
      if (version.value !== null && version.value !== data.app_version) {
        window.location.reload()

        return
      }

      version.value = data.app_version

      // An account's actor comes from its sign-in, with its permissions.
      if (data.viewer === 'device') {
        setActor({ kind: 'device', personId: null, permissions: [] })
      }

      /*
       * The service worker's fallback is the last agenda it saw, whatever
       * days that was for. Over a shown calendar it would swap in other days'
       * events — keep the display and mark it stale instead. At a cold start
       * without network it is still better than an empty page.
       */
      if (agenda.value !== null && !coversRange(data.range, from, until)) {
        stale.value = true
        paired.value = true

        return
      }

      agenda.value = data
      asOf.value = data.generated_at
      paired.value = true

      // The service worker answered from its cache: the display is
      // correct but no longer current.
      stale.value = response.headers.get('x-fahoba-cache') === 'hit'
    }
    catch (error: unknown) {
      if (request !== latestRequest) {
        return
      }

      const status = (error as { status?: number }).status

      if (status === 401) {
        paired.value = false
        setActor(null)
        // An account signed out elsewhere (password changed): back to the sign-in.
        user.value = null

        return
      }

      // Everything else: network gone, server gone, timeout. Keep the display,
      // mark the state as stale.
      stale.value = true
      paired.value ??= true
    }
  }

  /*
   * Until the first response arrives the bar stays empty — without a network
   * the service worker answers with the last state, so this is only a
   * fraction of a second at start.
   */
  const areas = computed<Area[]>(() => asAreas(agenda.value?.household.areas ?? []))

  return { agenda, asOf, stale, paired, areas, load }
}
