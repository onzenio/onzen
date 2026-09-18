export default defineEventHandler((event) => {
  const id = getRouterParam(event, 'id')
  return backendProxy(event, `/monitoring/authors/${id}/term`)
})
