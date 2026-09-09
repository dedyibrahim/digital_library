<script setup>
import { computed, nextTick, onMounted, ref } from 'vue';
import pdfWorker from 'pdfjs-dist/build/pdf.worker.min.mjs?url';

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

const palette = computed(() => {
    if (extension.value === 'pdf') return ['bg-red-500', 'text-red-600'];
    if (['doc', 'docx', 'odt', 'rtf'].includes(extension.value)) return ['bg-blue-600', 'text-blue-600'];
    if (['xls', 'xlsx', 'csv', 'ods'].includes(extension.value)) return ['bg-emerald-600', 'text-emerald-600'];
    if (['ppt', 'pptx', 'odp'].includes(extension.value)) return ['bg-orange-500', 'text-orange-600'];
    if (['zip', 'rar', '7z', 'tar', 'gz'].includes(extension.value)) return ['bg-amber-500', 'text-amber-600'];
    if (['mp3', 'wav', 'ogg', 'm4a', 'flac'].includes(extension.value)) return ['bg-violet-600', 'text-violet-600'];
    if (['mp4', 'mkv', 'mov', 'avi', 'webm'].includes(extension.value)) return ['bg-rose-500', 'text-rose-600'];
    if (['js', 'ts', 'php', 'py', 'html', 'css', 'json', 'xml'].includes(extension.value)) return ['bg-cyan-600', 'text-cyan-600'];
    return ['bg-slate-500', 'text-slate-600'];
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
        <div v-else class="grid size-full place-items-center bg-slate-50">
            <div class="relative h-24 w-20 rounded-lg bg-white shadow-md ring-1 ring-slate-200">
                <span class="absolute right-0 top-0 size-6 rounded-bl-md bg-slate-100 [clip-path:polygon(0_0,100%_100%,100%_0)]"></span>
                <span class="absolute inset-x-2 bottom-5 rounded py-1.5 text-center text-xs font-bold uppercase tracking-wide text-white" :class="palette[0]">{{ extension.slice(0, 5) }}</span>
            </div>
        </div>
        <span class="absolute bottom-2 right-2 rounded-md bg-slate-900/75 px-2 py-1 text-[10px] font-bold uppercase text-white shadow">{{ extension }}</span>
    </div>
</template>
