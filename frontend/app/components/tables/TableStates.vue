<script setup lang="ts">
const props = defineProps<{
  error?: unknown
  title?: string
  errorTitle?: string
  description?: string
  errorDescription?: string
  variant?: 'subtle'
  emptyTitle?: string
  emptyHint?: string
}>()

const emit = defineEmits<{
  retry: []
}>()

const finalTitle = computed(() => props.title ?? props.errorTitle)
const finalDescription = computed(() => props.description ?? props.errorDescription)

const retryActions = [{
  label: 'Tentar novamente',
  color: 'error' as const,
  variant: 'outline' as const,
  onClick: () => emit('retry')
}]
</script>

<template>
  <UAlert
    v-if="error"
    color="error"
    :variant="variant"
    :title="finalTitle"
    :description="finalDescription"
    :actions="retryActions"
  />
  <div
    v-else-if="emptyTitle"
    class="flex flex-col items-center justify-center gap-2 py-8 text-center"
  >
    <p class="font-medium text-highlighted">
      {{ emptyTitle }}
    </p>
    <p class="text-sm text-muted">
      {{ emptyHint }}
    </p>
  </div>
</template>
