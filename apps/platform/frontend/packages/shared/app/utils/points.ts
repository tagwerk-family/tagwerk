import { zonedIso } from './calendar'

/**
 * The rule for who may request a reward.
 *
 * It also applies in the backend — where it is binding. It is here so the
 * tablet does not explain only after tapping that it is not enough.
 */
export function shortfall(balance: number, cost: number): number {
  return Math.max(0, cost - balance)
}

export function affordable(balance: number, cost: number): boolean {
  return shortfall(balance, cost) === 0
}

/** Sign always visible: "+5" and "−3" can be told apart at a glance. */
export function bookingText(points: number): string {
  return points > 0 ? `+${points}` : `−${Math.abs(points)}`
}

/** Percent of the way, capped at full: a bar never runs out of its frame. */
export function share(have: number, need: number): number {
  return need > 0 ? Math.min(100, Math.round((Math.max(0, have) / need) * 100)) : 0
}

/* Rewards as the API delivers them — to the wall and to the phone. */
export interface RewardDto {
  id: number
  name: string
  /** Emoji for the reward; null means the name is enough. */
  icon: string | null
  description: string | null
  cost: number
}

export interface PendingDto {
  id: number
  reward_id: number
  reward: string | null
  person_id: number
  person: string | null
  /** URL of the picture; null means the initial is enough. */
  avatar: string | null
  cost: number
}

/**
 * A key that changes only when a balance rises.
 *
 * The balance pops when its key changes; keyed on the balance itself, a
 * deduction would pop like praise. A deduction is said quietly.
 */
export function riseKeys(): (id: number, balance: number) => number {
  const seen = new Map<number, { balance: number, key: number }>()

  return (id, balance) => {
    const last = seen.get(id)
    const key = last === undefined ? 0 : balance > last.balance ? last.key + 1 : last.key

    seen.set(id, { balance, key })

    return key
  }
}

/** One day's bookings under its date line. */
export interface BookingDay<T> {
  /** Plain calendar date in the household's zone. */
  iso: string
  entries: T[]
}

/**
 * Bookings split into days, newest first as they come.
 *
 * The day is the household's, not the device's: a booking at 00:30 Vienna
 * time belongs to that morning, even on a tablet set to UTC.
 */
export function bookingDays<T extends { created_at: string | null }>(entries: T[], timeZone: string): BookingDay<T>[] {
  const days: BookingDay<T>[] = []

  for (const entry of entries) {
    const iso = entry.created_at === null ? '' : zonedIso(new Date(entry.created_at), timeZone)
    const last = days.at(-1)

    if (last?.iso === iso) {
      last.entries.push(entry)
    }
    else {
      days.push({ iso, entries: [entry] })
    }
  }

  return days
}
