<script setup>
import { computed } from 'vue';
import DriveIcon from './DriveIcon.vue';

const props = defineProps({ book: { type: Object, required: true } });
const appearance = computed(() => {
    const extension = (props.book.original_filename || props.book.title).split('.').pop().toLowerCase();
    const mime = props.book.mime_type || '';
    if (extension === 'pdf') return { icon: 'file', color: 'text-red-600', fill: '#fef2f2', label: 'PDF' };
    if (['xls', 'xlsx', 'csv', 'ods'].includes(extension)) return { icon: 'table', color: 'text-emerald-600', fill: '#ecfdf5' };
    if (['ppt', 'pptx', 'odp'].includes(extension)) return { icon: 'slides', color: 'text-orange-500', fill: '#fff7ed' };
    if (['doc', 'docx', 'odt', 'rtf'].includes(extension)) return { icon: 'text', color: 'text-blue-600', fill: '#eff6ff' };
    if (mime.startsWith('image/')) return { icon: 'image', color: 'text-violet-600', fill: '#f5f3ff' };
    if (mime.startsWith('video/')) return { icon: 'video', color: 'text-rose-600', fill: '#fff1f2' };
    if (mime.startsWith('audio/')) return { icon: 'music', color: 'text-fuchsia-600', fill: '#fdf4ff' };
    if (['zip', 'rar', '7z', 'tar', 'gz'].includes(extension)) return { icon: 'archive', color: 'text-amber-600', fill: '#fffbeb' };
    if (['js', 'ts', 'php', 'py', 'html', 'css', 'json', 'xml', 'sql'].includes(extension)) return { icon: 'code', color: 'text-cyan-600', fill: '#ecfeff' };
    return { icon: 'text', color: 'text-slate-500', fill: '#f8fafc' };
});
</script>

<template>
    <span class="relative inline-flex shrink-0 items-center justify-center" :class="appearance.color" aria-hidden="true">
        <svg class="absolute size-full" viewBox="0 0 48 56" fill="none">
            <path d="M10 2h20l12 12v34a6 6 0 0 1-6 6H10a6 6 0 0 1-6-6V8a6 6 0 0 1 6-6Z" :fill="appearance.fill" stroke="currentColor" stroke-opacity=".25" stroke-width="1.5" />
            <path d="M30 2v8a4 4 0 0 0 4 4h8" stroke="currentColor" stroke-opacity=".45" stroke-width="1.5" />
        </svg>
        <span v-if="appearance.label" class="relative mt-[18%] text-[.28em] font-extrabold tracking-tight">{{ appearance.label }}</span>
        <DriveIcon v-else :name="appearance.icon" class="relative mt-[15%] size-[48%]" />
    </span>
</template>
