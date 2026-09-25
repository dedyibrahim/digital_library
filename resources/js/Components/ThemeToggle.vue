<script setup>
import { inject, onMounted, onUnmounted, ref } from 'vue';

const theme = inject('theme');
const isDark = ref(document.documentElement.classList.contains('dark'));
const update = () => { isDark.value = document.documentElement.classList.contains('dark'); };
onMounted(() => window.addEventListener('theme-changed', update));
onUnmounted(() => window.removeEventListener('theme-changed', update));
</script>

<template>
    <button
        type="button"
        class="inline-flex shrink-0 items-center justify-center gap-2 rounded-full border border-slate-300 p-2.5 text-sm font-medium text-slate-700 hover:bg-slate-100 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-500"
        :aria-pressed="isDark"
        aria-label="Tema gelap"
        :title="isDark ? 'Gunakan tema terang' : 'Gunakan tema gelap'"
        @click="theme.setTheme(isDark ? 'light' : 'dark')"
    >
        <svg v-if="isDark" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="4" /><path d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1.5 1.5m11 11L19 19M5 19l1.5-1.5m11-11L19 5" /></svg>
        <svg v-else class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M20.5 14A9 9 0 0 1 10 3.5 9 9 0 1 0 20.5 14Z" /></svg>
        <span class="hidden xl:inline">{{ isDark ? 'Terang' : 'Gelap' }}</span>
    </button>
</template>
