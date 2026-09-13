export default defineEventHandler((event) => {
  return readBody(event).then((body) => {
    return backendFetch(event, '/forgot-password', { method: 'POST', body })
  })
})
