export default defineEventHandler((event) => {
  const ref = getRouterParam(event, 'ref')
  return proxyBinaryToBackend(event, `/monitoring/artifacts/${ref}/download`, { ...getQuery(event) })
})
