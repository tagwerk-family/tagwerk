<script setup lang="ts">
const { t } = useI18n()

const { error, ticket, login, confirmSecondFactor, requestReset, resetPassword, requestLink, redeemLink } = useAuth()

// Back to the start page, where a tablet is paired instead.
const { choose } = useEntry()

type Mode = 'login' | 'forgot' | 'sent' | 'reset' | 'invite' | 'link' | 'code' | 'factor'

const mode = ref<Mode>('login')
const email = ref('')
const password = ref('')
const confirmation = ref('')
const token = ref('')
const code = ref('')
const busy = ref(false)

/*
 * Buttons of the social sign-in plugin; empty when it is not installed or
 * switched off. They are plain links: the way there and back is the browser's.
 */
const providers = ref<{ key: string, label: string }[]>([])

/** False when the server sends no mail: no sign-in link, no reset. */
const mail = ref(true)

const SOCIAL_ERRORS: Record<string, string> = {
  'failed': 'login.social.failed',
  'unknown': 'login.social.unknown',
  'unconfirmed': 'login.social.unconfirmed',
  'no-household': 'login.social.noHousehold',
  'factor-missing': 'login.social.factorMissing',
}

/*
 * The mails link here with ?reset=…, ?invite=… or ?login=…, plus &email=….
 * Taken once and removed from the address: a token is good once, and a
 * reload would try it again. A sign-in link signs in right away.
 */
onMounted(() => {
  $fetch<{ providers: { key: string, label: string }[] }>('/api/auth/social', { baseURL: '/', credentials: 'same-origin' })
    .then(response => (providers.value = response.providers))
    .catch(() => (providers.value = []))
  $fetch<{ mail: boolean }>('/api/auth/methods', { baseURL: '/', credentials: 'same-origin' })
    .then(response => (mail.value = response.mail))
    .catch(() => (mail.value = true))

  const query = new URLSearchParams(window.location.search)
  const signIn = query.get('login')

  // Back from a sign-in provider: its reason, or the ticket for the code.
  const social = query.get('social')
  const factorTicket = query.get('two-factor')

  if (social || factorTicket) {
    window.history.replaceState(null, '', window.location.pathname)
    error.value = social ? t(SOCIAL_ERRORS[social] ?? 'login.social.failed') : null
    ticket.value = factorTicket

    return
  }

  if (signIn) {
    email.value = query.get('email') ?? ''
    window.history.replaceState(null, '', window.location.pathname)
    redeemLink(email.value, { token: signIn })

    return
  }

  for (const kind of ['reset', 'invite'] as const) {
    const value = query.get(kind)

    if (value) {
      token.value = value
      email.value = query.get('email') ?? ''
      mode.value = kind
      window.history.replaceState(null, '', window.location.pathname)
    }
  }
})

const choosing = computed(() => mode.value === 'reset' || mode.value === 'invite')

// Password, link or new password were right; the account also wants its code.
watch(ticket, (waiting) => {
  if (waiting) {
    code.value = ''
    mode.value = 'factor'
  }
})

function show(next: Mode): void {
  error.value = null
  ticket.value = null
  password.value = ''
  confirmation.value = ''
  code.value = ''
  mode.value = next
}

async function submit(): Promise<void> {
  busy.value = true

  if (mode.value === 'login') {
    await login(email.value.trim(), password.value)
  }
  else if (mode.value === 'link') {
    if (await requestLink(email.value.trim())) {
      mode.value = 'code'
    }
  }
  else if (mode.value === 'code') {
    await redeemLink(email.value.trim(), { code: code.value })
  }
  else if (mode.value === 'factor') {
    await confirmSecondFactor(code.value)
  }
  else if (mode.value === 'forgot') {
    if (await requestReset(email.value.trim())) {
      mode.value = 'sent'
    }
  }
  else if (mode.value === 'reset' || mode.value === 'invite') {
    if (password.value !== confirmation.value) {
      error.value = t('login.mismatch')
    }
    else {
      await resetPassword(mode.value, token.value, email.value, password.value, confirmation.value)
    }
  }

  busy.value = false
}

const intro = computed((): Record<Mode, string> => ({
  login: t('login.intro.login'),
  forgot: t('login.intro.forgot'),
  sent: t('login.intro.sent'),
  reset: t('login.intro.reset'),
  invite: t('login.intro.invite'),
  link: t('login.intro.link'),
  code: t('login.intro.code'),
  factor: t('login.intro.factor'),
}))

