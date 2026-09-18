export default defineEventHandler((event) => {
  const id = getRouterParam(event, 'id')
  return proxyBinaryToBackend(event, `/monitoring/parcelas/${id}/guia/download`, { ...getQuery(event) })
})
