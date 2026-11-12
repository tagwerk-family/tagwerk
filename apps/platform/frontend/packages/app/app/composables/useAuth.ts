import type { HouseholdNotice } from '../../../shared/app/utils/notice'
import type { Language } from '../../../shared/app/utils/language'
import { areaFromUrl, householdFromUrl, modeKey } from '~/utils/entry'

/** The service worker's cache (`public/sw.js`); emptied when the household changes. */
const OFFLINE_CACHE = 'fahoba-wall-v1'

export interface UserDto {
  id: number
  name: string
  email: string
  person_id: number | null
  /** What the phone offers as buttons; the server checks every write again. */
  permissions: string[]
  /** The language in effect; the own choice is null when following the household. */
  locale: string
  /** The same with what switching needs; older servers send `locale` only. */
  language?: Language
  locale_preference: string | null
  locales: Record<string, string>
  /** The households to switch between; one means there is nothing to switch. */
  households?: HouseholdDto[]
  /** Households waiting for a yes. */
  invitations?: { id: number, name: string }[]
  /** A plugin's word to the household (trial, read-only); shown throughout. */
  notices?: HouseholdNotice[]
}

export interface HouseholdDto {
  id: number
  name: string
  current: boolean
}

/** Password or link were right, but the account also needs its app's code. */
interface SecondFactorDto {
  two_factor: string
}

type SignInDto = UserDto | SecondFactorDto

