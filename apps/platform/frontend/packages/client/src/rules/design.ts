/** An installed design pack, as /api/theme describes it. */
export interface Design {
  key: string
  name: string
  url: string
}

/** The same key as in `public/theme.js`, which loads it before the first paint. */
export const DESIGN_STORAGE = 'fahoba-design'

const KEY = /^[a-z0-9][a-z0-9-]*\/[a-z0-9][a-z0-9-]*$/
const URL = /^\/api\/design\/[a-z0-9][a-z0-9-]*\/[a-z0-9][a-z0-9-]*\/[0-9a-f]{12}\.css$/

/**
 * A design read back from storage, or null. Only a stylesheet of this
 * server: whatever landed in storage must not load a foreign one.
 */
export function storedDesign(raw: string | null): Design | null {
  if (raw === null) {
    return null
  }

  try {
    const value: unknown = JSON.parse(raw)

    if (typeof value !== 'object' || value === null) {
      return null
    }

    const { key, name, url } = value as Record<string, unknown>

    return typeof key === 'string' && KEY.test(key) && typeof name === 'string' && typeof url === 'string' && URL.test(url)
      ? { key, name, url }
      : null
  }
  catch {
    return null
  }
}
