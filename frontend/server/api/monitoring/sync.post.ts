export default defineEventHandler(event => backendProxy(event, '/monitoring/sync'))
