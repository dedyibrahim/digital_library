<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import DriveIcon from './DriveIcon.vue';
import FileIcon from './FileIcon.vue';

const props = defineProps({ book: Object, x: Number, y: Number });
const emit = defineEmits(['close', 'action']);
const menu = ref(null);
const position = ref({ left: 0, top: 0 });
const actions = computed(() => [
    { id: 'preview', icon: 'eye', label: 'Pratinjau', file: true },
    { id: 'rename', icon: 'rename', label: 'Ganti nama', shortcut: 'F2' },
    { id: 'download', icon: 'download', label: 'Download', file: true },
    { id: 'share', icon: 'share', label: 'Bagikan', file: true },
    { id: 'star', icon: 'star', label: props.book.is_starred ? 'Hapus dari berbintang' : 'Tambahkan ke berbintang', divider: true },
    { id: 'delete', icon: 'trash', label: 'Hapus', danger: true, divider: true },
]);
const unavailable = (action) => action.file && !(props.book.object_key || props.book.file_path);
const placeMenu = async () => {
    await nextTick();
    if (!menu.value) return;
    position.value = {
        left: Math.max(8, Math.min(props.x, window.innerWidth - menu.value.offsetWidth - 8)),
        top: Math.max(8, Math.min(props.y, window.innerHeight - menu.value.offsetHeight - 8)),
    };
    menu.value.querySelector('button:not(:disabled)')?.focus();
};
const closeOutside = (event) => { if (!menu.value?.contains(event.target)) emit('close'); };
const close = () => emit('close');
const handleKeys = (event) => {
    if (event.key === 'Escape' || event.key === 'Tab') { emit('close'); return; }
    if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;
    event.preventDefault();
    const items = Array.from(menu.value.querySelectorAll('button:not(:disabled)'));
    const current = items.indexOf(document.activeElement);
    const index = event.key === 'Home' ? 0 : event.key === 'End' ? items.length - 1
        : (current + (event.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
    items[index]?.focus();
};
watch(() => [props.x, props.y, props.book.uuid], placeMenu);
onMounted(() => {
    placeMenu();
    window.addEventListener('pointerdown', closeOutside);
    window.addEventListener('resize', close);
    window.addEventListener('scroll', close, true);
});
onBeforeUnmount(() => {
    window.removeEventListener('pointerdown', closeOutside);
    window.removeEventListener('resize', close);
    window.removeEventListener('scroll', close, true);
});
</script>

<template>
    <Teleport to="body">
        <div ref="menu" role="menu" :aria-label="`Aksi untuk ${book.title}`" class="fixed z-[100] w-64 max-w-[calc(100vw-16px)] overflow-y-auto rounded-2xl border border-slate-200/80 bg-white p-1.5 shadow-[0_12px_48px_-8px_rgba(15,23,42,0.25)]" :style="{ left: `${position.left}px`, top: `${position.top}px`, maxHeight: 'calc(100dvh - 16px)' }" @contextmenu.prevent @keydown="handleKeys">
            <div class="mb-1 flex items-center gap-2 border-b border-slate-100 px-3 py-3"><FileIcon :book="book" class="h-8 w-7 text-[28px]" /><span class="truncate text-xs font-semibold text-slate-500">{{ book.title }}</span></div>
            <template v-for="action in actions" :key="action.id">
                <div v-if="action.divider" class="my-1 border-t border-slate-100" role="separator"></div>
                <button type="button" role="menuitem" :disabled="unavailable(action)" class="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-left text-sm outline-none disabled:cursor-not-allowed disabled:opacity-35" :class="action.danger ? 'text-red-600 hover:bg-red-50 focus:bg-red-50' : 'text-slate-700 hover:bg-blue-50 focus:bg-blue-50 focus:text-blue-700'" @click="emit('action', action.id, book)">
                    <DriveIcon :name="action.icon" class="size-[18px] shrink-0" /><span class="flex-1">{{ action.label }}</span><span v-if="action.shortcut" class="text-xs text-slate-400">{{ action.shortcut }}</span>
                </button>
            </template>
        </div>
    </Teleport>
</template>