const action = computed((): Record<Mode, string> => ({
  login: t('login.action.login'),
  forgot: t('login.action.forgot'),
  sent: '',
  reset: t('login.action.reset'),
  invite: t('login.action.invite'),
  link: t('login.action.link'),
  code: t('login.action.code'),
  factor: t('login.action.code'),
}))

const cell = 'mt-1.5 min-h-13 w-full rounded-2xl border-2 border-line bg-surface px-4 text-lg text-ink outline-none transition-colors placeholder:text-ink-3 focus:border-accent'
const link = 'min-h-11 w-full text-base font-semibold text-accent'
</script>

<template>
  <div class="flex min-h-full flex-col justify-center bg-canvas px-6 py-12">
    <TwLogo class="size-14" />

    <h1 class="mt-6 font-display text-4xl font-semibold">
      Family Home Base
    </h1>

    <p class="mt-1 text-lg text-ink-2">
      {{ intro[mode] }}
    </p>

    <form v-if="mode !== 'sent'" class="mt-8 space-y-4" @submit.prevent="submit">
      <label v-if="!choosing && mode !== 'code' && mode !== 'factor'" class="block">
        <span class="text-base font-semibold text-ink-2">{{ $t('login.email') }}</span>
        <input
          v-model="email"
          type="email"
          autocomplete="username"
          inputmode="email"
          required
          :class="cell"
        >
      </label>

      <label v-if="mode === 'code'" class="block">
        <span class="text-base font-semibold text-ink-2">{{ $t('login.code') }}</span>
        <input
          v-model="code"
          type="text"
          autocomplete="one-time-code"
          autocapitalize="characters"
          spellcheck="false"
          maxlength="6"
          required
          :class="[cell, 'font-mono uppercase tracking-[0.3em]']"
        >
      </label>

      <label v-if="mode === 'factor'" class="block">
        <span class="text-base font-semibold text-ink-2">{{ $t('login.factor') }}</span>
        <input
          v-model="code"
          type="text"
          data-testid="factor-code"
          autocomplete="one-time-code"
          inputmode="numeric"
          spellcheck="false"
          maxlength="32"
          required
          :class="[cell, 'font-mono tracking-[0.3em]']"
        >
      </label>

      <label v-if="mode === 'login' || choosing" class="block">
        <span class="text-base font-semibold text-ink-2">{{ choosing ? $t('login.newPassword') : $t('login.password') }}</span>
        <input
          v-model="password"
          type="password"
          :autocomplete="choosing ? 'new-password' : 'current-password'"
          :minlength="choosing ? 10 : undefined"
          required
          :class="cell"
        >
      </label>

      <label v-if="choosing" class="block">
        <span class="text-base font-semibold text-ink-2">{{ $t('login.repeat') }}</span>
        <input
          v-model="confirmation"
          type="password"
          autocomplete="new-password"
          required
          :class="cell"
        >
      </label>

      <p v-if="error" role="alert" class="text-base font-medium text-danger">
        {{ error }}
      </p>

      <button
        type="submit"
        data-testid="login"
        class="flex min-h-13 w-full items-center justify-center gap-2 rounded-2xl bg-accent text-lg font-semibold text-accent-ink transition-opacity disabled:opacity-50"
        :disabled="busy"
      >
        <TwPending :busy="busy" />
        {{ action[mode] }}
      </button>
    </form>

    <div v-if="mode === 'login' && providers.length" class="mt-6">
      <p class="text-center text-sm text-ink-3">
        {{ $t('login.social.or') }}
      </p>
      <a
        v-for="provider in providers"
        :key="provider.key"
        :href="`/social/${provider.key}/redirect`"
        data-testid="social-login"
        :data-value="provider.key"
        class="mt-3 flex min-h-13 w-full items-center justify-center rounded-2xl border-2 border-line bg-surface text-lg font-semibold"
      >
        {{ $t('login.social.with', { provider: provider.label }) }}
      </a>
    </div>

    <div class="mt-4 space-y-1">
      <template v-if="mode === 'login'">
        <template v-if="mail">
          <button type="button" :class="link" @click="show('link')">
            {{ $t('login.byLink') }}
          </button>
          <button type="button" :class="link" @click="show('forgot')">
            {{ $t('login.forgot') }}
          </button>
        </template>
        <p v-else class="text-center text-sm text-ink-2" data-testid="no-mail">
          {{ $t('login.noMail') }}
        </p>
        <button type="button" data-testid="entry-back" :class="link" @click="choose('device')">
          {{ $t('common.back') }}
        </button>
      </template>
      <button v-else type="button" :class="link" @click="show('login')">
        {{ $t('login.back') }}
      </button>
    </div>
  </div>
</template>
