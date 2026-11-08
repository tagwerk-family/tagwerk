/*
 * Pure rules of the statistics view (`GET /api/statistics`). Per-person
 * maps are keyed by person id as a string, `none` is ticked off without a
 * name. A person keeps their colour in every chart; “without a name” is grey.
 */

export interface StatisticsPerson { id: number, name: string, color: string | null }

export interface StatisticsResponse {
  period: { from: string, until: string, days: number, bucket: 'day' | 'week' | 'month' }
  people: StatisticsPerson[]
  totals: { done: number, due: number, kept: number, earned: number, spent: number }
  tasks: { id: number, title: string, icon: string | null, counts: Record<string, number>, total: number }[]
  timeline: { labels: string[], series: Record<string, number[]> }
  weekdays: Record<string, number[]>
  reliability: Record<string, { due: number, done: number }>
  stars: Record<string, { earned: number, spent: number }>
}

export interface Series { key: string, name: string, color: string }

export const NOBODY = 'none'

/** Grey: nobody's colour, so it never reads as one of the family. */
export const NOBODY_COLOR = '#9ca3af'

const HEX = /^#[0-9a-f]{6}$/i

/** People in the family's order that occur in `keys`, then “without a name”. */
export function seriesOrder(people: StatisticsPerson[], keys: Iterable<string>, nobody: string): Series[] {
  const present = new Set(keys)
  const order: Series[] = people
    .filter(person => present.has(String(person.id)))
    .map(person => ({
      key: String(person.id),
      name: person.name,
      color: person.color !== null && HEX.test(person.color) ? person.color : NOBODY_COLOR,
    }))

  if (present.has(NOBODY)) {
    order.push({ key: NOBODY, name: nobody, color: NOBODY_COLOR })
  }

  return order
}

export interface StackSegment { key: string, color: string, name: string, value: number, share: number }

export interface StackColumn { total: number, share: number, segments: StackSegment[] }

/**
 * Stacked columns: one per step, segments in the series' order. Shares are
 * percent of the tallest column, so the tallest fills the plot.
 */
export function stacks(series: Record<string, number[]>, order: Series[]): StackColumn[] {
  const length = Math.max(0, ...order.map(entry => series[entry.key]?.length ?? 0))
  const totals = Array.from({ length }, (_, index) =>
    order.reduce((sum, entry) => sum + (series[entry.key]?.[index] ?? 0), 0))
  const max = Math.max(1, ...totals)

  return totals.map((total, index) => ({
    total,
    share: total / max * 100,
    segments: order
      .map(entry => ({ ...entry, value: series[entry.key]?.[index] ?? 0 }))
      .filter(segment => segment.value > 0)
      .map(segment => ({ ...segment, share: segment.value / max * 100 })),
  }))
}

/** Percent done of what was due, or null when nothing was. */
export function rate(due: number, done: number): number | null {
  return due > 0 ? Math.round(done / due * 100) : null
}

/**
 * How strongly a cell of the task table is tinted: nothing for zero, then
 * from a faint 12 to 90 percent, so a single time is still visible.
 */
export function heat(count: number, max: number): number {
  return count === 0 ? 0 : Math.round(12 + count / Math.max(1, max) * 78)
}

/** Whoever has the most done in the table; nobody without a name wins. */
export function busiest(tasks: StatisticsResponse['tasks']): { key: string, count: number } | null {
  const sums = new Map<string, number>()

  for (const task of tasks) {
    for (const [key, count] of Object.entries(task.counts)) {
      if (key !== NOBODY) {
        sums.set(key, (sums.get(key) ?? 0) + count)
      }
    }
  }

  let best: { key: string, count: number } | null = null

  for (const [key, count] of sums) {
    if (best === null || count > best.count) {
      best = { key, count }
    }
  }

  return best
}

/** Every how many steps a label fits below the columns. */
export function labelStep(steps: number, room: number): number {
  return Math.max(1, Math.ceil(steps / Math.max(1, room)))
}
