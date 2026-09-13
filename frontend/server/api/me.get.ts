export default defineEventHandler((event) => {
  return backendFetch(event, '/api/me')
})
