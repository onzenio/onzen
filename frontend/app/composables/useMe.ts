export type UserRole = 'super_admin' | 'admin' | 'operator' | 'user'
export type AccountProfile = 'A' | 'B'

export interface MeUser {
  id: number
  name: string
  email: string
  role: UserRole
}

export interface MePlan {
  id: number
  name: string
  price_cents: number
  max_users: number
  max_clients: number
  modules: string[]
  monthly_query_volume: number
  is_default: boolean
}

export interface MeAccount {
  id: number
  name: string
  profile: AccountProfile
  plan: MePlan | null
}

export interface MeResponse {
  user: MeUser
  account: MeAccount
  acting_as: { account_id: number } | null
}

export function useMe() {
  const { data, pending, error, refresh } = useFetch<MeResponse>('/api/me', {
    key: 'me'
  })

  const me = computed(() => data.value ?? null)
  const loggedIn = computed(() => me.value !== null)
  const actingAs = computed(() => me.value?.acting_as ?? null)

  return { me, loggedIn, actingAs, pending, error, refresh }
}
