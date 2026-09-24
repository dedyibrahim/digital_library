<script setup>
defineProps({ book: { type: Object, required: true } });
const labels = {
    pending: 'Menunggu indeks isi',
    processing: 'Membaca isi / OCR…',
    ready: 'Isi dapat dicari',
    partial: 'Sebagian isi dapat dicari',
    empty: 'Tidak ditemukan teks',
    unsupported: 'Pencarian nama file saja',
    failed: 'Indeks gagal; perlu diproses ulang',
};
</script>

<template>
    <div v-if="book.object_key || book.file_path" class="text-xs text-slate-500">
        <p :class="{ 'animate-pulse': book.search_status === 'processing', 'text-amber-700': ['failed', 'partial'].includes(book.search_status) }">{{ labels[book.search_status] || labels.pending }}</p>
        <p v-if="book.search_excerpt" class="mt-1 line-clamp-2 text-slate-700">{{ book.search_excerpt }}</p>
    </div>
</template>