export function useAuth() {
  const { t } = useI18n()

  const user = useState<UserDto | null>('user', () => null)
  const checked = useState<boolean>('auth-checked', () => false)
  const error = ref<string | null>(null)

  /**
   * The ticket while the server waits for the authenticator app's code. It
   * signs in nothing by itself and lives five minutes on the server.
   */
  const ticket = useState<string | null>('auth-ticket', () => null)

  function signedIn(response: SignInDto): boolean {
    if ('two_factor' in response) {
      ticket.value = response.two_factor

      return false
    }

    ticket.value = null
    user.value = response

    return true
  }

  /**
   * Asks for the signed-in user.
   *
   * The token lives in an httpOnly cookie and is invisible to JavaScript —
   * so the application can only ask the server whether someone is signed
   * in.
   */
  async function check(): Promise<void> {
    try {
      user.value = await $fetch<UserDto | null>('/api/auth/me', {
        baseURL: '/', credentials: 'same-origin',
      })

      followNotification()
    }
    catch {
      user.value = null
    }
    finally {
      checked.value = true
    }
  }

  async function login(email: string, password: string): Promise<boolean> {
    try {
      error.value = null

      return signedIn(await $fetch<SignInDto>('/api/auth/login', {
        method: 'POST',
        body: { email, password: password },
        baseURL: '/', credentials: 'same-origin',
      }))
    }
    catch (exception) {
      const response = (exception as { response?: { status?: number, _data?: { errors?: Record<string, string[]> } } })?.response

      error.value = response?.status === 429
        ? t('auth.tooMany')
        : response?.status === 422 ? response._data?.errors?.email?.[0] ?? t('auth.wrong') : t('auth.wrong')

      return false
    }
  }

  /** The authenticator app's code (or a recovery code) for the waiting ticket. */
  async function confirmSecondFactor(code: string): Promise<boolean> {
    try {
      error.value = null

      return signedIn(await $fetch<SignInDto>('/api/auth/two-factor', {
        method: 'POST',
        body: { ticket: ticket.value, code },
        baseURL: '/', credentials: 'same-origin',
      }))
    }
    catch (exception) {
      const status = (exception as { response?: { status?: number } })?.response?.status

      error.value = status === 429 ? t('auth.tooMany') : t('login.factorWrong')

      return false
    }
  }

  /** Always "sent": the server does not tell whether the address exists. */
  async function requestReset(email: string): Promise<boolean> {
    try {
      await $fetch('/api/auth/password/forgot', {
        method: 'POST',
        body: { email },
        baseURL: '/', credentials: 'same-origin',
      })

      error.value = null

      return true
    }
    catch (exception) {
      const status = (exception as { response?: { status?: number } })?.response?.status

      error.value = status === 429
        ? t('auth.tooMany')
        : t('auth.checkEmail')

      return false
    }
  }

  /** Always "sent": the server does not tell whether the address exists. */
  async function requestLink(email: string): Promise<boolean> {
    try {
      await $fetch('/api/auth/link', {
        method: 'POST',
        body: { email },
        baseURL: '/', credentials: 'same-origin',
      })

      error.value = null

      return true
    }
    catch (exception) {
      const status = (exception as { response?: { status?: number } })?.response?.status

      error.value = status === 429
        ? t('auth.tooMany')
        : t('auth.checkEmail')

      return false
    }
  }

  /** Signs in with the link from the mail (token) or the code typed in. */
  async function redeemLink(email: string, secret: { token?: string, code?: string }): Promise<boolean> {
    try {
      error.value = null

      return signedIn(await $fetch<SignInDto>('/api/auth/link/redeem', {
        method: 'POST',
        body: { email, ...secret },
        baseURL: '/', credentials: 'same-origin',
      }))
    }
    catch (exception) {
      const response = (exception as { response?: { status?: number, _data?: { errors?: Record<string, string[]> } } })?.response
      const errors = response?._data?.errors

      error.value = response?.status === 429
        ? t('auth.tooMany')
        : errors?.email?.[0] ?? errors?.code?.[0] ?? t('common.failed')

      return false
    }
  }

  /**
   * Sets the password from a reset or invitation link, then signs in with it.
   * Both links carry a token of their own kind; the server keeps them apart.
   */
  async function resetPassword(kind: 'reset' | 'invite', token: string, email: string, password: string, confirmation: string): Promise<boolean> {
    try {
      await $fetch(kind === 'invite' ? '/api/auth/invitation' : '/api/auth/password/reset', {
        method: 'POST',
        body: { token, email, password, password_confirmation: confirmation },
        baseURL: '/', credentials: 'same-origin',
      })
    }
    catch (exception) {
      const response = (exception as { response?: { status?: number, _data?: { errors?: Record<string, string[]> } } })?.response
      const errors = response?._data?.errors

      error.value = response?.status === 429
        ? t('auth.tooMany')
        : errors?.password?.[0] ?? errors?.token?.[0] ?? t('common.failed')

      return false
    }

    return login(email, password)
  }

  /**
   * Looks at another household. The page loads anew: every area keeps its
   * state in `useState`, and a reload is the one way to be sure nothing of
   * the other family stays on screen. The offline copy of the agenda goes
   * too — offline, grandma would otherwise see the other family's events.
   */
  async function switchHousehold(id: number): Promise<void> {
    await $fetch<UserDto>('/api/auth/household', {
      method: 'PUT',
      body: { household_id: id },
      baseURL: '/', credentials: 'same-origin',
    })

    await forgetHousehold()
  }

  /** Leaves the household on screen; the server refuses for its last manager. */
  async function leaveHousehold(): Promise<string | null> {
    try {
      await $fetch('/api/auth/household', {
        method: 'DELETE',
        baseURL: '/', credentials: 'same-origin',
      })
    }
    catch (exception) {
      const errors = (exception as { response?: { _data?: { errors?: Record<string, string[]> } } })?.response?._data?.errors

      return errors?.household?.[0] ?? t('common.failed')
    }

    await forgetHousehold()

    return null
  }

  async function answerInvitation(id: number, accept: boolean): Promise<void> {
    user.value = await $fetch<UserDto>(`/api/auth/invitations/${id}`, {
      method: accept ? 'POST' : 'DELETE',
      baseURL: '/', credentials: 'same-origin',
    })
  }

  /*
   * A tapped notification names its household and the area that handles it;
   * the parameters go from the address at once, so a reload does not switch
   * back. The area becomes the one the account remembers for that household's
   * session — the shell starts there, after the household switch's reload too.
   */
  function followNotification(): void {
    const search = window.location.search
    const query = new URLSearchParams(search)

    if (!query.has('household') && !query.has('area')) {
      return
    }

    const households = user.value?.households ?? []
    const target = householdFromUrl(search, households)
    const area = areaFromUrl(search)
    window.history.replaceState(null, '', window.location.pathname)

    if (area !== null) {
      try {
        sessionStorage.setItem(modeKey(target ?? households.find(entry => entry.current)?.id), area)
      }
      catch {
        // Blocked storage (private window): the start area applies.
      }
    }

    if (target !== null) {
      void switchHousehold(target)
    }
  }

  async function forgetHousehold(): Promise<void> {
    if ('caches' in window) {
      await caches.delete(OFFLINE_CACHE).catch(() => false)
    }

    window.location.replace('/')
  }

  /** Null follows the household again. */
  async function setLanguage(code: string | null): Promise<void> {
    user.value = await $fetch<UserDto>('/api/auth/locale', {
      method: 'PUT',
      body: { locale: code },
      baseURL: '/', credentials: 'same-origin',
    })
  }

  /** The households deleting this account would take along: it is their last manager. */
  async function householdsLost(): Promise<{ id: number, name: string }[]> {
    const answer = await $fetch<{ households_lost: { id: number, name: string }[] }>('/api/auth/account', {
      baseURL: '/', credentials: 'same-origin',
    })

    return answer.households_lost
  }

  /** Null when the account is gone; otherwise the server's reason. */
  async function deleteAccount(password: string, confirm: string[]): Promise<string | null> {
    try {
      await $fetch<unknown>('/api/auth/account', {
        method: 'DELETE',
        body: { password, confirm },
        baseURL: '/', credentials: 'same-origin',
      })
    }
    catch (exception) {
      const errors = (exception as { response?: { _data?: { errors?: Record<string, string[]> } } })?.response?._data?.errors

      return errors?.password?.[0] ?? errors?.confirm?.[0] ?? t('common.failed')
    }

    return null
  }

  async function logout(): Promise<void> {
    await $fetch('/api/auth/logout', {
      method: 'POST',
      baseURL: '/', credentials: 'same-origin',
    }).catch(() => {})

    user.value = null
  }

  return { user, checked, error, ticket, check, login, confirmSecondFactor, requestReset, resetPassword, requestLink, redeemLink, setLanguage, switchHousehold, leaveHousehold, answerInvitation, householdsLost, deleteAccount, logout }
}
