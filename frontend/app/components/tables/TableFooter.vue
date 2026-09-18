<script setup lang="ts">
export interface FooterTableApi {
  getState: () => { pagination: { pageIndex: number, pageSize: number } }
  setPageIndex: (index: number) => void
}

const props = withDefaults(defineProps<{
  selected: number
  total: number
  unit: string
  feminine?: boolean
  tableApi?: FooterTableApi | null
}>(), {
  feminine: false,
  tableApi: null
})

const emit = defineEmits<{
  'update:page': [page: number]
}>()

const page = computed(() => (props.tableApi?.getState().pagination.pageIndex || 0) + 1)
const pageSize = computed(() => props.tableApi?.getState().pagination.pageSize)
</script>

<template>
  <div class="flex items-center justify-between gap-3 border-t border-default pt-4 mt-auto">
    <div class="text-sm text-muted">
      {{ selected }} de {{ total }} {{ unit }} {{ feminine ? 'selecionada(s).' : 'selecionado(s).' }}
    </div>

    <div class="flex items-center gap-1.5">
      <UPagination
        :page="page"
        :items-per-page="pageSize"
        :total="total"
        @update:page="(p: number) => emit('update:page', p)"
      />
    </div>
  </div>
</template>
