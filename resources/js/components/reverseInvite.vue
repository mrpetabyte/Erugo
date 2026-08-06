<script setup>
import { ref, defineExpose } from 'vue'
import { useTranslate } from '@tolgee/vue'
import { MessageCircleMore, Link2, Mail, CircleX, Copy, Check } from 'lucide-vue-next'
import { sendReverseShareInvite } from '../api'
import { useToast } from 'vue-toastification'
import { domData } from '../domData'
const { t } = useTranslate()
const toast = useToast()
const reverseInviteActive = ref(false)
const mode = ref('link')
const invite = ref({
  label: '',
  email: '',
  name: '',
  message: ''
})
const generatedLink = ref('')
const linkCopied = ref(false)
const errors = ref({})
const submitting = ref(false)

const reverseInviteClickOutside = (event) => {
  if (!event.target.closest('.user-form')) {
    reverseInviteActive.value = false
  }
}

const resetForm = () => {
  invite.value = { label: '', email: '', name: '', message: '' }
  errors.value = {}
  generatedLink.value = ''
  linkCopied.value = false
}

const buildInviteLink = (inviteCode) => {
  const appUrl = domData().application_url || window.location.origin
  return `${appUrl}/?invite_code=${inviteCode}`
}

const sendReverseInvite = async () => {
  errors.value = {}
  const isLinkMode = mode.value === 'link'

  const label = isLinkMode ? invite.value.label.trim() : ''

  if (isLinkMode && !label) {
    errors.value.label = t.value('reverse_invite_send.label_required')
    return
  }

  if (!isLinkMode && !invite.value.email.trim()) {
    errors.value.email = t.value('reverse_invite_send.email_required')
    return
  }

  if (!isLinkMode && !invite.value.name.trim()) {
    errors.value.name = t.value('reverse_invite_send.name_required')
    return
  }

  submitting.value = true
  try {
    const result = await sendReverseShareInvite(
      isLinkMode ? '' : invite.value.email.trim(),
      isLinkMode ? '' : invite.value.name.trim(),
      invite.value.message.trim(),
      label
    )
    if (isLinkMode) {
      const inviteCode = result?.data?.invite?.invite_code
      generatedLink.value = buildInviteLink(inviteCode)
    } else {
      reverseInviteActive.value = false
      toast.success(t.value('reverse_invite_send.success'))
    }
  } catch (error) {
    console.error(error)
    if (error.message === 'No account found for this email') {
      toast.error(t.value('reverse_invite_send.no_account_error'))
    } else {
      toast.error(error.message || t.value('reverse_invite_send.error'))
    }
  } finally {
    submitting.value = false
  }
}

const copyLink = async () => {
  try {
    await navigator.clipboard.writeText(generatedLink.value)
    linkCopied.value = true
    setTimeout(() => {
      linkCopied.value = false
    }, 1500)
  } catch (e) {
    console.error(e)
  }
}

const showReverseInviteForm = () => {
  resetForm()
  mode.value = 'link'
  reverseInviteActive.value = true
}

defineExpose({
  showReverseInviteForm
})
</script>

