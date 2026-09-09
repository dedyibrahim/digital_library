<script setup>
import { computed, nextTick, onMounted, ref } from 'vue';
import pdfWorker from 'pdfjs-dist/build/pdf.worker.min.mjs?url';
import FileIcon from './FileIcon.vue';

const props = defineProps({ book: Object });
const canvas = ref(null);
const video = ref(null);
const failed = ref(false);

const extension = computed(() => (props.book.original_filename || props.book.title || '')
    .split('.').pop()?.toLowerCase() || 'file');
const mime = computed(() => props.book.mime_type || '');
const objectExists = computed(() => Boolean(props.book.object_key || props.book.file_path));
const openUrl = computed(() => route('books.content', props.book.uuid));
const kind = computed(() => {
    if (mime.value.startsWith('image/')) return 'image';
    if (mime.value === 'application/pdf' || extension.value === 'pdf') return 'pdf';
    if (mime.value.startsWith('video/')) return 'video';
    return 'icon';
});

const renderPdf = async () => {
    if (kind.value !== 'pdf' || !objectExists.value) return;
    try {
        const pdfjs = await import('pdfjs-dist');
        pdfjs.GlobalWorkerOptions.workerSrc = pdfWorker;
        const document = await pdfjs.getDocument({ url: openUrl.value, withCredentials: true }).promise;
        const page = await document.getPage(1);
        await nextTick();
        const base = page.getViewport({ scale: 1 });
        const scale = Math.min(1.5, 300 / base.width);
        const viewport = page.getViewport({ scale });
        const context = canvas.value.getContext('2d');
        canvas.value.width = viewport.width;
        canvas.value.height = viewport.height;
        await page.render({ canvasContext: context, viewport }).promise;
    } catch {
        failed.value = true;
    }
};

const seekVideo = () => {
    if (video.value?.duration) video.value.currentTime = Math.min(1, video.value.duration / 10);
};

onMounted(renderPdf);
</script>

<template>
    <div class="relative size-full overflow-hidden bg-white">
        <img v-if="kind === 'image' && objectExists && !failed" :src="openUrl" :alt="book.title" class="size-full object-cover" loading="lazy" @error="failed = true" />
        <canvas v-else-if="kind === 'pdf' && objectExists && !failed" ref="canvas" class="size-full object-cover object-top"></canvas>
        <video v-else-if="kind === 'video' && objectExists && !failed" ref="video" :src="openUrl" class="size-full object-cover" muted preload="metadata" @loadedmetadata="seekVideo" @error="failed = true"></video>
        <div v-else class="grid size-full place-items-center bg-gradient-to-br from-white to-slate-100">
            <FileIcon :book="book" class="h-28 w-24 text-[88px] drop-shadow-md" />
        </div>
        <span class="absolute bottom-2 right-2 rounded-md bg-slate-900/75 px-2 py-1 text-[10px] font-bold uppercase text-white shadow">{{ extension }}</span>
    </div>
</template>
