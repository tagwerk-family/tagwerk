import { mkdirSync, mkdtempSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'
import { pluginLayers } from '../plugin-layers'

function platform(files: Record<string, unknown>, layers: string[]): string {
  const root = mkdtempSync(join(tmpdir(), 'layers-'))

  for (const [path, content] of Object.entries(files)) {
    mkdirSync(join(root, path, '..'), { recursive: true })
    writeFileSync(join(root, path), JSON.stringify(content))
  }

  for (const layer of layers) {
    mkdirSync(join(root, layer), { recursive: true })
    writeFileSync(join(root, layer, 'nuxt.config.ts'), 'export default {}')
  }

  return root
}

const board = { name: 'acme/board', dist: { type: 'path', url: 'plugins/board' }, extra: { fahoba: { frontend: 'frontend' } } }
const hooks = { name: 'acme/hooks', dist: { type: 'path', url: 'plugins/hooks' } }
const remote = { name: 'acme/remote', dist: { type: 'zip', url: 'https://example.org/remote.zip' }, extra: { fahoba: { frontend: 'frontend' } } }

describe('pluginLayers', () => {
  it('takes installed path packages that bring a layer', () => {
    const root = platform({ 'vendor/composer/installed.json': { packages: [board, hooks, remote] } }, ['plugins/board/frontend'])

    expect(pluginLayers(root)).toEqual([join(root, 'plugins/board/frontend')])
  })

  it('falls back to the lock file without a vendor directory', () => {
    const root = platform({ 'composer.lock': { packages: [board], 'packages-dev': [] } }, ['plugins/board/frontend'])

    expect(pluginLayers(root)).toEqual([join(root, 'plugins/board/frontend')])
  })

  it('leaves out dev packages of the lock file', () => {
    const root = platform({ 'composer.lock': { packages: [], 'packages-dev': [board] } }, ['plugins/board/frontend'])

    expect(pluginLayers(root)).toEqual([])
  })

  it('skips a declared layer that is not there', () => {
    const root = platform({ 'vendor/composer/installed.json': { packages: [board] } }, [])

    expect(pluginLayers(root)).toEqual([])
  })

  it('finds nothing without composer data', () => {
    expect(pluginLayers(platform({}, []))).toEqual([])
  })
})
