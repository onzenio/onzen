export default defineEventHandler((event) => {
  return readBody(event).then((body) => {
    return backendFetch(event, '/reset-password', { method: 'POST', body })
  })
})
