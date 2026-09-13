<script setup lang="ts">
import type { DropdownMenuItem } from '@nuxt/ui'

interface SwitchAccountItem {
  id: number
  name: string
  profile: string
}

defineProps<{
  collapsed?: boolean
}>()

const toast = useToast()
const { me, actingAs, refresh } = useMe()

const { data } = await useFetch<{ data: SwitchAccountItem[] }>('/api/accounts', {
  key: 'switcher-accounts'
})

const accounts = computed(() => data.value?.data ?? [])
const currentName = computed(() => me.value?.account.name ?? 'Contas')

async function switchTo(accountId: number) {
  try {
    await $fetch('/api/switch', { method: 'POST', body: { account_id: accountId } })
    await refresh()
    toast.add({
      title: 'Conta alternada',
      description: `Atuando como ${me.value?.account.name ?? ''}.`,
      color: 'success'
    })
    await navigateTo('/')
  } catch (error) {
    toast.add({ title: 'Não foi possível alternar de conta', description: backendMessage(error), color: 'error' })
  }
}

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

const items = computed<DropdownMenuItem[][]>(() => {
  const list: DropdownMenuItem[] = accounts.value.map(account => ({
    label: account.name,
    description: account.profile === 'A' ? 'Conta A' : 'Conta B',
    icon: 'i-lucide-building-2',
    checked: account.id === me.value?.account.id,
    type: 'checkbox' as const,
    onSelect() {
      if (account.id !== me.value?.account.id) {
        void switchTo(account.id)
      }
    }
  }))
  if (!actingAs.value) {
    return [list]
  }
  return [list, [{
    label: 'Voltar à minha conta',
    icon: 'i-lucide-undo-2',
    onSelect() {
      void exitSwitch()
    }
  }]]
})
</script>

<template>
  <div class="flex w-full flex-col gap-1.5">
    <UDropdownMenu
      :items="items"
      :content="{ align: 'center', collisionPadding: 12 }"
      :ui="{ content: collapsed ? 'w-40' : 'w-(--reka-dropdown-menu-trigger-width)' }"
    >
      <UButton
        :label="collapsed ? undefined : currentName"
        trailing-icon="i-lucide-chevrons-up-down"
        color="neutral"
        variant="ghost"
        block
        :square="collapsed"
        class="data-[state=open]:bg-elevated"
        :class="[!collapsed && 'py-2']"
        :ui="{ trailingIcon: 'text-dimmed' }"
      />
    </UDropdownMenu>
    <UBadge
      v-if="actingAs && !collapsed"
      color="warning"
      variant="subtle"
      icon="i-lucide-eye"
      label="Atuando em outra conta"
      class="self-start"
    />
  </div>
</template>
