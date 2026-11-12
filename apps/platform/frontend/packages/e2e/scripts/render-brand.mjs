/*
 * Renders the mark's PNGs through Chromium (and the avatar for GitHub and
 * npm into storage/app/brand). ImageMagick's built-in SVG
 * renderer drops gradients and strokes and paints the tile black.
 *
 *   cd apps/platform/frontend/packages/e2e && node scripts/render-brand.mjs
 *
 * favicon.ico is packed from the written favicon-*.png afterwards, in DDEV:
 *   magick storage/app/brand/favicon-{48,32,16}.png public/favicon.ico
 */
import { chromium } from '@playwright/test'
import { mkdirSync, readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'

const platform = fileURLToPath(new URL('../../../../', import.meta.url))
const app = `${platform}frontend/packages/app/public/`
mkdirSync(`${platform}storage/app/brand`, { recursive: true })

const jobs = [
  [`${app}icon.svg`, `${app}icon-512.png`, 512],
  [`${app}icon.svg`, `${app}icon-192.png`, 192],
  [`${platform}resources/brand/badge.svg`, `${app}badge-96.png`, 96],
  // Square, without rounded corners: GitHub and npm round the avatar themselves.
  [`${platform}resources/brand/avatar.svg`, `${platform}storage/app/brand/avatar-1024.png`, 1024],
  ...[48, 32, 16].map(size => [`${platform}public/favicon.svg`, `${platform}storage/app/brand/favicon-${size}.png`, size]),
]

const browser = await chromium.launch()
const page = await browser.newPage()
for (const [source, target, size] of jobs) {
  await page.setViewportSize({ width: size, height: size })
  const svg = readFileSync(source, 'utf8').replace('<svg ', `<svg width="${size}" height="${size}" `)
  await page.setContent(`<body style="margin:0">${svg}</body>`)
  await page.screenshot({ path: target, omitBackground: true })
}
await browser.close()
