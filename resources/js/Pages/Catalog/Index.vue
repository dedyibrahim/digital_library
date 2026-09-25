<script setup>
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import FileThumbnail from '@/Components/FileThumbnail.vue';
import DriveIcon from '@/Components/DriveIcon.vue';
import FileIcon from '@/Components/FileIcon.vue';
import FileContextMenu from '@/Components/FileContextMenu.vue';
import Modal from '@/Components/Modal.vue';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import DocumentSearchStatus from '@/Components/DocumentSearchStatus.vue';

const props = defineProps({
    books: Object,
    categories: Array,
    filters: Object,
    stats: Object,
});
const page = usePage();

const form = reactive({ ...props.filters });
const view = ref('grid');
const showNewMenu = ref(false);
const modal = ref(null);
const previewBook = ref(null);
const isDragging = ref(false);
const fileInput = ref(null);
const fileError = ref('');
let timer;
let dragDepth = 0;
let uploadTimer;
let contextTrigger;
let shareRequest;
const contextMenu = ref(null);
const actionDialog = ref(null);
const actionBook = ref(null);
const renameInput = ref(null);
const shareInput = ref(null);
const renameForm = useForm({ name: '' });
const shareState = reactive({ url: '', expires: '', processing: false, error: '', copied: false });
const uploadStatus = reactive({ visible: false, count: 0, complete: false, error: '' });
const uploadError = computed(() => fileError.value || Object.values(bookForm.errors)[0] || '');

const folderForm = useForm({ name: '' });
const bookForm = useForm({
    category_id: '', files: [],
});

watch(form, () => {
    clearTimeout(timer);
    timer = setTimeout(() => router.get('/', form, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    }), 300);
});

const activeLabel = computed(() => {
    if (form.view === 'recent') return 'Terbaru';
    if (form.view === 'starred') return 'Berbintang';
    return props.categories.find((item) => item.slug === form.category)?.name || 'Pustaka Saya';
});
const fileType = (book) => book.original_filename?.split('.').pop()?.toUpperCase() || 'Metadata';
const canPreview = (book) => {
    const mime = book.mime_type || '';
    return mime === 'application/pdf'
        || mime.startsWith('image/')
        || mime.startsWith('video/')
        || mime.startsWith('audio/')
        || mime.startsWith('text/');
};
const previewKind = (book) => {
    const mime = book.mime_type || '';
    if (mime.startsWith('image/')) return 'image';
    if (mime.startsWith('video/')) return 'video';
    if (mime.startsWith('audio/')) return 'audio';
    return 'frame';
};
const fileSize = (book) => {
    if (!book.file_size) return '—';
    if (book.file_size < 1024) return `${book.file_size} B`;
    if (book.file_size < 1024 * 1024) return `${(book.file_size / 1024).toFixed(0)} KB`;
    return `${(book.file_size / 1024 / 1024).toFixed(1)} MB`;
};
const colorClasses = [
    'bg-blue-50 text-blue-700',
    'bg-emerald-50 text-emerald-700',
    'bg-violet-50 text-violet-700',
    'bg-amber-50 text-amber-700',
];

const selectCategory = (slug = '') => {
    form.category = slug;
    form.view = 'all';
};

const selectView = (selectedView) => {
    form.view = selectedView;
    form.category = '';
};

const openModal = (name) => {
    if (!page.props.auth.user) {
        router.visit(route('login'));
        return;
    }
    showNewMenu.value = false;
    if (name === 'upload' && !bookForm.processing) {
        bookForm.category_id = props.categories.find((category) => category.slug === form.category)?.id || '';
    }
    modal.value = name;
};

const closeModal = () => {
    modal.value = null;
    isDragging.value = false;
    fileError.value = '';
    folderForm.clearErrors();
    bookForm.clearErrors();
};

const createFolder = () => folderForm.post(route('categories.store'), {
    preserveScroll: true,
    onSuccess: () => { folderForm.reset(); closeModal(); },
});

const uploadBook = () => {
    if (bookForm.processing) return;
    if (!bookForm.files.length) {
        fileError.value = 'Pilih atau jatuhkan file terlebih dahulu.';
        return;
    }

    clearTimeout(uploadTimer);
    Object.assign(uploadStatus, { visible: true, count: bookForm.files.length, complete: false, error: '' });
    bookForm.post(route('books.store'), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            uploadStatus.complete = true;
            uploadTimer = setTimeout(() => { uploadStatus.visible = false; }, 6000);
            bookForm.reset();
            if (fileInput.value) fileInput.value.value = '';
            closeModal();
        },
        onError: (errors) => {
            uploadStatus.error = Object.values(errors)[0] || 'Upload gagal. Silakan coba lagi.';
            modal.value = 'upload';
        },
        onCancel: () => {
            uploadStatus.error = 'Upload dibatalkan. File masih bisa dicoba kembali.';
        },
        onFinish: () => {
            if (!uploadStatus.complete && !uploadStatus.error) {
                uploadStatus.error = 'Upload belum berhasil. Periksa koneksi dan coba lagi.';
                modal.value = 'upload';
            }
        },
    });
};

