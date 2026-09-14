import type { Session } from '~/types/monitoring'

export default defineNuxtRouteMiddleware(async () => {
  try {
    const me = await $fetch<Session>('/api/me')
    if (me.user.role !== 'super_admin') {
      throw createError({ statusCode: 403, statusMessage: 'Área restrita ao super_admin.' })
    }
  } catch (error: unknown) {
    const status = (error as { statusCode?: number })?.statusCode
    if (status === 401) {
      throw createError({ statusCode: 401, statusMessage: 'Autentique-se para acessar a administração SERPRO.' })
    }
    throw createError({ statusCode: 403, statusMessage: 'Área restrita ao super_admin.' })
  }
})
