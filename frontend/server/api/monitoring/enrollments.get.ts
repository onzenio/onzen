export default defineEventHandler(event => proxyToBackend(event, '/monitoring/enrollments'))
