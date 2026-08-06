<script setup>
import { PartyPopper, Upload } from 'lucide-vue-next'
import { useTranslate } from '@tolgee/vue'
import { store } from '../store'

const { t } = useTranslate()

const uploadMoreFiles = () => {
  store.setMode('upload')
}
</script>

<template>
  <div class="thank-guest-for-upload">
    <svg id="gradientDefs">
      <linearGradient id="gradient">
        <stop offset="0%" style="stop-color: var(--link-color); stop-opacity: 1" />
        <stop offset="100%" style="stop-color: var(--link-color-hover); stop-opacity: 1" />
      </linearGradient>
    </svg>
    <PartyPopper class="success-message-icon" />
    <p class="success-message">{{ t('guest.upload_success') }}</p>
    <p class="px-5">
      <strong>{{ t('guest.upload_success_message') }}</strong>
    </p>
    <button class="upload-more-button" @click="uploadMoreFiles">
      <Upload />
      {{ t('guest.upload_more') }}
    </button>
  </div>
</template>

<style scoped lang="scss">
.thank-guest-for-upload {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 16px;
  padding: 24px;
  box-sizing: border-box;
  flex: 1 1 auto;
  min-height: 0;

  .upload-more-button {
    position: relative;
    display: block;
    width: 100%;
    text-align: center;

    svg {
      position: absolute;
      left: 16px;
      top: 50%;
      transform: translateY(-50%);
    }
  }
}
</style>
