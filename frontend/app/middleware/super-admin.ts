import type { MeResponse } from '../composables/useMe'

export default defineNuxtRouteMiddleware(async () => {
  const api = useRequestFetch()
  const me = await api<MeResponse>('/api/me').catch(() => null)
  if (me?.user.role !== 'super_admin') {
    return navigateTo('/')
  }
})
