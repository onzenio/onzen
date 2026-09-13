function isPublicBackendRoute(method: string, pathname: string): boolean {
  if (method === 'GET' && pathname === '/api/onboarding/status') {
    return true
  }
  if (method === 'POST' && pathname === '/api/onboarding') {
    return true
  }
  if (method === 'POST' && /^\/api\/invitations\/[^/]+\/accept$/.test(pathname)) {
    return true
  }
  return false
}

export default defineEventHandler(async (event) => {
  const pathname = getRequestURL(event).pathname
  const method = getMethod(event).toUpperCase() as BackendMethod
  if (!isPublicBackendRoute(method, pathname) && !readSession(event)) {
    throw createError({ statusCode: 401, statusMessage: 'Unauthenticated', message: 'Não autenticado.' })
  }
  const body = method === 'GET' || method === 'HEAD'
    ? undefined
    : await readBody(event).catch(() => undefined)
  return backendFetch(event, pathname, { method, body, query: { ...getQuery(event) } })
})