const chooseFiles = (fileList) => {
    if (bookForm.processing) {
        fileError.value = 'Tunggu upload yang sedang berjalan selesai sebelum menambahkan file.';
        return false;
    }
    const files = Array.from(fileList || []);
    bookForm.files = [];
    if (!files.length) return false;
    if (files.length > 20) {
        fileError.value = 'Maksimal 20 file dalam sekali upload.';
        return false;
    }
    const oversized = files.find((file) => file.size > 100 * 1024 * 1024);
    if (oversized) {
        fileError.value = `${oversized.name} melebihi batas 100 MB.`;
        return false;
    }

    fileError.value = '';
    bookForm.clearErrors();
    bookForm.files = files;
    return true;
};

const handleFileInput = (event) => chooseFiles(event.target.files);
const handleDrop = (event) => {
    if (!hasFiles(event)) return;
    event.preventDefault();
    resetDrag();
    const files = event.dataTransfer?.files;
    if (!files?.length) return;
    if (!page.props.auth.user) {
        router.visit(route('login'));
        return;
    }
    if (!chooseFiles(files)) { modal.value = 'upload'; return; }
    if (modal.value !== 'upload') {
        bookForm.category_id = props.categories.find((category) => category.slug === form.category)?.id || '';
    }
    modal.value = null;
    closeContextMenu(false);
    uploadBook();
};

const hasFiles = (event) => Array.from(event.dataTransfer?.types || []).includes('Files');
const resetDrag = () => { dragDepth = 0; isDragging.value = false; };
const handleDragEnter = (event) => {
    if (!hasFiles(event)) return;
    event.preventDefault();
    dragDepth += 1;
    isDragging.value = true;
};
const handleDragOver = (event) => {
    if (!hasFiles(event)) return;
    event.preventDefault();
    event.dataTransfer.dropEffect = bookForm.processing ? 'none' : 'copy';
};
const handleDragLeave = (event) => {
    if (!hasFiles(event)) return;
    dragDepth = Math.max(0, dragDepth - 1);
    if (!dragDepth) resetDrag();
};

const closeContextMenu = (restoreFocus = true) => {
    contextMenu.value = null;
    if (restoreFocus) contextTrigger?.focus({ preventScroll: true });
};
const openContextMenu = (event, book) => {
    if (!page.props.auth.user) return;
    event.preventDefault();
    event.stopPropagation();
    contextTrigger = event.currentTarget;
    const bounds = contextTrigger.getBoundingClientRect();
    contextMenu.value = { book, x: event.clientX || bounds.left + 20, y: event.clientY || bounds.bottom };
    showNewMenu.value = false;
};
const beginRename = async (book) => {
    if (!page.props.auth.user) return;
    actionBook.value = book;
    renameForm.reset();
    renameForm.clearErrors();
    renameForm.name = book.original_filename || book.title;
    actionDialog.value = 'rename';
    await nextTick();
    renameInput.value?.focus();
    const dot = renameForm.name.lastIndexOf('.');
    renameInput.value?.setSelectionRange(0, dot > 0 ? dot : renameForm.name.length);
};
const closeActionDialog = () => {
    if (renameForm.processing) return;
    shareRequest?.abort();
    actionDialog.value = null;
    contextTrigger?.focus({ preventScroll: true });
};
const renameBook = () => renameForm.patch(route('books.rename', actionBook.value.uuid), {
    preserveScroll: true,
    onSuccess: () => { actionDialog.value = null; },
});
const createShareLink = async () => {
    if (shareState.processing) return;
    shareState.processing = true;
    shareState.error = '';
    shareRequest = new AbortController();
    try {
        const csrfCookie = document.cookie.split('; ').find((cookie) => cookie.startsWith('XSRF-TOKEN='));
        const response = await fetch(route('books.share', actionBook.value.uuid), {
            method: 'POST', credentials: 'same-origin', signal: shareRequest.signal,
            headers: { Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(csrfCookie?.slice('XSRF-TOKEN='.length) || '') },
        });
        if (!response.ok) throw new Error(response.status === 419 || response.status === 401
            ? 'Sesi berakhir. Muat ulang halaman dan masuk kembali.' : 'Tautan belum bisa dibuat. Pastikan file masih tersedia, lalu coba lagi.');
        const result = await response.json();
        shareState.url = result.url;
        shareState.expires = new Date(result.expires_at).toLocaleDateString('id-ID', { dateStyle: 'long' });
    } catch (error) {
        if (error.name !== 'AbortError') shareState.error = error.message;
    } finally { shareState.processing = false; }
};
const copyShareLink = async () => {
    try {
        await navigator.clipboard.writeText(shareState.url);
        shareState.copied = true;
        shareState.error = '';
    } catch {
        shareInput.value?.focus();
        shareInput.value?.select();
        shareState.error = 'Tautan sudah dipilih. Tekan Ctrl+C untuk menyalinnya.';
    }
};
const runFileAction = (action, book) => {
    closeContextMenu(false);
    if (action === 'preview') openPreview(book);
    if (action === 'rename') beginRename(book);
    if (action === 'download') window.location.assign(route('books.download', book.uuid));
    if (action === 'star') toggleStar(book);
    if (action === 'delete') deleteBook(book);
    if (action === 'share') {
        actionBook.value = book;
        Object.assign(shareState, { url: '', expires: '', processing: false, error: '', copied: false });
        actionDialog.value = 'share';
    }
};

const readableSize = (bytes) => bytes < 1024 * 1024
    ? `${Math.ceil(bytes / 1024)} KB`
    : `${(bytes / 1024 / 1024).toFixed(1)} MB`;

const toggleStar = (book) => router.patch(route('books.star', book.uuid), {}, { preserveScroll: true });
const deleteBook = (book) => {
    if (window.confirm(`Hapus “${book.title}”? Tindakan ini tidak dapat dibatalkan.`)) {
        router.delete(route('books.destroy', book.uuid), { preserveScroll: true });
    }
};

