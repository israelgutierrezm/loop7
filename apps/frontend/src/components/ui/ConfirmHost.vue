<script setup lang="ts">
import { computed } from 'vue'
import { useConfirmStore } from '@/stores/confirm'
import ModalDialog from '@/components/ui/ModalDialog.vue'
import AppIcon from '@/components/AppIcon.vue'

const confirm = useConfirmStore()
const open = computed(() => confirm.current !== null)
</script>

<template>
  <ModalDialog :open="open" :title="confirm.current?.title ?? ''" size="sm" @close="confirm.answer(false)">
    <div class="flex gap-3">
      <span
        class="grid h-10 w-10 shrink-0 place-items-center rounded-full"
        :class="confirm.current?.danger ? 'bg-rose-50 text-rose-600 dark:bg-rose-950/40' : 'bg-brand-50 text-brand-600 dark:bg-brand-950/40'"
      >
        <AppIcon :name="confirm.current?.danger ? 'alert' : 'help'" :size="20" />
      </span>
      <p class="text-sm text-slate-600 dark:text-slate-300">
        {{ confirm.current?.message ?? '¿Quieres continuar?' }}
      </p>
    </div>
    <template #footer>
      <button type="button" class="btn-secondary text-sm" @click="confirm.answer(false)">
        {{ confirm.current?.cancelText ?? 'Cancelar' }}
      </button>
      <button
        type="button"
        class="btn text-sm text-white"
        :class="confirm.current?.danger ? 'bg-rose-600 hover:bg-rose-700' : 'bg-brand-600 hover:bg-brand-700'"
        @click="confirm.answer(true)"
      >
        {{ confirm.current?.confirmText ?? 'Confirmar' }}
      </button>
    </template>
  </ModalDialog>
</template>
