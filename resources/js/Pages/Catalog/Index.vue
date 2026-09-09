<script setup>
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import FileThumbnail from '@/Components/FileThumbnail.vue';

const props = defineProps({
    books: Object,
    categories: Array,
    filters: Object,
    stats: Object,
});
const page = usePage();

const form = reactive({ ...props.filters });
const view = ref('list');
const showNewMenu = ref(false);
const modal = ref(null);
const previewBook = ref(null);
const isDragging = ref(false);
const fileInput = ref(null);
const fileError = ref('');
let timer;

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
const extensionColor = (book) => {
    const extension = fileType(book).toLowerCase();
    if (extension === 'pdf') return 'bg-red-500';
    if (['doc', 'docx', 'odt'].includes(extension)) return 'bg-blue-600';
    if (['xls', 'xlsx', 'csv'].includes(extension)) return 'bg-emerald-600';
    if (['ppt', 'pptx'].includes(extension)) return 'bg-orange-500';
    if (['zip', 'rar', '7z'].includes(extension)) return 'bg-amber-500';
    if (['mp3', 'wav', 'm4a'].includes(extension)) return 'bg-violet-600';
    if (['mp4', 'mkv', 'mov'].includes(extension)) return 'bg-rose-500';
    return 'bg-slate-500';
};
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
    if (!bookForm.files.length) {
        fileError.value = 'Pilih atau jatuhkan file terlebih dahulu.';
        return;
    }

    bookForm.post(route('books.store'), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            bookForm.reset();
            if (fileInput.value) fileInput.value.value = '';
            closeModal();
        },
    });
};

const chooseFiles = (fileList) => {
    const files = Array.from(fileList || []);
    if (!files.length) return;
    if (files.length > 20) {
        fileError.value = 'Maksimal 20 file dalam sekali upload.';
        return;
    }
    const oversized = files.find((file) => file.size > 100 * 1024 * 1024);
    if (oversized) {
        fileError.value = `${oversized.name} melebihi batas 100 MB.`;
        return;
    }

    fileError.value = '';
    bookForm.clearErrors('files');
    bookForm.files = files;
};

const handleFileInput = (event) => chooseFiles(event.target.files);
const handleDrop = (event) => {
    isDragging.value = false;
    const files = event.dataTransfer?.files;
    if (!files?.length) return;
    if (!page.props.auth.user) {
        router.visit(route('login'));
        return;
    }
    chooseFiles(files);
    modal.value = 'upload';
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
        if (previewBook.value) closePreview();
        else if (modal.value) closeModal();
    }
};

onMounted(() => {
    window.addEventListener('keydown', handleKeydown);
    if (form.action && page.props.auth.user) {
        modal.value = form.action;
    }
});

onUnmounted(() => {
    window.removeEventListener('keydown', handleKeydown);
    document.body.style.overflow = '';
});
</script>

