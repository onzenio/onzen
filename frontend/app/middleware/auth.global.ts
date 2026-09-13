const PUBLIC_PATHS = ['/login', '/forgot-password', '/reset-password', '/onboarding']

function isPublicPath(path: string): boolean {
  return PUBLIC_PATHS.includes(path) || path.startsWith('/invite/')
}

function errorStatus(error: unknown): number | null {
  if (typeof error === 'object' && error !== null && 'statusCode' in error) {
    const status = (error as { statusCode?: unknown }).statusCode
    return typeof status === 'number' ? status : null
  }
  return null
}

export default defineNuxtRouteMiddleware(async (to) => {
  const api = useRequestFetch()

  const logged = await api('/api/me')
    .then(() => true)
    .catch((error: unknown) => {
      const status = errorStatus(error)
      return status === 401 || status === 419 ? false : null
    })
  if (logged === null) {
    return
  }

  const onboardingAvailable = await api<{ available?: boolean }>('/api/onboarding/status')
    .then(data => data?.available === true)
    .catch(() => false)

  if (onboardingAvailable && to.path !== '/onboarding') {
    return navigateTo('/onboarding')
  }
  if (!logged && !isPublicPath(to.path)) {
    return navigateTo('/login')
  }
  if (logged && to.path === '/login') {
    return navigateTo('/')
  }
})
