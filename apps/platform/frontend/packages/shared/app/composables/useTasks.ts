import type { TaskInput, FormOption, LocalDayBonus, LocalTick } from '../utils/task'
import { keepDayBonus, keepTicks, occurrenceKey, occurrenceSlot, withDayBonus } from '../utils/task'
import { isOffline } from '../utils/network'
import { holdAnnouncements } from '../utils/announcements'

import type { BonusDto, OccurrenceDto } from '@fahoba/client'

// The payloads live in the client SDK; re-exported so the layer's imports stay.
export type { BonusDto, OccurrenceDto }

/*
 * This device's ticks the server may not have shown yet, and a step counter
 * that orders loads and confirmations (see `keepTicks`). A clock would do
 * too, until the tablet sets its time. Not reactive: nothing draws them, and
 * a toggle recognises its own tick by identity, which a proxy would break.
 */
const ticks: Record<string, LocalTick> = {}
const dayBonuses: Record<string, LocalDayBonus> = {}
let step = 0
let latest = 0

export function useTasks() {
  const { t } = useI18n()
  const { cheer, dayBonus: cheerDayBonus } = useCheer()
  const { withPin } = usePinGate()
  const { preferences, set: setPreference } = usePreferences()

  const occurrences = useState<OccurrenceDto[]>('occurrences', () => [])
  const bonus = useState<BonusDto[]>('task-bonus', () => [])
  /** Stars for a day on which a person finished all of their own tasks; 0 is off. */
  const dayBonus = useState<number>('task-day-bonus', () => 0)
  const selection = useState<FormOption>('task-options', () => ({ weekdays: {} }))

  /*
   * The last loaded range. After creating it has to reload — only the server
   * knows which days a rule hits — and the form does not know the view's
   * range.
   */
  const range = useState<{ from: string, until: string } | null>('occurrence-range', () => null)

  /*
   * Whose tasks show; null is "Für alle", empty means everyone. Stored with
   * the device: the tablet in a child's room comes up showing that child
   * again, and the dashboard's tasks tile jumps here with one person chosen.
   */
  const peopleFilter = computed<(number | null)[]>({
    get: () => preferences.value.task_people,
    set: value => void setPreference('task_people', value),
  })
  const error = ref<string | null>(null)

  async function load(from: string, until: string): Promise<void> {
    const startedAt = ++step

    latest = startedAt

    try {
      const data = await $fetch<{ occurrences: OccurrenceDto[], bonus?: BonusDto[], day_bonus?: number, options: FormOption }>('/api/wall/tasks', {
        params: { from: from, to: until },
        baseURL: '/', credentials: 'same-origin',
      })

      // A later load is already on its way, or done: this answer is older.
      if (latest !== startedAt) {
        return
      }

      occurrences.value = keepTicks(data.occurrences, ticks, startedAt)
      bonus.value = keepDayBonus(data.bonus ?? [], dayBonuses, startedAt)
      dayBonus.value = data.day_bonus ?? 0
      selection.value = data.options
      range.value = { from, until }
      error.value = null
    }
    catch {
      error.value = t('tasks.loadFailed')
    }
  }

  async function reload(): Promise<void> {
    if (range.value) {
      await load(range.value.from, range.value.until)
    }
  }

  function current(key: string): OccurrenceDto | undefined {
    return occurrences.value.find(entry => occurrenceKey(entry) === key)
  }

  /**
   * Checking off takes effect immediately and is reverted on failure.
   *
   * The way back matters more than with the calendar: a child that checks off
   * and sees nothing happen keeps tapping -- and a credit of stars depends
   * on it.
   */
  async function toggle(tapped: OccurrenceDto, personId: number | null = null, skip = false): Promise<void> {
    if (isOffline()) {
      error.value = t('network.offline')

      return
    }

    /*
     * The dialog holds the row from when it opened. A reload meanwhile — the
     * previous tick's, held until the device was quiet — replaced it, and a
     * tick on the old object showed only with the reload after it.
     */
    const entry = current(occurrenceKey(tapped)) ?? tapped
    const before = entry.done
    const wasSkipped = entry.skipped ?? false
    const key = occurrenceKey(entry)
    const tick: LocalTick = { done: !before, done_by_id: entry.done_by_id ?? null, earned: entry.earned ?? 0, at: Infinity }

    ticks[key] = tick
    holdAnnouncements()
    entry.done = !before
    entry.skipped = skip

    // Cheered at once, not after the server: the tap is the moment.
    if (!before && !skip) {
      cheer(entry.points)
    }

    const credited = { id: entry.done_by_id ?? null, earned: entry.earned ?? 0 }

    try {
      const send = () => $fetch<{ points?: number, day_bonus?: { person_id: number, points: number } | null } | null>(`/api/wall/tasks/${entry.task_id}/completion`, {
        method: before ? 'DELETE' : 'POST',
        body: { date: entry.date, person_id: personId, ...(skip ? { skipped: true } : {}), ...occurrenceSlot(entry) },
        baseURL: '/', credentials: 'same-origin',
      })
      // Skipping is a parent's decision; on the wall it asks for the PIN.
      const response = skip ? await withPin(send) : await send()

      // Until the next load, the same chain the server follows for whom it books.
      tick.done_by_id = before || skip ? null : personId ?? entry.for_person_id ?? entry.person_id
      tick.earned = before ? 0 : response?.points ?? 0

      // A tap on the same task meanwhile owns the slot now.
      if (ticks[key] === tick) {
        tick.at = ++step
      }

      // A load may have replaced the row meanwhile; the one on screen counts.
      Object.assign(current(key) ?? entry, { done_by_id: tick.done_by_id, earned: tick.earned, skipped: skip })
      error.value = null

      /*
       * The day bonus shows in the column's "+n" at once, not with the next
       * load — and earning it is the big moment of the day.
       */
      const settled = response?.day_bonus

      if (settled) {
        // What is booked now, not the change: a load may already hold it.
        const booked = Math.max(settled.points, 0)

        dayBonuses[`${settled.person_id}|${entry.date}`] = { points: booked, at: ++step }
        bonus.value = withDayBonus(bonus.value, settled.person_id, entry.date, booked)

        if (settled.points > 0) {
          cheerDayBonus(settled.points)
        }
      }
    }
    catch {
      if (ticks[key] === tick) {
        delete ticks[key]
      }

      Object.assign(current(key) ?? entry, { done: before, done_by_id: credited.id, earned: credited.earned, skipped: wasSkipped })
      error.value = t('common.changeNotSaved')
    }
  }

  /**
   * Swap a single week of the rotation; `null` restores the order.
   *
   * Deliberately **no** anticipation here: the server derives from the rule
   * whose turn it is, and when resetting the wall does not even know the
   * result. So it adopts what the response names.
   */
  async function assign(entry: OccurrenceDto, personId: number | null): Promise<boolean> {
    if (isOffline()) {
      error.value = t('network.offline')

      return false
    }

    try {
      const response = await withPin(() => $fetch<{
        person_id: number | null
        person_name: string | null
        color: string | null
      }>(`/api/wall/tasks/${entry.task_id}/assignment`, {
        method: personId === null ? 'DELETE' : 'PUT',
        body: { date: entry.date, person_id: personId },
        baseURL: '/', credentials: 'same-origin',
      }))

      entry.person_id = response.person_id
      entry.person_name = response.person_name
      entry.color = response.color
      error.value = null

      return true
    }
    catch {
      error.value = t('tasks.assigneeNotChanged')

      return false
    }
  }

  /**
   * Create a task, or change one (`taskId`) — the wall only creates, the
   * phone also changes. On the wall the parent PIN may be asked first; the
   * gate repeats the call after unlocking.
   *
   * Afterwards it reloads instead of inserting: on which days a rule applies
   * is derived by the server, and a hand-made row would stand on the wrong
   * day until the next refresh.
   */
  async function save(input: TaskInput, taskId: number | null = null): Promise<boolean> {
    if (isOffline()) {
      error.value = t('network.offline')

      return false
    }

    try {
      await withPin(() => $fetch<unknown>(taskId === null ? '/api/wall/tasks' : `/api/wall/tasks/${taskId}`, {
        method: taskId === null ? 'POST' : 'PATCH',
        body: input,
        baseURL: '/', credentials: 'same-origin',
      }))

      await reload()
      error.value = null

      return true
    }
    catch {
      error.value = t('tasks.notSaved')

      return false
    }
  }

  /** Remove from the view. Completions and earned stars stay (`Task::retire`). */
  async function remove(taskId: number): Promise<boolean> {
    if (isOffline()) {
      error.value = t('network.offline')

      return false
    }

    try {
      await withPin(() => $fetch<unknown>(`/api/wall/tasks/${taskId}`, {
        method: 'DELETE',
        baseURL: '/', credentials: 'same-origin',
      }))

      await reload()
      error.value = null

      return true
    }
    catch {
      error.value = t('tasks.notRemoved')

      return false
    }
  }

  return { occurrences, bonus, dayBonus, selection, range, peopleFilter, error, load, reload, toggle, assign, save, remove }
}
