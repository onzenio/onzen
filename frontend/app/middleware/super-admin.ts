import type { UserRole } from '~/types/monitoring'

const SUPER_ADMIN_ROLES: UserRole[] = ['super_admin']

export default defineNuxtRouteMiddleware(async () => {
  await guardSessionRole(SUPER_ADMIN_ROLES, 'Área restrita ao super_admin.')
})
