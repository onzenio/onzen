export default defineEventHandler((event) => {
  const id = getRouterParam(event, 'id')
  return proxyToBackend(event, `/monitoring/alerts/${id}/acknowledge`)
})