const openPreview = (book) => {
    previewBook.value = book;
    document.body.style.overflow = 'hidden';
};

const closePreview = () => {
    previewBook.value = null;
    document.body.style.overflow = '';
};

const handleKeydown = (event) => {
    if (event.key === 'Escape') {
        resetDrag();
        if (contextMenu.value) closeContextMenu();
        else if (actionDialog.value) closeActionDialog();
        else if (previewBook.value) closePreview();
        else if (modal.value) closeModal();
    }
};

onMounted(() => {
    window.addEventListener('keydown', handleKeydown);
    window.addEventListener('dragenter', handleDragEnter);
    window.addEventListener('dragover', handleDragOver);
    window.addEventListener('dragleave', handleDragLeave);
    window.addEventListener('drop', handleDrop);
    window.addEventListener('dragend', resetDrag);
    window.addEventListener('blur', resetDrag);
    if (form.action && page.props.auth.user) {
        modal.value = form.action;
    }
});

onUnmounted(() => {
    window.removeEventListener('keydown', handleKeydown);
    window.removeEventListener('dragenter', handleDragEnter);
    window.removeEventListener('dragover', handleDragOver);
    window.removeEventListener('dragleave', handleDragLeave);
    window.removeEventListener('drop', handleDrop);
    window.removeEventListener('dragend', resetDrag);
    window.removeEventListener('blur', resetDrag);
    clearTimeout(timer);
    clearTimeout(uploadTimer);
    shareRequest?.abort();
    document.body.style.overflow = '';
});
</script>