<template>
    <Head title="Pustaka Saya" />

    <div class="min-h-screen bg-white text-slate-800" @dragenter.prevent="isDragging = true" @dragover.prevent @drop.prevent="handleDrop">
        <div v-if="isDragging && modal !== 'upload'" class="fixed inset-3 z-[70] grid place-items-center rounded-3xl border-4 border-dashed border-blue-500 bg-blue-50/95" @dragover.prevent @dragleave.prevent="isDragging = false" @drop.prevent="handleDrop">
            <div class="text-center"><div class="mx-auto grid size-20 place-items-center rounded-full bg-blue-600 text-4xl text-white shadow-xl">↓</div><p class="mt-5 text-2xl font-semibold text-blue-900">Jatuhkan file di sini</p><p class="mt-2 text-sm text-blue-700">Semua jenis file · Maksimal 100 MB per file</p></div>
        </div>
        <header class="fixed inset-x-0 top-0 z-30 flex h-16 items-center border-b border-slate-200 bg-white px-4">
            <Link href="/" class="flex w-60 shrink-0 items-center gap-3 px-2">
                <span class="grid size-10 place-items-center rounded-xl bg-blue-600 text-white shadow-sm">
                    <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2Z"/></svg>
                </span>
                <span class="text-xl font-semibold tracking-tight text-slate-700">Pustaka Digital</span>
            </Link>

            <div class="mx-auto flex h-12 w-full max-w-3xl items-center rounded-2xl bg-slate-100 px-4 transition focus-within:bg-white focus-within:shadow-md">
                <svg class="mr-3 size-5 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                <input v-model="form.search" class="w-full border-0 bg-transparent p-0 text-[15px] placeholder:text-slate-500 focus:ring-0" type="search" placeholder="Cari dalam pustaka" />
            </div>

            <div class="ml-6 flex items-center gap-2">
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
                    <button @click="openModal('folder')" class="flex w-full items-center gap-3 px-4 py-3 text-sm hover:bg-slate-50"><span class="text-xl">📁</span> Folder baru</button>
                    <button @click="openModal('upload')" class="flex w-full items-center gap-3 border-t px-4 py-3 text-sm hover:bg-slate-50"><span class="text-xl">⬆️</span> Upload file</button>
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
                    <svg class="size-5 fill-slate-500 text-slate-500" viewBox="0 0 24 24"><path d="M10 4H2v16h20V6H12z"/></svg>
                    <span class="truncate">{{ category.name }}</span>
                </button>
            </nav>

            <div class="mt-auto border-t border-slate-200 px-3 pt-5">
                <div class="mb-2 flex items-center gap-3 text-sm"><svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M2 12h20"/></svg> Penyimpanan</div>
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
                    <div class="flex items-center rounded-full border border-slate-300 p-1">
                        <button @click="view = 'list'" class="rounded-full p-2" :class="view === 'list' ? 'bg-blue-100 text-blue-700' : 'text-slate-500'" title="Tampilan daftar"><svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg></button>
                        <button @click="view = 'grid'" class="rounded-full p-2" :class="view === 'grid' ? 'bg-blue-100 text-blue-700' : 'text-slate-500'" title="Tampilan grid"><svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg></button>
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
                            <span class="grid size-10 place-items-center rounded-lg" :class="colorClasses[index % colorClasses.length]"><svg class="size-5 fill-current" viewBox="0 0 24 24"><path d="M10 4H2v16h20V6H12z"/></svg></span>
                            <span class="truncate">{{ category.name }}</span>
                        </button>
                    </div>
                </section>

                <section class="mt-9">
                    <div class="mb-3 flex items-center justify-between"><h2 class="text-sm font-semibold text-slate-700">Dokumen</h2><span class="text-xs text-slate-400">Diurutkan: Terbaru</span></div>

                    <div v-if="!books.data.length" class="grid min-h-72 place-items-center rounded-2xl border border-dashed border-slate-300 bg-slate-50 text-center">
                        <div><div class="mx-auto mb-3 grid size-14 place-items-center rounded-full bg-slate-200 text-2xl">📚</div><p class="font-medium">Tidak ada dokumen</p><p class="mt-1 text-sm text-slate-500">Coba ubah pencarian atau kategori.</p></div>
                    </div>

                    <div v-else-if="view === 'list'" class="overflow-x-auto rounded-xl border border-slate-200">
                        <div class="grid grid-cols-[minmax(240px,2fr)_minmax(130px,1fr)_90px_90px_112px] gap-4 border-b bg-slate-50 px-4 py-3 text-xs font-medium text-slate-500">
                            <span>Nama</span><span>Pemilik</span><span>Tipe</span><span>Ukuran</span><span></span>
                        </div>
                        <article v-for="book in books.data" :key="book.id" class="grid grid-cols-[minmax(240px,2fr)_minmax(130px,1fr)_90px_90px_112px] items-center gap-4 border-b border-slate-100 px-4 py-3 text-sm last:border-0 hover:bg-blue-50/50">
                            <div class="flex min-w-0 items-center gap-3">
                                <span class="size-10 shrink-0 overflow-hidden rounded-lg ring-1 ring-slate-200"><FileThumbnail :book="book" /></span>
                                <div class="min-w-0"><button v-if="(book.object_key || book.file_path) && $page.props.auth.user" @click="openPreview(book)" class="block w-full truncate text-left font-medium text-slate-800 hover:text-blue-600 hover:underline">{{ book.title }}</button><p v-else class="truncate font-medium text-slate-800">{{ book.title }}</p><p class="truncate text-xs text-slate-500">{{ book.category.name }}<template v-if="book.published_year"> · {{ book.published_year }}</template></p></div>
                            </div>
                            <span class="truncate text-slate-600">{{ book.author }}</span>
                            <span class="text-slate-500">{{ fileType(book) }}</span>
                            <span class="text-slate-500">{{ fileSize(book) }}</span>
                            <div v-if="$page.props.auth.user" class="flex items-center justify-end">
                                <button @click="toggleStar(book)" class="rounded-full p-2 hover:bg-slate-200" :class="book.is_starred ? 'text-amber-500' : 'text-slate-400'" :title="book.is_starred ? 'Hapus bintang' : 'Beri bintang'">★</button>
                                <a v-if="book.object_key || book.file_path" :href="route('books.download', book.uuid)" class="rounded-full p-2 text-slate-500 hover:bg-slate-200" title="Download">↓</a>
                                <span v-else class="cursor-not-allowed p-2 text-slate-300" title="File belum tersedia">↓</span>
                                <button @click="deleteBook(book)" class="rounded-full p-2 text-slate-400 hover:bg-red-50 hover:text-red-600" title="Hapus">×</button>
                            </div>
                            <Link v-else :href="route('login')" class="text-xs font-semibold text-blue-600">Masuk untuk aksi</Link>
                        </article>
                    </div>

                    <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                        <article v-for="book in books.data" :key="book.id" class="overflow-hidden rounded-xl border border-slate-200 bg-slate-50 hover:shadow-md">
                            <div class="flex items-center justify-between p-3"><div class="flex min-w-0 items-center gap-2"><span class="rounded px-1.5 py-1 text-[9px] font-bold text-white" :class="extensionColor(book)">{{ fileType(book).slice(0, 4) }}</span><span class="truncate text-sm font-medium">{{ book.title }}</span></div><button v-if="$page.props.auth.user" @click="toggleStar(book)" :class="book.is_starred ? 'text-amber-500' : 'text-slate-400'" title="Beri bintang">★</button></div>
                            <button v-if="(book.object_key || book.file_path) && $page.props.auth.user" @click="openPreview(book)" class="mx-3 block h-44 w-[calc(100%_-_1.5rem)] overflow-hidden rounded-lg bg-white text-center shadow-sm hover:ring-2 hover:ring-blue-400"><FileThumbnail :book="book" /></button><div v-else class="mx-3 h-44 w-[calc(100%_-_1.5rem)] overflow-hidden rounded-lg bg-white text-center shadow-sm"><FileThumbnail :book="book" /></div>
                            <div class="flex items-center justify-between p-3 text-xs text-slate-500"><span>{{ book.category.name }}</span><div class="flex items-center gap-3"><span>{{ fileSize(book) }}</span><a v-if="(book.object_key || book.file_path) && $page.props.auth.user" :href="route('books.download', book.uuid)" class="font-semibold text-blue-600">Download</a><button v-if="$page.props.auth.user" @click="deleteBook(book)" class="font-semibold text-red-500">Hapus</button></div></div>
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
                <span class="grid size-9 shrink-0 place-items-center rounded-lg bg-blue-600 text-lg">▤</span>
                <div class="min-w-0 flex-1"><p class="truncate text-sm font-semibold">{{ previewBook.original_filename || previewBook.title }}</p><p class="text-xs text-slate-400">{{ fileType(previewBook) }} · {{ fileSize(previewBook) }}</p></div>
                <button @click="toggleStar(previewBook)" class="rounded-full p-2.5 hover:bg-white/10" :class="previewBook.is_starred ? 'text-amber-400' : 'text-slate-300'" title="Beri bintang">★</button>
                <a :href="route('books.download', previewBook.uuid)" class="rounded-full p-2.5 text-slate-200 hover:bg-white/10" title="Download">↓</a>
                <button @click="closePreview" class="grid size-10 place-items-center rounded-full text-2xl text-slate-200 hover:bg-white/10" title="Tutup (Esc)">×</button>
            </div>

            <div class="relative flex min-h-0 flex-1 items-center justify-center overflow-hidden rounded-2xl bg-slate-900 shadow-2xl">
                <img v-if="canPreview(previewBook) && previewKind(previewBook) === 'image'" :src="route('books.open', previewBook.uuid)" :alt="previewBook.title" class="max-h-full max-w-full object-contain" />
                <video v-else-if="canPreview(previewBook) && previewKind(previewBook) === 'video'" :src="route('books.open', previewBook.uuid)" class="max-h-full max-w-full" controls autoplay />
                <div v-else-if="canPreview(previewBook) && previewKind(previewBook) === 'audio'" class="w-full max-w-xl rounded-2xl bg-white p-8 text-center"><div class="text-6xl">♫</div><p class="mt-4 truncate font-semibold text-slate-800">{{ previewBook.title }}</p><audio :src="route('books.open', previewBook.uuid)" class="mt-6 w-full" controls autoplay /></div>
                <iframe v-else-if="canPreview(previewBook)" :src="route('books.open', previewBook.uuid)" class="size-full border-0 bg-white" :title="previewBook.title"></iframe>
                <div v-else class="max-w-md p-8 text-center text-white"><div class="mx-auto grid size-24 place-items-center rounded-3xl bg-slate-800 text-5xl">▤</div><h2 class="mt-6 text-xl font-semibold">Preview tidak tersedia</h2><p class="mt-2 text-sm leading-6 text-slate-400">Browser belum mendukung preview {{ fileType(previewBook) }}. File tetap aman di object storage dan dapat diunduh.</p><a :href="route('books.download', previewBook.uuid)" class="mt-6 inline-flex rounded-xl bg-blue-600 px-5 py-3 text-sm font-semibold text-white hover:bg-blue-500">Download file</a></div>
            </div>
        </div>

        <div v-if="$page.props.flash?.success" class="fixed bottom-6 right-6 z-50 rounded-xl bg-slate-900 px-5 py-3 text-sm font-medium text-white shadow-xl">
            {{ $page.props.flash.success }}
        </div>

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
                <div @click="fileInput?.click()" @dragenter.prevent="isDragging = true" @dragover.prevent="isDragging = true" @dragleave.prevent="isDragging = false" @drop.prevent.stop="handleDrop" class="mt-6 cursor-pointer rounded-2xl border-2 border-dashed p-8 text-center transition" :class="isDragging ? 'border-blue-600 bg-blue-50' : bookForm.files.length ? 'border-emerald-400 bg-emerald-50' : 'border-slate-300 hover:border-blue-400 hover:bg-slate-50'">
                    <input ref="fileInput" @change="handleFileInput" type="file" multiple class="hidden" />
                    <template v-if="bookForm.files.length"><div class="mx-auto grid size-12 place-items-center rounded-xl bg-emerald-600 text-2xl text-white">✓</div><p class="mt-3 font-semibold text-slate-800">{{ bookForm.files.length }} file dipilih</p><p class="mt-1 text-sm text-slate-500">Klik untuk mengganti pilihan</p></template>
                    <template v-else><div class="mx-auto grid size-12 place-items-center rounded-xl bg-blue-100 text-2xl text-blue-600">⬆</div><p class="mt-3 font-semibold text-slate-800">Tarik dan jatuhkan file di sini</p><p class="mt-1 text-sm text-slate-500">atau klik untuk memilih file</p></template>
                </div>
                <div v-if="bookForm.files.length" class="mt-4 max-h-40 space-y-2 overflow-y-auto rounded-xl bg-slate-50 p-3"><div v-for="file in bookForm.files" :key="`${file.name}-${file.size}`" class="flex items-center justify-between gap-4 text-sm"><span class="truncate text-slate-700">{{ file.name }}</span><span class="shrink-0 text-xs text-slate-400">{{ readableSize(file.size) }}</span></div></div>
                <span v-if="fileError || bookForm.errors.files" class="mt-2 block text-sm text-red-600">{{ fileError || bookForm.errors.files }}</span>
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
