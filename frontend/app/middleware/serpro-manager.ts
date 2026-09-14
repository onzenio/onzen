import type { UserRole } from '~/types/monitoring'

const MANAGER_ROLES: UserRole[] = ['admin', 'super_admin']

export default defineNuxtRouteMiddleware(async () => {
  await guardSessionRole(MANAGER_ROLES, 'Área restrita a administradores da Account.')
})