<template>
    <Head title="Pustaka Saya" />

    <div class="min-h-screen bg-white text-slate-800">
        <div v-if="isDragging" class="pointer-events-none fixed inset-3 z-[110] grid place-items-center rounded-3xl border-2 border-dashed border-blue-500 bg-blue-50/95 backdrop-blur-sm">
            <div class="text-center"><div class="mx-auto grid size-20 place-items-center rounded-3xl bg-blue-600 text-white shadow-xl shadow-blue-200"><DriveIcon name="upload" class="size-10" /></div><p class="mt-5 text-2xl font-semibold text-blue-900">{{ bookForm.processing ? 'Upload sedang berlangsung' : 'Lepaskan untuk mengupload' }}</p><p class="mt-2 text-sm text-blue-700">{{ bookForm.processing ? 'Tunggu sampai upload selesai untuk menambahkan file.' : `Langsung disimpan ke ${categories.find((category) => category.id == bookForm.category_id)?.name || activeLabel}` }}</p><p class="mt-2 text-xs text-blue-500">Maksimal 20 file · 100 MB per file</p></div>
        </div>
        <header class="fixed inset-x-0 top-0 z-30 flex h-16 items-center border-b border-slate-200 bg-white px-4">
            <Link href="/" class="mr-3 flex shrink-0 items-center gap-3 md:mr-0 md:w-60 md:px-2" aria-label="Digital Library">
                <ApplicationLogo class="size-11 shrink-0" />
                <span class="hidden text-xl font-semibold tracking-tight text-slate-700 md:inline">Digital Library</span>
            </Link>

            <div class="mx-auto flex h-12 w-full max-w-3xl items-center rounded-2xl bg-slate-100 px-4 transition focus-within:bg-white focus-within:shadow-md">
                <svg class="mr-3 size-5 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                <input v-model="form.search" class="w-full border-0 bg-transparent p-0 text-[15px] placeholder:text-slate-500 focus:ring-0" type="search" :placeholder="$page.props.auth.user ? 'Cari nama atau isi dokumen' : 'Cari dalam pustaka'" />
            </div>

            <div class="ml-2 flex items-center gap-2 sm:ml-6">
                <button @click="modal = 'help'" class="rounded-full p-2.5 text-slate-600 hover:bg-slate-100" title="Bantuan"><svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.1 9a3 3 0 1 1 5.2 2c-1.3 1-2.3 1.7-2.3 3"/><path d="M12 18h.01"/></svg></button>
                <Link v-if="$page.props.auth.user" :href="route('profile.edit')" class="grid size-9 place-items-center rounded-full bg-blue-600 text-sm font-semibold text-white">{{ $page.props.auth.user.name.charAt(0) }}</Link>
                <Link v-else :href="route('login')" class="whitespace-nowrap rounded-full border border-slate-300 px-4 py-2 text-sm font-semibold text-blue-600 hover:bg-blue-50">Masuk</Link>
            </div>
        </header>

        <aside class="fixed bottom-0 left-0 top-16 z-20 hidden w-64 flex-col bg-white px-3 py-5 md:flex">
            <div class="relative">
                <button @click="showNewMenu = !showNewMenu" class="flex items-center gap-3 rounded-2xl bg-white px-5 py-4 text-sm font-medium shadow-[0_1px_3px_1px_rgba(60,64,67,.15)] transition hover:bg-slate-50 hover:shadow-md">
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
                    Tambah baru
                </button>
                <div v-if="showNewMenu" class="absolute left-0 top-16 z-30 w-56 rounded-xl border border-slate-100 bg-white py-2 shadow-xl">
                    <button @click="openModal('folder')" class="flex w-full items-center gap-3 px-4 py-3 text-sm hover:bg-slate-50"><DriveIcon name="folder" class="size-5 text-blue-600" /> Folder baru</button>
                    <button @click="openModal('upload')" class="flex w-full items-center gap-3 border-t px-4 py-3 text-sm hover:bg-slate-50"><DriveIcon name="upload" class="size-5 text-blue-600" /> Upload file</button>
                </div>
            </div>

            <nav class="mt-6 space-y-1 text-sm">
                <button @click="selectCategory()" class="flex w-full items-center gap-3 rounded-full px-4 py-2.5" :class="!form.category && form.view === 'all' ? 'bg-blue-100 font-semibold text-blue-800' : 'hover:bg-slate-100'">
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7h5l2 2h11v10H3z"/><path d="M3 7V5h6l2 2"/></svg>
                    Pustaka Saya
                </button>
                <button @click="selectView('recent')" class="flex w-full items-center gap-3 rounded-full px-4 py-2.5" :class="form.view === 'recent' ? 'bg-blue-100 font-semibold text-blue-800' : 'hover:bg-slate-100'"><svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg> Terbaru</button>
                <button @click="selectView('starred')" class="flex w-full items-center gap-3 rounded-full px-4 py-2.5" :class="form.view === 'starred' ? 'bg-blue-100 font-semibold text-blue-800' : 'hover:bg-slate-100'"><svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-3-5.6 3 1.1-6.2L3 9.6l6.2-.9z"/></svg> Berbintang</button>
            </nav>

            <p class="mb-2 mt-7 px-4 text-xs font-semibold uppercase tracking-wider text-slate-400">Kategori</p>
            <nav class="space-y-1 overflow-y-auto text-sm">
                <button v-for="category in categories" :key="category.id" @click="selectCategory(category.slug)" class="flex w-full items-center gap-3 rounded-full px-4 py-2.5" :class="form.category === category.slug ? 'bg-blue-100 font-semibold text-blue-800' : 'hover:bg-slate-100'">
                    <DriveIcon name="folder" class="size-5 text-slate-500" />
                    <span class="truncate">{{ category.name }}</span>
                </button>
            </nav>

            <div class="mt-auto border-t border-slate-200 px-3 pt-5">
                <div class="mb-2 flex items-center gap-3 text-sm"><DriveIcon name="cloud" class="size-5" /> Penyimpanan</div>
                <div class="h-1.5 overflow-hidden rounded-full bg-slate-200"><div class="h-full w-[18%] rounded-full bg-blue-600"></div></div>
                <p class="mt-2 text-xs text-slate-500">{{ stats.copies }} dokumen tersimpan</p>
            </div>
        </aside>

        <main class="min-h-screen pt-16 md:pl-64">
            <div class="mx-auto max-w-[1500px] px-5 py-6 lg:px-8">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-medium text-slate-800">{{ activeLabel }}</h1>
                        <p class="mt-1 text-sm text-slate-500">{{ books.total }} file</p>
                    </div>
                    <div class="flex items-center gap-3">
                    <button @click="openModal('upload')" class="inline-flex items-center gap-2 rounded-xl bg-blue-600 px-3 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-blue-700"><DriveIcon name="upload" class="size-4" /><span class="hidden sm:inline">Upload file</span></button>
                    <div class="flex items-center rounded-full border border-slate-300 p-1">
                        <button @click="view = 'list'" class="rounded-full p-2" :class="view === 'list' ? 'bg-blue-100 text-blue-700' : 'text-slate-500'" title="Tampilan daftar"><svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg></button>
                        <button @click="view = 'grid'" class="rounded-full p-2" :class="view === 'grid' ? 'bg-blue-100 text-blue-700' : 'text-slate-500'" title="Tampilan grid"><svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg></button>
                    </div>
                    </div>
                </div>

                <div class="mt-6 flex gap-2 overflow-x-auto pb-2">
                    <button @click="selectCategory()" class="whitespace-nowrap rounded-lg border px-4 py-2 text-sm" :class="!form.category ? 'border-blue-600 bg-blue-50 text-blue-700' : 'border-slate-300 hover:bg-slate-50'">Semua</button>
                    <button v-for="category in categories" :key="category.id" @click="selectCategory(category.slug)" class="whitespace-nowrap rounded-lg border px-4 py-2 text-sm" :class="form.category === category.slug ? 'border-blue-600 bg-blue-50 text-blue-700' : 'border-slate-300 hover:bg-slate-50'">{{ category.name }}</button>
                </div>

                <section class="mt-7">
                    <h2 class="mb-4 text-sm font-semibold text-slate-700">Akses cepat</h2>
                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <button v-for="(category, index) in categories.slice(0, 4)" :key="category.id" @click="selectCategory(category.slug)" class="flex items-center gap-3 rounded-xl bg-slate-100 p-3 text-left text-sm font-medium hover:bg-slate-200">
                            <span class="grid size-10 place-items-center rounded-xl" :class="colorClasses[index % colorClasses.length]"><DriveIcon name="folder" class="size-6" /></span>
                            <span class="truncate">{{ category.name }}</span>
                        </button>
                    </div>
                </section>

                <section class="mt-9">
                    <div class="mb-3 flex items-center justify-between gap-4"><h2 class="text-sm font-semibold text-slate-700">Dokumen</h2><span class="text-right text-xs text-slate-400">Klik kanan untuk aksi · Tarik file untuk upload</span></div>

                    <div v-if="!books.data.length" class="grid min-h-72 place-items-center rounded-2xl border border-dashed border-slate-300 bg-slate-50 text-center">
                        <div><div class="mx-auto mb-4 grid size-16 place-items-center rounded-2xl bg-blue-100 text-blue-600"><DriveIcon name="upload" class="size-8" /></div><p class="font-medium">Belum ada file di sini</p><p class="mt-1 text-sm text-slate-500">Tarik file ke halaman ini untuk langsung mengupload.</p><button @click="openModal('upload')" class="mt-4 text-sm font-semibold text-blue-600 hover:underline">Pilih file dari perangkat</button></div>
                    </div>

                    <div v-else-if="view === 'list'" class="overflow-x-auto rounded-xl border border-slate-200">
                        <div class="grid grid-cols-[minmax(240px,2fr)_minmax(130px,1fr)_90px_90px_112px] gap-4 border-b bg-slate-50 px-4 py-3 text-xs font-medium text-slate-500">
                            <span>Nama</span><span>Pemilik</span><span>Tipe</span><span>Ukuran</span><span></span>
                        </div>
                        <article v-for="book in books.data" :key="book.id" :data-file-id="book.uuid" tabindex="0" :aria-label="book.title" @contextmenu="openContextMenu($event, book)" @keydown.f2.prevent="beginRename(book)" @keydown.shift.f10.prevent="openContextMenu($event, book)" class="grid grid-cols-[minmax(240px,2fr)_minmax(130px,1fr)_90px_90px_112px] items-center gap-4 border-b border-slate-100 px-4 py-3 text-sm outline-none last:border-0 hover:bg-blue-50/50 focus-visible:bg-blue-50 focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-blue-500" :class="contextMenu?.book.uuid === book.uuid && 'bg-blue-50'">
                            <div class="flex min-w-0 items-center gap-3">
                                <FileIcon :book="book" class="h-10 w-9 text-[36px]" />
                                <div class="min-w-0"><button v-if="(book.object_key || book.file_path) && $page.props.auth.user" @click="openPreview(book)" class="block w-full truncate text-left font-medium text-slate-800 hover:text-blue-600 hover:underline">{{ book.title }}</button><p v-else class="truncate font-medium text-slate-800">{{ book.title }}</p><p class="truncate text-xs text-slate-500">{{ book.category.name }}<template v-if="book.published_year"> · {{ book.published_year }}</template></p></div>
                            </div>
                            <div class="min-w-0"><span class="truncate text-slate-600">{{ book.author }}</span><DocumentSearchStatus v-if="$page.props.auth.user" :book="book" /></div>
                            <span class="text-slate-500">{{ fileType(book) }}</span>
                            <span class="text-slate-500">{{ fileSize(book) }}</span>
                            <div v-if="$page.props.auth.user" class="flex items-center justify-end">
                                <button @click="toggleStar(book)" class="rounded-full p-2 hover:bg-slate-200" :class="book.is_starred ? 'text-amber-500' : 'text-slate-400'" :title="book.is_starred ? 'Hapus bintang' : 'Beri bintang'"><DriveIcon name="star" class="size-[18px]" :class="book.is_starred && 'fill-amber-100'" /></button>
                                <a v-if="book.object_key || book.file_path" :href="route('books.download', book.uuid)" class="rounded-full p-2 text-slate-500 hover:bg-slate-200" title="Download"><DriveIcon name="download" class="size-[18px]" /></a>
                                <button @click="openContextMenu($event, book)" class="rounded-full p-2 text-slate-500 hover:bg-slate-200" :aria-label="`Opsi ${book.title}`" aria-haspopup="menu" :aria-expanded="contextMenu?.book.uuid === book.uuid"><DriveIcon name="more" class="size-[18px]" /></button>
                            </div>
                            <Link v-else :href="route('login')" class="text-xs font-semibold text-blue-600">Masuk untuk aksi</Link>
                        </article>
                    </div>

                    <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                        <article v-for="book in books.data" :key="book.id" :data-file-id="book.uuid" tabindex="0" :aria-label="book.title" @contextmenu="openContextMenu($event, book)" @keydown.f2.prevent="beginRename(book)" @keydown.shift.f10.prevent="openContextMenu($event, book)" class="group overflow-hidden rounded-2xl border bg-slate-50/80 outline-none transition hover:border-blue-200 hover:bg-blue-50/40 hover:shadow-lg hover:shadow-slate-200/50 focus-visible:ring-2 focus-visible:ring-blue-500" :class="contextMenu?.book.uuid === book.uuid ? 'border-blue-400 bg-blue-50 ring-1 ring-blue-300' : 'border-slate-200'">
                            <div class="flex items-center justify-between gap-2 p-3"><div class="flex min-w-0 items-center gap-2"><FileIcon :book="book" class="h-8 w-7 text-[28px]" /><span class="truncate text-sm font-medium" :title="book.title">{{ book.title }}</span></div><button v-if="$page.props.auth.user" @click="openContextMenu($event, book)" class="shrink-0 rounded-full p-1.5 text-slate-500 hover:bg-blue-100 hover:text-blue-700" :aria-label="`Opsi ${book.title}`" aria-haspopup="menu" :aria-expanded="contextMenu?.book.uuid === book.uuid"><DriveIcon name="more" class="size-5" /></button></div>
                            <button v-if="(book.object_key || book.file_path) && $page.props.auth.user" @click="openPreview(book)" class="mx-3 block h-44 w-[calc(100%_-_1.5rem)] overflow-hidden rounded-lg bg-white text-center shadow-sm hover:ring-2 hover:ring-blue-400"><FileThumbnail :book="book" /></button><div v-else class="mx-3 h-44 w-[calc(100%_-_1.5rem)] overflow-hidden rounded-lg bg-white text-center shadow-sm"><FileThumbnail :book="book" /></div>
                            <div class="flex items-center justify-between gap-2 p-3 text-xs text-slate-500"><span class="flex min-w-0 items-center gap-1.5"><DriveIcon name="folder" class="size-3.5 shrink-0" /><span class="truncate">{{ book.category.name }}</span></span><div class="flex shrink-0 items-center gap-2"><span>{{ fileSize(book) }}</span><button v-if="$page.props.auth.user" @click="toggleStar(book)" class="rounded-full p-1 hover:bg-slate-200" :class="book.is_starred ? 'text-amber-500' : 'text-slate-400'" :title="book.is_starred ? 'Hapus bintang' : 'Beri bintang'"><DriveIcon name="star" class="size-4" :class="book.is_starred && 'fill-amber-100'" /></button></div></div>
                            <DocumentSearchStatus v-if="$page.props.auth.user" :book="book" class="px-3 pb-3" />
                        </article>
                    </div>

                    <div v-if="books.links.length > 3" class="mt-6 flex flex-wrap justify-center gap-2">
                        <Link v-for="link in books.links" :key="link.label" :href="link.url || '#'" v-html="link.label" class="rounded-lg border px-3 py-2 text-sm" :class="[link.active ? 'border-blue-600 bg-blue-600 text-white' : 'border-slate-300 bg-white hover:bg-slate-50', !link.url && 'pointer-events-none opacity-40']" />
                    </div>
                </section>
            </div>
        </main>

        <div v-if="previewBook" class="fixed inset-0 z-[80] flex flex-col bg-slate-950/90 p-2 backdrop-blur-sm sm:p-4" @click.self="closePreview">
            <div class="mb-2 flex h-14 shrink-0 items-center gap-3 rounded-2xl bg-slate-900/95 px-4 text-white shadow-xl">
                <FileIcon :book="previewBook" class="h-10 w-9 text-[36px]" />
                <div class="min-w-0 flex-1"><p class="truncate text-sm font-semibold">{{ previewBook.original_filename || previewBook.title }}</p><p class="text-xs text-slate-400">{{ fileType(previewBook) }} · {{ fileSize(previewBook) }}</p></div>
                <button @click="toggleStar(previewBook)" class="rounded-full p-2.5 hover:bg-white/10" :class="previewBook.is_starred ? 'text-amber-400' : 'text-slate-300'" title="Beri bintang"><DriveIcon name="star" class="size-5" /></button>
                <a :href="route('books.download', previewBook.uuid)" class="rounded-full p-2.5 text-slate-200 hover:bg-white/10" title="Download"><DriveIcon name="download" class="size-5" /></a>
                <button @click="closePreview" class="grid size-10 place-items-center rounded-full text-slate-200 hover:bg-white/10" title="Tutup (Esc)"><DriveIcon name="close" class="size-5" /></button>
            </div>

            <div class="relative flex min-h-0 flex-1 items-center justify-center overflow-hidden rounded-2xl bg-slate-900 shadow-2xl">
                <img v-if="canPreview(previewBook) && previewKind(previewBook) === 'image'" :src="route('books.open', previewBook.uuid)" :alt="previewBook.title" class="max-h-full max-w-full object-contain" />
                <video v-else-if="canPreview(previewBook) && previewKind(previewBook) === 'video'" :src="route('books.open', previewBook.uuid)" class="max-h-full max-w-full" controls autoplay />
                <div v-else-if="canPreview(previewBook) && previewKind(previewBook) === 'audio'" class="w-full max-w-xl rounded-2xl bg-white p-8 text-center"><div class="text-6xl">♫</div><p class="mt-4 truncate font-semibold text-slate-800">{{ previewBook.title }}</p><audio :src="route('books.open', previewBook.uuid)" class="mt-6 w-full" controls autoplay /></div>
                <iframe v-else-if="canPreview(previewBook)" :src="route('books.open', previewBook.uuid)" class="size-full border-0 bg-white" :title="previewBook.title"></iframe>
                <div v-else class="max-w-md p-8 text-center text-white"><div class="mx-auto grid size-24 place-items-center rounded-3xl bg-slate-800 text-5xl">▤</div><h2 class="mt-6 text-xl font-semibold">Preview tidak tersedia</h2><p class="mt-2 text-sm leading-6 text-slate-400">Browser belum mendukung preview {{ fileType(previewBook) }}. File tetap aman di object storage dan dapat diunduh.</p><a :href="route('books.download', previewBook.uuid)" class="mt-6 inline-flex rounded-xl bg-blue-600 px-5 py-3 text-sm font-semibold text-white hover:bg-blue-500">Download file</a></div>
            </div>
        </div>

        <div v-if="$page.props.flash?.success && !uploadStatus.visible" role="status" class="fixed bottom-6 right-6 z-50 rounded-xl bg-slate-900 px-5 py-3 text-sm font-medium text-white shadow-xl">
            {{ $page.props.flash.success }}
        </div>

        <FileContextMenu v-if="contextMenu" :book="contextMenu.book" :x="contextMenu.x" :y="contextMenu.y" @close="closeContextMenu" @action="runFileAction" />

        <div v-if="uploadStatus.visible" role="status" aria-live="polite" class="fixed bottom-5 right-5 z-[75] w-80 max-w-[calc(100vw-40px)] overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xl shadow-slate-300/50">
            <div class="flex items-center gap-3 p-4">
                <span class="grid size-10 shrink-0 place-items-center rounded-xl" :class="uploadStatus.error ? 'bg-red-50 text-red-600' : uploadStatus.complete ? 'bg-emerald-50 text-emerald-600' : 'bg-blue-50 text-blue-600'"><DriveIcon :name="uploadStatus.error ? 'close' : uploadStatus.complete ? 'check' : 'upload'" class="size-5" /></span>
                <div class="min-w-0 flex-1"><p class="text-sm font-semibold">{{ uploadStatus.error ? 'Upload belum selesai' : uploadStatus.complete ? `${uploadStatus.count} file berhasil diupload` : `Mengupload ${uploadStatus.count} file` }}</p><p class="mt-0.5 text-xs text-slate-500">{{ uploadStatus.error || (uploadStatus.complete ? 'File sudah tersimpan di pustaka.' : `${bookForm.progress?.percentage || 0}% · Jangan tutup halaman ini`) }}</p></div>
                <button v-if="!bookForm.processing" @click="uploadStatus.visible = false" aria-label="Tutup status upload" class="rounded-full p-1 text-slate-400 hover:bg-slate-100"><DriveIcon name="close" class="size-4" /></button>
            </div>
            <div v-if="bookForm.processing" role="progressbar" aria-label="Progres upload" :aria-valuenow="bookForm.progress?.percentage || 0" aria-valuemin="0" aria-valuemax="100" class="h-1 bg-blue-100"><div class="h-full bg-blue-600 transition-all" :style="{ width: `${bookForm.progress?.percentage || 0}%` }"></div></div>
        </div>

        <Modal :show="Boolean(actionDialog)" max-width="md" :closeable="!renameForm.processing" @close="closeActionDialog">
            <div v-if="actionBook" class="p-6">
                <div class="flex items-center gap-3"><FileIcon :book="actionBook" class="h-12 w-10 text-[40px]" /><div class="min-w-0 flex-1"><h2 class="text-lg font-semibold">{{ actionDialog === 'rename' ? 'Ganti nama file' : 'Bagikan file' }}</h2><p class="truncate text-sm text-slate-500">{{ actionBook.title }}</p></div><button @click="closeActionDialog" :disabled="renameForm.processing" aria-label="Tutup dialog" class="rounded-full p-2 text-slate-500 hover:bg-slate-100"><DriveIcon name="close" class="size-5" /></button></div>
                <form v-if="actionDialog === 'rename'" @submit.prevent="renameBook" class="mt-6">
                    <label for="rename-file" class="text-sm font-medium">Nama file</label>
                    <input id="rename-file" ref="renameInput" v-model="renameForm.name" :disabled="renameForm.processing" class="mt-2 w-full rounded-xl border-slate-300 focus:border-blue-600 focus:ring-blue-600" maxlength="255" required :aria-invalid="Boolean(renameForm.errors.name)" aria-describedby="rename-hint" />
                    <p id="rename-hint" class="mt-2 text-xs text-slate-500">Pertahankan ekstensi agar jenis file tetap sesuai.</p>
                    <p v-if="renameForm.errors.name" role="alert" class="mt-2 text-sm text-red-600">{{ renameForm.errors.name }}</p>
                    <div class="mt-6 flex justify-end gap-2"><button type="button" @click="closeActionDialog" :disabled="renameForm.processing" class="rounded-xl px-4 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-100">Batal</button><button :disabled="renameForm.processing" class="rounded-xl bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50">{{ renameForm.processing ? 'Menyimpan...' : 'Simpan' }}</button></div>
                </form>
                <div v-else class="mt-6">
                    <div class="flex gap-3 rounded-xl bg-blue-50 p-4"><DriveIcon name="link" class="mt-0.5 size-5 shrink-0 text-blue-600" /><div><p class="text-sm font-semibold text-blue-950">Siapa pun yang memiliki tautan</p><p class="mt-1 text-sm leading-6 text-blue-800">Dapat mengunduh file ini tanpa login. Tautan berlaku selama 7 hari.</p></div></div>
                    <label v-if="shareState.url" class="mt-5 block text-sm font-medium">Tautan download<input ref="shareInput" :value="shareState.url" readonly @click="$event.target.select()" class="mt-2 w-full rounded-xl border-slate-300 bg-slate-50 text-sm" /><span class="mt-2 block text-xs font-normal text-slate-500">Berlaku sampai {{ shareState.expires }}</span></label>
                    <p v-if="shareState.error" role="alert" class="mt-3 text-sm text-red-600">{{ shareState.error }}</p>
                    <div class="mt-6 flex justify-end gap-2"><button @click="closeActionDialog" class="rounded-xl px-4 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-100">Selesai</button><button @click="shareState.url ? copyShareLink() : createShareLink()" :disabled="shareState.processing" class="inline-flex items-center gap-2 rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50"><DriveIcon :name="shareState.copied ? 'check' : 'link'" class="size-4" />{{ shareState.processing ? 'Membuat...' : shareState.copied ? 'Tautan disalin' : shareState.url ? 'Salin tautan' : 'Buat tautan' }}</button></div>
                </div>
            </div>
        </Modal>

        <div v-if="modal" class="fixed inset-0 z-50 grid place-items-center bg-slate-900/40 p-4 backdrop-blur-sm" @click.self="closeModal">
            <form v-if="modal === 'folder'" @submit.prevent="createFolder" class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl">
                <div class="flex items-center justify-between"><h2 class="text-xl font-semibold">Folder baru</h2><button type="button" @click="closeModal" class="rounded-full p-2 text-slate-500 hover:bg-slate-100">×</button></div>
                <p class="mt-1 text-sm text-slate-500">Folder digunakan sebagai kategori koleksi buku.</p>
                <label class="mt-6 block text-sm font-medium">Nama folder</label>
                <input v-model="folderForm.name" autofocus class="mt-2 w-full rounded-xl border-slate-300 focus:border-blue-600 focus:ring-blue-600" maxlength="80" required />
                <p v-if="folderForm.errors.name" class="mt-2 text-sm text-red-600">{{ folderForm.errors.name }}</p>
                <div class="mt-6 flex justify-end gap-3"><button type="button" @click="closeModal" class="rounded-lg px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-100">Batal</button><button :disabled="folderForm.processing" class="rounded-lg bg-blue-600 px-5 py-2 text-sm font-semibold text-white disabled:opacity-50">{{ folderForm.processing ? 'Membuat...' : 'Buat' }}</button></div>
            </form>

            <form v-else-if="modal === 'upload'" @submit.prevent="uploadBook" class="max-h-[90vh] w-full max-w-xl overflow-y-auto rounded-2xl bg-white p-6 shadow-2xl">
                <div class="flex items-center justify-between"><div><h2 class="text-xl font-semibold">Upload file</h2><p class="mt-1 text-sm text-slate-500">Semua jenis file, maksimal 100 MB per file.</p></div><button type="button" @click="closeModal" class="rounded-full p-2 text-slate-500 hover:bg-slate-100">×</button></div>
                <div @click="!bookForm.processing && fileInput?.click()" role="button" tabindex="0" @keydown.enter.prevent="!bookForm.processing && fileInput?.click()" @keydown.space.prevent="!bookForm.processing && fileInput?.click()" class="mt-6 cursor-pointer rounded-2xl border-2 border-dashed p-8 text-center transition" :class="isDragging ? 'border-blue-600 bg-blue-50' : bookForm.files.length ? 'border-emerald-400 bg-emerald-50' : 'border-slate-300 hover:border-blue-400 hover:bg-slate-50'">
                    <input ref="fileInput" @change="handleFileInput" :disabled="bookForm.processing" type="file" multiple class="hidden" />
                    <template v-if="bookForm.files.length"><div class="mx-auto grid size-12 place-items-center rounded-xl bg-emerald-600 text-2xl text-white">✓</div><p class="mt-3 font-semibold text-slate-800">{{ bookForm.files.length }} file dipilih</p><p class="mt-1 text-sm text-slate-500">Klik untuk mengganti pilihan</p></template>
                    <template v-else><div class="mx-auto grid size-12 place-items-center rounded-xl bg-blue-100 text-2xl text-blue-600">⬆</div><p class="mt-3 font-semibold text-slate-800">Tarik dan jatuhkan file di sini</p><p class="mt-1 text-sm text-slate-500">atau klik untuk memilih file</p></template>
                </div>
                <div v-if="bookForm.files.length" class="mt-4 max-h-40 space-y-2 overflow-y-auto rounded-xl bg-slate-50 p-3"><div v-for="file in bookForm.files" :key="`${file.name}-${file.size}`" class="flex items-center justify-between gap-4 text-sm"><span class="truncate text-slate-700">{{ file.name }}</span><span class="shrink-0 text-xs text-slate-400">{{ readableSize(file.size) }}</span></div></div>
                <span v-if="uploadError" role="alert" class="mt-2 block text-sm text-red-600">{{ uploadError }}</span>
                <label class="mt-5 block"><span class="text-sm font-medium">Simpan ke folder <span class="font-normal text-slate-400">(opsional)</span></span><select v-model="bookForm.category_id" class="mt-2 w-full rounded-xl border-slate-300 focus:border-blue-600 focus:ring-blue-600"><option value="">Folder default</option><option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}</option></select></label>
                <div class="mt-6 flex justify-end gap-3"><button type="button" @click="closeModal" class="rounded-lg px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-100">Batal</button><button :disabled="bookForm.processing || !bookForm.files.length" class="rounded-lg bg-blue-600 px-5 py-2 text-sm font-semibold text-white disabled:opacity-50">{{ bookForm.processing ? `Mengupload ${bookForm.progress?.percentage || 0}%` : `Upload ${bookForm.files.length || ''} file` }}</button></div>
            </form>

            <div v-else-if="modal === 'help'" class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl">
                <div class="flex items-center justify-between"><h2 class="text-xl font-semibold">Bantuan Pustaka</h2><button @click="closeModal" class="rounded-full p-2 text-slate-500 hover:bg-slate-100">×</button></div>
                <ul class="mt-5 space-y-3 text-sm leading-6 text-slate-600"><li><strong>Tambah baru</strong> untuk membuat folder atau mengupload file apa pun.</li><li><strong>★</strong> untuk menambahkan file ke Berbintang.</li><li><strong>↓</strong> untuk mengunduh dan memasukkan file ke Terbaru.</li><li><strong>×</strong> untuk menghapus file.</li></ul>
                <button @click="closeModal" class="mt-6 w-full rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white">Mengerti</button>
            </div>
        </div>
    </div>
</template>
