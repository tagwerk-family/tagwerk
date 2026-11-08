import { describe, expect, it } from 'vitest'
import { busiest, heat, labelStep, NOBODY_COLOR, rate, seriesOrder, stacks } from '../app/utils/statistics'

const people = [
  { id: 3, name: 'Max', color: '#059669' },
  { id: 1, name: 'Lena', color: '#d97706' },
  { id: 2, name: 'Alex', color: 'red' },
]

describe('seriesOrder', () => {
  it('keeps the family order and only who occurs, nobody last in grey', () => {
    expect(seriesOrder(people, ['none', '1', '3'], 'Ohne Namen')).toEqual([
      { key: '3', name: 'Max', color: '#059669' },
      { key: '1', name: 'Lena', color: '#d97706' },
      { key: 'none', name: 'Ohne Namen', color: NOBODY_COLOR },
    ])
  })

  it('never passes on a colour that is no hex', () => {
    expect(seriesOrder(people, ['2'], '')[0]?.color).toBe(NOBODY_COLOR)
  })
})

describe('stacks', () => {
  const order = seriesOrder(people, ['3', '1'], '')

  it('stacks in the series order, scaled to the tallest column', () => {
    const columns = stacks({ 3: [2, 0, 1], 1: [2, 0, 0] }, order)

    expect(columns.map(column => column.total)).toEqual([4, 0, 1])
    expect(columns[0]?.share).toBe(100)
    expect(columns[0]?.segments.map(segment => [segment.name, segment.share])).toEqual([['Max', 50], ['Lena', 50]])
    expect(columns[1]?.segments).toEqual([])
    expect(columns[2]?.share).toBe(25)
  })

  it('draws empty columns without dividing by zero', () => {
    expect(stacks({ 3: [0, 0] }, order).map(column => column.share)).toEqual([0, 0])
  })
})

describe('rate', () => {
  it('rounds the share and has none without anything due', () => {
    expect(rate(3, 2)).toBe(67)
    expect(rate(0, 0)).toBeNull()
  })
})

describe('heat', () => {
  it('leaves zero blank and keeps one time visible', () => {
    expect(heat(0, 10)).toBe(0)
    expect(heat(1, 10)).toBe(20)
    expect(heat(10, 10)).toBe(90)
  })
})

describe('busiest', () => {
  it('sums across tasks and ignores nobody', () => {
    expect(busiest([
      { id: 1, title: 'A', icon: null, counts: { 1: 2, none: 9 }, total: 11 },
      { id: 2, title: 'B', icon: null, counts: { 3: 3, 1: 2 }, total: 5 },
    ])).toEqual({ key: '1', count: 4 })
    expect(busiest([])).toBeNull()
  })
})

describe('labelStep', () => {
  it('thins labels to the room there is', () => {
    expect(labelStep(28, 7)).toBe(4)
    expect(labelStep(7, 7)).toBe(1)
    expect(labelStep(13, 0)).toBe(13)
  })
})
