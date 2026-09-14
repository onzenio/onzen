<script setup lang="ts">
definePageMeta({ middleware: ['serpro-manager'] })
</script>

<template>
  <UDashboardPanel id="monitoring-certificado">
    <template #header>
      <UDashboardNavbar title="Certificado Digital">
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <MonitoringCertificateManager />
    </template>
  </UDashboardPanel>
</template>
