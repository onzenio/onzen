<script setup lang="ts">
export interface ToolbarTableApi {
  getAllColumns: () => { id: string, getCanHide: () => boolean, getIsVisible: () => boolean }[]
  getColumn: (id: string) => { toggleVisibility: (value?: boolean) => void } | undefined
}

export interface ToolbarFilterItem {
  label: string
  value: string
}

const props = withDefaults(defineProps<{
  searchPlaceholder?: string
  filterItems?: ToolbarFilterItem[]
  filterPlaceholder?: string
  tableApi?: ToolbarTableApi | null
  columnLabels?: Record<string, string>
}>(), {
  tableApi: null,
  columnLabels: () => ({})
})

const search = defineModel<string>('search', { default: '' })
const filterValue = defineModel<string>('filterValue', { default: 'all' })

const emit = defineEmits<{
  export: []
}>()

const visibilityItems = computed(() => (props.tableApi?.getAllColumns() ?? [])
  .filter(column => column.getCanHide() && column.id !== 'select')
  .map(column => ({
    label: props.columnLabels[column.id] ?? column.id,
    type: 'checkbox' as const,
    checked: column.getIsVisible(),
    onUpdateChecked(checked: boolean) {
      props.tableApi?.getColumn(column.id)?.toggleVisibility(!!checked)
    },
    onSelect(e?: Event) {
      e?.preventDefault()
    }
  })))
</script>

<template>
  <div class="flex flex-wrap items-center justify-between gap-1.5">
    <slot name="leading">
      <UInput
        v-if="searchPlaceholder"
        v-model="search"
        class="max-w-sm"
        icon="i-lucide-search"
        :placeholder="searchPlaceholder"
      />
    </slot>

    <div class="flex flex-wrap items-center gap-1.5">
      <slot name="bulk" />
      <UButton
        label="Exportar CSV"
        color="neutral"
        variant="outline"
        icon="i-lucide-download"
        @click="emit('export')"
      />
      <USelect
        v-if="filterItems"
        v-model="filterValue"
        :items="filterItems"
        value-key="value"
        :placeholder="filterPlaceholder"
        class="min-w-28"
      />
      <UDropdownMenu
        :items="visibilityItems"
        :content="{ align: 'end' }"
      >
        <UButton
          label="Exibir"
          color="neutral"
          variant="outline"
          trailing-icon="i-lucide-settings-2"
        />
      </UDropdownMenu>
    </div>
  </div>
</template>
