export default defineEventHandler(async (event) => {
  const ref = getRouterParam(event, 'ref')
  const data = await backendFetch<{ url: string, expires_in_minutes: number }>(
    event,
    `/monitoring/artifacts/${ref}/url`,
    { method: 'GET' }
  )
  const search = new URL(data.url).search
  const origin = getRequestURL(event).origin
  return {
    ...data,
    url: `${origin}/api/monitoring/artifacts/${ref}/download${search}`
  }
})
