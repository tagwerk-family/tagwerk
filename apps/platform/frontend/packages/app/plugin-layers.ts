import { existsSync, readFileSync } from 'node:fs'
import { join, resolve } from 'node:path'

interface ComposerPackage {
  name: string
  dist?: { type?: string, url?: string }
  extra?: { fahoba?: { frontend?: string } }
}

/*
 * The Nuxt layers of the installed plugins, found at build time.
 *
 * Installed means: in Composer's install data. In development that is
 * `vendor/composer/installed.json` (dev packages included); the image's
 * frontend stage has no vendor directory and reads `composer.lock`, whose
 * `packages` leave the dev ones out — the example plugin stays out of
 * production. Only path packages under `plugins/` can bring a layer: a
 * package from Packagist has no sources here at build time.
 */
export function pluginLayers(platformRoot: string): string[] {
  return installedPackages(platformRoot)
    .filter(entry => entry.dist?.type === 'path' && entry.dist.url !== undefined && entry.extra?.fahoba?.frontend !== undefined)
    .sort((a, b) => a.name.localeCompare(b.name))
    .map(entry => resolve(platformRoot, entry.dist!.url!, entry.extra!.fahoba!.frontend!))
    .filter(layer => existsSync(join(layer, 'nuxt.config.ts')))
}

function installedPackages(platformRoot: string): ComposerPackage[] {
  const installed = join(platformRoot, 'vendor/composer/installed.json')

  if (existsSync(installed)) {
    return (JSON.parse(readFileSync(installed, 'utf8')) as { packages?: ComposerPackage[] }).packages ?? []
  }

  const lock = join(platformRoot, 'composer.lock')

  if (existsSync(lock)) {
    return (JSON.parse(readFileSync(lock, 'utf8')) as { packages?: ComposerPackage[] }).packages ?? []
  }

  return []
}
