import { createSharedComposable } from '@vueuse/core'
import type { Session, UserRole } from '~/types/monitoring'

const _useSession = () => {
  const { data, status, error, refresh } = useFetch<Session>('/api/me', { lazy: true })

  const role = computed<UserRole | null>(() => data.value?.user.role ?? null)
  const userName = computed(() => data.value?.user.name ?? '')
  const accountName = computed(() => data.value?.account.name ?? '')
  /** Admin e operator operam consultas e associações; user só consulta. */
  const canWrite = computed(() => role.value !== null && role.value !== 'user')
  /** Certificado e autores: só admin e super_admin. */
  const canManageSensitive = computed(() => role.value === 'admin' || role.value === 'super_admin')
  const isSuperAdmin = computed(() => role.value === 'super_admin')

  return {
    session: data,
    status,
    error,
    refresh,
    role,
    userName,
    accountName,
    canWrite,
    canManageSensitive,
    isSuperAdmin
  }
}

export const useSession = createSharedComposable(_useSession)
