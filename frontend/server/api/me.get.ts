export default defineEventHandler((event) => {
  if (!readSession(event)) {
    throw createError({ statusCode: 401, statusMessage: 'Unauthenticated', message: 'Não autenticado.' })
  }
  return backendFetch(event, '/api/me')
})
