<script setup>
defineProps({ name: { type: String, default: 'file' } });
const paths = {
    file: 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z M14 2v6h6 M8 13h8 M8 17h5',
    text: 'M5 5h14 M5 10h14 M5 15h14 M5 20h8',
    cloud: 'M6 18a5 5 0 0 1-1-9 7 7 0 0 1 13-2 5.5 5.5 0 0 1 0 11z',
    folder: 'M3 7V5a2 2 0 0 1 2-2h4l3 4h7a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z',
    upload: 'M12 16V3 M7 8l5-5 5 5 M4 16v4a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-4',
    download: 'M12 3v13 M7 11l5 5 5-5 M4 16v4a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-4',
    rename: 'm16 3 5 5 M4 20l5-1L21 7a2 2 0 0 0-5-5L4 14z M13 21h8',
    share: 'M18 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6 M6 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6 M18 22a3 3 0 1 0 0-6 3 3 0 0 0 0 6 M8.6 10.5l6.8-4 M8.6 13.5l6.8 4',
    star: 'm12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-3-5.6 3 1.1-6.2L3 9.6l6.2-.9z',
    trash: 'M3 6h18 M9 6V3h6v3 M5 6l1 15h12l1-15 M10 10v7 M14 10v7',
    close: 'm6 6 12 12 M6 18 18 6',
    check: 'm5 12 4 4L19 6',
    more: 'M12 5h.01 M12 12h.01 M12 19h.01',
    link: 'M10 13a5 5 0 0 0 7 .5l4-4a5 5 0 0 0-7-7l-2 2 M14 11a5 5 0 0 0-7-.5l-4 4a5 5 0 0 0 7 7l2-2',
    eye: 'M2 12s3-7 10-7 10 7 10 7-3 7-10 7S2 12 2 12 M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0',
    image: 'M4 3h16v18H4z M4 16l5-5 4 4 3-3 4 4 M9 7h.01',
    music: 'M9 18V5l11-2v13 M9 8l11-2 M9 18a3 3 0 1 1-3-3h3 M20 16a3 3 0 1 1-3-3h3',
    video: 'M4 4h16v16H4z m5 4 7 4-7 4z',
    code: 'm8 6-6 6 6 6 M16 6l6 6-6 6 M14 3l-4 18',
    table: 'M3 3h18v18H3z M3 9h18 M3 15h18 M9 3v18',
    slides: 'M3 3h18v13H3z M12 16v5 M7 21l5-5 5 5 M7 7h10 M7 11h6',
    archive: 'M4 3h16v18H4z M10 3v3h4v3h-4v3h4v3h-4v3h4',
};
</script>

<template>
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" :stroke-width="name === 'more' ? 3.5 : 1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path :d="paths[name] || paths.file" />
    </svg>
</template>
