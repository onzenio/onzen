<script setup lang="ts">
import type { CommandPaletteItem, NavigationMenuItem } from '@nuxt/ui'

const route = useRoute()
const toast = useToast()

const open = ref(false)

const { me, actingAs, refresh } = useMe()
const { can, isSuperAdmin } = usePermissions()

function closeSidebar() {
  open.value = false
}

const links = computed<NavigationMenuItem[][]>(() => {
  const main: NavigationMenuItem[] = [{
    label: 'Home',
    icon: 'i-lucide-house',
    to: '/',
    onSelect: closeSidebar
  }]
  if (can('clients.view')) {
    main.push({
      label: 'Clients',
      icon: 'i-lucide-briefcase',
      to: '/clients',
      onSelect: closeSidebar
    })
  }
  const adminChildren: NavigationMenuItem[] = []
  if (can('accounts.view')) {
    adminChildren.push({
      label: 'Escritórios',
      to: '/accounts',
      onSelect: closeSidebar
    })
  }
  if (can('plans.view')) {
    adminChildren.push({
      label: 'Planos',
      to: '/plans',
      onSelect: closeSidebar
    })
  }
  if (can('audit.view')) {
    adminChildren.push({
      label: 'Auditoria',
      to: '/audit',
      onSelect: closeSidebar
    })
  }
  if (adminChildren.length > 0) {
    main.push({
      label: 'Administração',
      icon: 'i-lucide-shield-check',
      defaultOpen: true,
      type: 'trigger',
      children: adminChildren
    })
  }
  main.push({
    label: 'Inbox',
    icon: 'i-lucide-inbox',
    to: '/inbox',
    badge: '4',
    onSelect: closeSidebar
  }, {
    label: 'Customers',
    icon: 'i-lucide-users',
    to: '/customers',
    onSelect: closeSidebar
  }, {
    label: 'Settings',
    to: '/settings',
    icon: 'i-lucide-settings',
    defaultOpen: true,
    type: 'trigger',
    children: [{
      label: 'General',
      to: '/settings',
      exact: true,
      onSelect: closeSidebar
    }, {
      label: 'Members',
      to: '/settings/members',
      onSelect: closeSidebar
    }, {
      label: 'Notifications',
      to: '/settings/notifications',
      onSelect: closeSidebar
    }, {
      label: 'Security',
      to: '/settings/security',
      onSelect: closeSidebar
    }]
  })
  return [main, [{
    label: 'Feedback',
    icon: 'i-lucide-message-circle',
    to: 'https://github.com/nuxt-ui-templates/dashboard',
    target: '_blank'
  }, {
    label: 'Help & Support',
    icon: 'i-lucide-info',
    to: 'https://github.com/nuxt-ui-templates/dashboard',
    target: '_blank'
  }]]
})

const groups = computed(() => [{
  id: 'links',
  label: 'Go to',
  items: links.value.flat() as unknown as CommandPaletteItem[]
}, {
  id: 'code',
  label: 'Code',
  items: [{
    id: 'source',
    label: 'View page source',
    icon: 'i-simple-icons-github',
    to: `https://github.com/nuxt-ui-templates/dashboard/blob/main/app/pages${route.path === '/' ? '/index' : route.path}.vue`,
    target: '_blank'
  }]
}])

async function exitSwitch() {
  try {
    await $fetch('/api/switch', { method: 'DELETE' })
    await refresh()
    toast.add({ title: 'De volta à sua conta', color: 'success' })
    await navigateTo('/')
  } catch (error) {
    toast.add({ title: 'Não foi possível sair da conta', description: backendMessage(error), color: 'error' })
  }
}

onMounted(async () => {
  const cookie = useCookie('cookie-consent')
  if (cookie.value === 'accepted') {
    return
  }

  toast.add({
    title: 'We use first-party cookies to enhance your experience on our website.',
    duration: 0,
    close: false,
    actions: [{
      label: 'Accept',
      color: 'neutral',
      variant: 'outline',
      onClick: () => {
        cookie.value = 'accepted'
      }
    }, {
      label: 'Opt out',
      color: 'neutral',
      variant: 'ghost'
    }]
  })
})
</script>

<template>
  <UBanner
    v-if="actingAs"
    icon="i-lucide-eye"
    :title="`Atuando como ${me?.account.name ?? ''}`"
    color="warning"
  >
    <template #actions>
      <UButton
        label="Sair"
        color="neutral"
        variant="outline"
        size="xs"
        @click="exitSwitch"
      />
    </template>
  </UBanner>

  <UDashboardGroup unit="rem">
    <UDashboardSidebar
      id="default"
      v-model:open="open"
      collapsible
      resizable
      class="bg-elevated/25"
      :ui="{ footer: 'lg:border-t lg:border-default' }"
    >
      <template #header="{ collapsed }">
        <AccountSwitcher v-if="isSuperAdmin" :collapsed="collapsed" />
        <TeamsMenu v-else :collapsed="collapsed" />
      </template>

      <template #default="{ collapsed }">
        <UDashboardSearchButton :collapsed="collapsed" class="bg-transparent ring-default" />

        <UNavigationMenu
          :collapsed="collapsed"
          :items="links[0]"
          orientation="vertical"
          tooltip
          popover
        />

        <UNavigationMenu
          :collapsed="collapsed"
          :items="links[1]"
          orientation="vertical"
          tooltip
          class="mt-auto"
        />
      </template>

      <template #footer="{ collapsed }">
        <UserMenu :collapsed="collapsed" />
      </template>
    </UDashboardSidebar>

    <UDashboardSearch :groups="groups" />

    <slot />

    <NotificationsSlideover />
  </UDashboardGroup>
</template>
