import { connectRealtime, createHttp, type Realtime } from '@fahoba/client'

/**
 * Change notifications, so wall and phone do not wait for the next poll.
 *
 * They do not replace polling, they shorten it: if the WebSocket fails,
 * polling remains the fallback. The message itself carries no data, only the
 * affected area — loading happens with the same request as otherwise.
 * Connecting, gathering bursts and the `all` after a lost socket live in
 * `@fahoba/client`, which other frontends use as well.
 */
export function useRealtime(onChange: (areas: string[]) => unknown) {
  let realtime: Realtime | null = null

  /*
   * Counts connection attempts. connect() waits for the settings; if it is
   * connected or disconnected again in the meantime, the older attempt must
   * not afterwards keep a second connection that nobody disconnects.
   */
  let attempt = 0

  async function connect(): Promise<void> {
    disconnect()

    const current = attempt
    const opened = await connectRealtime(createHttp().request, onChange)

    if (current !== attempt) {
      opened.disconnect()

      return
    }

    realtime = opened
  }

  function disconnect(): void {
    attempt++
    realtime?.disconnect()
    realtime = null
  }

  return { connect, disconnect }
}
