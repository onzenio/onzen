export default defineEventHandler((event) => {
  return readBody(event).then((body) => {
    return backendFetch(event, '/login', { method: 'POST', body })
  })
})
