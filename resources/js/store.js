import { reactive, nextTick } from 'vue'
import { useToast } from 'vue-toastification'
import mitt from 'mitt'
import debounce from './debounce'
const emitter = mitt()
const toast = useToast()

const uploadController = reactive({
  pause: false,
  pauseUpload() {
    this.pause = true
  },
  resumeUpload() {
    this.pause = false
  }
})

const store = reactive({
  userId: null,
  admin: false,
  guest: false,
  jwt: null,
  jwtExpires: null,
  loggedIn: false,
  settingsOpen: false,
  mode: 'upload',
  shareCode: null,
  mustChangePassword: false,
  bootstrapDone: false,
  guestUploaderName: (() => {
    try {
      return localStorage.getItem('erugo_guest_name') || ''
    } catch (e) {
      return ''
    }
  })(),
  reverseShareLabel: (() => {
    try {
      return localStorage.getItem('erugo_reverse_share_label') || null
    } catch (e) {
      return null
    }
  })(),

  setUserId(userId) {
    this.userId = parseInt(userId)
  },

  setAdmin(admin) {
    this.admin = admin
  },

  setJwt(jwt) {
    this.jwt = jwt
  },

  setJwtExpires(jwtExpires) {
    this.jwtExpires = new Date(jwtExpires * 1000)
  },

  setLoggedIn(loggedIn) {
    this.loggedIn = loggedIn
  },

  setSettingsOpen(settingsOpen) {
    this.settingsOpen = settingsOpen
  },

  setMode(mode) {
    this.mode = mode
  },

  setShareCode(shareCode) {
    this.shareCode = shareCode
  },

  setBootstrapDone(bootstrapDone) {
    this.bootstrapDone = bootstrapDone
  },

  setGuestUploaderName(guestUploaderName) {
    this.guestUploaderName = guestUploaderName
    try {
      localStorage.setItem('erugo_guest_name', guestUploaderName)
    } catch (e) {
      // ignore storage errors (e.g. private mode)
    }
  },

  setReverseShareLabel(label) {
    this.reverseShareLabel = label || null
    try {
      if (label) {
        localStorage.setItem('erugo_reverse_share_label', label)
      } else {
        localStorage.removeItem('erugo_reverse_share_label')
      }
    } catch (e) {
      // ignore storage errors (e.g. private mode)
    }
  },

  getReverseShareLabel() {
    return this.reverseShareLabel
  },

  setMultiple(data) {
    const keys = Object.keys(data)
    keys.forEach(key => {
      this[`set${key.charAt(0).toUpperCase() + key.slice(1)}`](data[key])
    })
  },

  isAdmin() {
    return this.admin
  },

  isLoggedIn() {
    return this.loggedIn
  },

  isGuest() {
    return this.guest
  },

  getGuestUploaderName() {
    return this.guestUploaderName
  },

  authSuccess(data) {
    this.setMultiple({
      userId: data.userId,
      admin: data.admin,
      jwt: data.jwt,
      jwtExpires: data.jwtExpires,
      loggedIn: data.loggedIn
    })
    this.mustChangePassword = data.mustChangePassword
    this.guest = data.guest
    // Sync the label whenever the response explicitly carries an invite_label
    // key (the accept flow): set it, or clear it when the invite has none.
    // Login/refresh responses omit the key (undefined), so they leave the
    // persisted label untouched -- this keeps the label across page reloads.
    if (data.inviteLabel !== undefined) {
      this.setReverseShareLabel(data.inviteLabel)
    }
    this.logState()
  },

  logState() {  
    //no
  },

  autoShowProfileEdit: false,
  showPasswordResetForm() {
    this.autoShowProfileEdit = true
    emitter.emit('showPasswordResetForm')
  },

  
})

const showResetPasswordToast = () => {
  toast.error('You must change your password to continue')
}

const debouncedShowResetPasswordToast = debounce(showResetPasswordToast, 100)




export { emitter, store, uploadController }