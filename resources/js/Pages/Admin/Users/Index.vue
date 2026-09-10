<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Modal from '@/Components/Modal.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

const props = defineProps({
    users: Object,
    filters: Object,
});

const page = usePage();
const search = ref(props.filters.search || '');
const modal = ref(null);
const selectedUser = ref(null);
let searchTimer;

const form = useForm({
    name: '',
    email: '',
    role: 'user',
    password: '',
    password_confirmation: '',
});

watch(search, (value) => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => router.get(route('admin.users.index'), { search: value }, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    }), 300);
});

const openCreate = () => {
    selectedUser.value = null;
    form.reset();
    form.role = 'user';
    form.clearErrors();
    modal.value = 'form';
};

const openEdit = (user) => {
    selectedUser.value = user;
    form.name = user.name;
    form.email = user.email;
    form.role = user.role;
    form.password = '';
    form.password_confirmation = '';
    form.clearErrors();
    modal.value = 'form';
};

const closeModal = () => {
    modal.value = null;
    selectedUser.value = null;
    form.reset();
    form.clearErrors();
};

const submit = () => {
    const options = { preserveScroll: true, onSuccess: closeModal };
    if (selectedUser.value) {
        form.patch(route('admin.users.update', selectedUser.value.id), options);
        return;
    }
    form.post(route('admin.users.store'), options);
};

const confirmDelete = (user) => {
    selectedUser.value = user;
    modal.value = 'delete';
};

const deleteUser = () => form.delete(route('admin.users.destroy', selectedUser.value.id), {
    preserveScroll: true,
    onSuccess: closeModal,
});
</script>

<template>
    <Head title="Manajemen Pengguna" />

    <AuthenticatedLayout>
        <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
            <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 class="text-2xl font-semibold text-slate-900">Manajemen Pengguna</h1>
                    <p class="mt-1 text-sm text-slate-500">Kelola akun dan hak akses pustaka digital.</p>
                </div>
                <button class="rounded-full bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-blue-700" @click="openCreate">
                    + Tambah pengguna
                </button>
            </div>

            <div v-if="page.props.flash.success" class="mb-4 rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-700">
                {{ page.props.flash.success }}
            </div>
            <div v-if="form.errors.user" class="mb-4 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ form.errors.user }}
            </div>

            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b border-slate-200 p-4">
                    <input v-model="search" type="search" placeholder="Cari nama atau email..." class="w-full rounded-full border-slate-300 px-4 py-2 text-sm focus:border-blue-500 focus:ring-blue-500 sm:max-w-md" />
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200">
                        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-5 py-3">Pengguna</th>
                                <th class="px-5 py-3">Role</th>
                                <th class="px-5 py-3">Bergabung</th>
                                <th class="px-5 py-3 text-right">Aksi</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="user in users.data" :key="user.id" class="hover:bg-slate-50">
                                <td class="px-5 py-4">
                                    <div class="font-medium text-slate-900">{{ user.name }}</div>
                                    <div class="text-sm text-slate-500">{{ user.email }}</div>
                                </td>
                                <td class="px-5 py-4">
                                    <span :class="user.role === 'admin' ? 'bg-blue-50 text-blue-700' : 'bg-slate-100 text-slate-600'" class="rounded-full px-3 py-1 text-xs font-semibold capitalize">
                                        {{ user.role }}
                                    </span>
                                </td>
                                <td class="px-5 py-4 text-sm text-slate-500">{{ new Date(user.created_at).toLocaleDateString('id-ID') }}</td>
                                <td class="px-5 py-4 text-right text-sm">
                                    <button class="font-medium text-blue-600 hover:text-blue-800" @click="openEdit(user)">Edit</button>
                                    <button v-if="user.id !== page.props.auth.user.id" class="ml-4 font-medium text-red-600 hover:text-red-800" @click="confirmDelete(user)">Hapus</button>
                                </td>
                            </tr>
                            <tr v-if="!users.data.length">
                                <td colspan="4" class="px-5 py-12 text-center text-sm text-slate-500">Pengguna tidak ditemukan.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="users.links.length > 3" class="flex flex-wrap gap-2 border-t border-slate-200 p-4">
                    <Link v-for="link in users.links" :key="link.label" :href="link.url || '#'" preserve-scroll :class="[link.active ? 'bg-blue-600 text-white' : 'bg-white text-slate-600', !link.url ? 'pointer-events-none opacity-40' : 'hover:bg-slate-100']" class="rounded-lg border border-slate-200 px-3 py-1.5 text-sm" v-html="link.label" />
                </div>
            </div>
        </div>

        <Modal :show="modal === 'form'" max-width="lg" @close="closeModal">
            <form class="p-6" @submit.prevent="submit">
                <h2 class="text-lg font-semibold text-slate-900">{{ selectedUser ? 'Edit pengguna' : 'Tambah pengguna' }}</h2>
                <div class="mt-5 space-y-4">
                    <div>
                        <label class="text-sm font-medium text-slate-700">Nama</label>
                        <input v-model="form.name" required class="mt-1 w-full rounded-xl border-slate-300 focus:border-blue-500 focus:ring-blue-500" />
                        <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">{{ form.errors.name }}</p>
                    </div>
                    <div>
                        <label class="text-sm font-medium text-slate-700">Email</label>
                        <input v-model="form.email" type="email" required class="mt-1 w-full rounded-xl border-slate-300 focus:border-blue-500 focus:ring-blue-500" />
                        <p v-if="form.errors.email" class="mt-1 text-sm text-red-600">{{ form.errors.email }}</p>
                    </div>
                    <div>
                        <label class="text-sm font-medium text-slate-700">Role</label>
                        <select v-model="form.role" class="mt-1 w-full rounded-xl border-slate-300 focus:border-blue-500 focus:ring-blue-500">
                            <option value="user">User</option>
                            <option value="admin">Admin</option>
                        </select>
                        <p v-if="form.errors.role" class="mt-1 text-sm text-red-600">{{ form.errors.role }}</p>
                    </div>
                    <div>
                        <label class="text-sm font-medium text-slate-700">Password {{ selectedUser ? '(kosongkan jika tidak diubah)' : '' }}</label>
                        <input v-model="form.password" type="password" :required="!selectedUser" class="mt-1 w-full rounded-xl border-slate-300 focus:border-blue-500 focus:ring-blue-500" />
                        <p v-if="form.errors.password" class="mt-1 text-sm text-red-600">{{ form.errors.password }}</p>
                    </div>
                    <div>
                        <label class="text-sm font-medium text-slate-700">Konfirmasi password</label>
                        <input v-model="form.password_confirmation" type="password" :required="!selectedUser" class="mt-1 w-full rounded-xl border-slate-300 focus:border-blue-500 focus:ring-blue-500" />
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" class="rounded-full px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100" @click="closeModal">Batal</button>
                    <button :disabled="form.processing" class="rounded-full bg-blue-600 px-5 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50">Simpan</button>
                </div>
            </form>
        </Modal>

        <Modal :show="modal === 'delete'" max-width="sm" @close="closeModal">
            <div class="p-6">
                <h2 class="text-lg font-semibold text-slate-900">Hapus pengguna?</h2>
                <p class="mt-2 text-sm text-slate-600">Akun {{ selectedUser?.name }} akan dihapus permanen.</p>
                <div class="mt-6 flex justify-end gap-3">
                    <button class="rounded-full px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100" @click="closeModal">Batal</button>
                    <button :disabled="form.processing" class="rounded-full bg-red-600 px-5 py-2 text-sm font-semibold text-white hover:bg-red-700 disabled:opacity-50" @click="deleteUser">Hapus</button>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
