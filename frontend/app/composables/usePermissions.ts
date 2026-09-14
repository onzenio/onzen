import type { UserRole } from './useMe'

export type PermissionAction
  = 'accounts.view'
    | 'plans.view'
    | 'audit.view'
    | 'clients.view'
    | 'clients.write'
    | 'invites.manage'
    | 'switch.use'
    | 'monitoring.view'
    | 'monitoring.write'
    | 'serpro.view'

export function usePermissions() {
  const { me } = useMe()

  const role = computed((): UserRole | null => me.value?.user.role ?? null)
  const isSuperAdmin = computed(() => role.value === 'super_admin')
  const isAdmin = computed(() => role.value === 'admin' || role.value === 'super_admin')
  const modules = computed((): string[] => me.value?.account.plan?.modules ?? [])

  function can(action: PermissionAction): boolean {
    switch (action) {
      case 'accounts.view':
        return isSuperAdmin.value
      case 'plans.view':
        return isSuperAdmin.value
      case 'audit.view':
        return isSuperAdmin.value || role.value === 'admin'
      case 'clients.view':
        return role.value !== null
      case 'clients.write':
        return isSuperAdmin.value || role.value === 'admin' || role.value === 'operator'
      case 'invites.manage':
        return isSuperAdmin.value || role.value === 'admin'
      case 'switch.use':
        return isSuperAdmin.value
      case 'monitoring.view':
        // Papel da carteira + Module liberado (monitoring ou clients).
        return role.value !== null && (canAccessModule('monitoring') || canAccessModule('clients'))
      case 'monitoring.write':
        return (isSuperAdmin.value || role.value === 'admin' || role.value === 'operator') && (canAccessModule('monitoring') || canAccessModule('clients'))
      case 'serpro.view':
        return isSuperAdmin.value
      default:
        return false
    }
  }

  function canAccessModule(module: string): boolean {
    return modules.value.includes(module)
  }

  return { role, isSuperAdmin, isAdmin, modules, can, canAccessModule }
}
