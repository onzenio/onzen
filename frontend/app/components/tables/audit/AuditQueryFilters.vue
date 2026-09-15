<script setup lang="ts">
const accountId = defineModel<string>('accountId', { default: '' })
const actorUserId = defineModel<string>('actorUserId', { default: '' })
const from = defineModel<string>('from', { default: '' })
const to = defineModel<string>('to', { default: '' })

const emit = defineEmits<{
  apply: []
  clear: []
}>()
</script>

<template>
  <div class="flex flex-wrap items-end gap-1.5">
    <UFormField label="Conta" class="min-w-28">
      <UInput
        v-model="accountId"
        type="number"
        min="1"
        placeholder="ID da conta"
      />
    </UFormField>
    <UFormField label="Ator" class="min-w-28">
      <UInput
        v-model="actorUserId"
        type="number"
        min="1"
        placeholder="ID do usuário"
      />
    </UFormField>
    <UFormField label="De" class="min-w-28">
      <UInput v-model="from" type="date" />
    </UFormField>
    <UFormField label="Até" class="min-w-28">
      <UInput v-model="to" type="date" />
    </UFormField>
    <div class="flex gap-1.5">
      <UButton label="Filtrar" icon="i-lucide-filter" @click="emit('apply')" />
      <UButton
        label="Limpar"
        color="neutral"
        variant="outline"
        @click="emit('clear')"
      />
    </div>
  </div>
</template>
