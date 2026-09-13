export default defineEventHandler(async (event) => {
  const data = await backendFetch(event, '/logout', { method: 'POST' })
  deleteCookie(event, SESSION_COOKIE, { path: '/' })
  return data
})