<template>
  <div class="user-form-overlay" :class="{ active: reverseInviteActive }" @click="reverseInviteClickOutside">
    <div class="user-form">
      <h2>
        <MessageCircleMore />
        {{ $t('settings.title.reverse_invite') }}
      </h2>
      <p>{{ $t('settings.reverse_invite.description') }}</p>

      <div class="mode-toggle">
        <button
          type="button"
          class="mode-toggle-btn"
          :class="{ active: mode === 'link' }"
          @click="mode = 'link'"
        >
          <Link2 />
          {{ $t('reverse_invite_send.mode_link') }}
        </button>
        <button
          type="button"
          class="mode-toggle-btn"
          :class="{ active: mode === 'email' }"
          @click="mode = 'email'"
        >
          <Mail />
          {{ $t('reverse_invite_send.mode_email') }}
        </button>
      </div>

      <template v-if="mode === 'link'">
        <div class="input-container">
          <label for="edit_invite_label">{{ $t('reverse_invite_send.label') }}</label>
          <input
            type="text"
            v-model="invite.label"
            id="edit_invite_label"
            :placeholder="$t('reverse_invite_send.label_placeholder')"
            required
            :class="{ error: errors.label }"
          />
          <div class="error-message" v-if="errors.label">
            {{ errors.label }}
          </div>
        </div>

        <div class="link-result" v-if="generatedLink">
          <div class="link-result-label">{{ $t('reverse_invite_send.your_link') }}</div>
          <div class="link-result-value">
            <span class="link-url">{{ generatedLink }}</span>
            <button type="button" class="copy-link" @click="copyLink">
              <Check v-if="linkCopied" />
              <Copy v-else />
              {{ linkCopied ? $t('reverse_invite_send.copied') : $t('reverse_invite_send.copy') }}
            </button>
          </div>
        </div>
      </template>

      <template v-else>
        <div class="input-container">
          <label for="edit_user_email">{{ $t('settings.users.email') }}</label>
          <input
            type="email"
            v-model="invite.email"
            id="edit_user_email"
            :placeholder="$t('settings.users.email')"
            required
            :class="{ error: errors.email }"
          />
          <div class="error-message" v-if="errors.email">
            {{ errors.email }}
          </div>
        </div>
        <div class="input-container">
          <label for="edit_user_name">{{ $t('settings.users.name') }}</label>
          <input
            type="text"
            v-model="invite.name"
            id="edit_user_name"
            :placeholder="$t('settings.users.name')"
            required
            :class="{ error: errors.name }"
          />
          <div class="error-message" v-if="errors.name">
            {{ errors.name }}
          </div>
        </div>
        <div class="input-container">
          <label for="edit_user_message">{{ $t('invite.labels.message') }}</label>
          <textarea
            v-model="invite.message"
            id="edit_user_message"
            :placeholder="$t('invite.message')"
          ></textarea>
        </div>
      </template>

      <div class="button-bar">
        <button @click="sendReverseInvite" :disabled="submitting">
          <Link2 v-if="mode === 'link'" />
          <Mail v-else />
          {{ mode === 'link' ? $t('reverse_invite_send.create_link') : $t('button.reverse_share_invite_send') }}
        </button>
        <button class="secondary close-button" @click="reverseInviteActive = false">
          <CircleX />
          {{ $t('settings.close') }}
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped lang="scss">
.user-form-overlay {
  border-radius: 10px 10px 0 0;
  position: fixed;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: var(--overlay-background-color);
  backdrop-filter: blur(10px);
  z-index: 230;
  opacity: 0;
  pointer-events: none;
  transition: all 0.3s ease;

  h2 {
    margin-bottom: 10px;
    font-size: 24px;
    color: var(--panel-text-color);
    display: flex;
    align-items: center;
    justify-content: center;

    svg {
      width: 24px;
      height: 24px;
      margin-right: 10px;
    }
  }
  .user-form {
    position: absolute;
    bottom: 0;
    left: 50%;
    transform: translate(-50%, 100%);
    width: min(500px, 100vw);
    background: var(--panel-background-color);
    color: var(--panel-text-color);
    padding: 20px;
    border-radius: 10px 10px 0 0;
    box-shadow: 0 0 100px 0 rgba(0, 0, 0, 0.5);
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    justify-content: flex-start;
    gap: 10px;
    transition: all 0.3s ease;
    padding-bottom: 20px;
    button {
      display: block;
      width: 100%;
    }
  }

  &.active {
    opacity: 1;
    pointer-events: auto;
    .user-form {
      transform: translate(-50%, 0%);
    }
  }
}

.mode-toggle {
  width: 100%;
  display: flex;
  gap: 8px;

  .mode-toggle-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 8px;
    border-radius: 8px;
    background: var(--panel-item-background-color);
    color: var(--panel-text-color);
    border: 1px solid var(--panel-border-color, transparent);
    cursor: pointer;
    opacity: 0.65;
    transition: all 0.2s ease;

    &.active {
      opacity: 1;
      border-color: var(--primary-button-background-color);
      background: var(--primary-button-background-color);
      color: var(--primary-button-text-color);
    }
  }
}

.link-result {
  width: 100%;
  display: flex;
  flex-direction: column;
  gap: 6px;
  color: var(--panel-text-color);

  .link-result-label {
    font-weight: 600;
  }

  .link-result-value {
    display: flex;
    align-items: center;
    gap: 8px;

    .link-url {
      flex: 1;
      padding: 8px;
      border-radius: 6px;
      background: var(--panel-item-background-color);
      word-break: break-all;
      font-size: 13px;
    }

    .copy-link {
      width: auto;
      display: flex;
      align-items: center;
      gap: 6px;
      white-space: nowrap;
    }
  }
}
</style>
