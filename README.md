# Digital Library

[![CI](https://github.com/dedyibrahim/digital_library/actions/workflows/ci.yml/badge.svg)](https://github.com/dedyibrahim/digital_library/actions/workflows/ci.yml)

Digital Library adalah aplikasi penyimpanan dan pengelolaan file berbasis web dengan pengalaman seperti Google Drive. File disimpan melalui object storage internal menggunakan object key UUID, sedangkan metadata dikelola di PostgreSQL.

## Fitur

- Autentikasi pengguna: registrasi, login, profil, dan logout.
- Upload satu atau banyak file melalui file picker atau drag-and-drop.
- Mendukung semua jenis file hingga 100 MB per file.
- Folder/kategori untuk mengelompokkan file.
- Pencarian berdasarkan nama file, pemilik, MIME type, dan folder.
- Tampilan grid dan daftar.
- Filter file terbaru dan berbintang.
- Preview mengambang hampir fullscreen seperti Google Drive.
- Thumbnail otomatis untuk gambar, halaman pertama PDF, dan frame video.
- Ikon berwarna berdasarkan ekstensi untuk Office, arsip, audio, video, kode, dan format lainnya.
- Preview langsung untuk PDF, gambar, video, audio, dan teks.
- Download dan penghapusan file.
- Object storage privat dengan URL UUID `/drive/files/{uuid}`.

## Teknologi

- PHP 8.3 dan Laravel 13
- Inertia.js 2 dan Vue 3
- PostgreSQL
- Tailwind CSS
- PDF.js
- Vite
- PHPUnit

## Arsitektur penyimpanan

Object storage internal menyimpan file menggunakan key yang tidak mengungkap nama atau struktur folder pengguna:

```text
objects/library/{prefix}/{uuid}.{extension}
```

Metadata seperti UUID, bucket, object key, nama asli, MIME type, ukuran, folder, status bintang, dan waktu terakhir dibuka tersimpan di PostgreSQL. Semua endpoint file dilindungi autentikasi.

```mermaid
flowchart LR
    U[Browser] --> I[Inertia + Vue]
    I --> L[Laravel API]
    L --> P[(PostgreSQL metadata)]
    L --> O[Private object storage]
    O --> K[UUID object keys]
```

## Struktur utama

```text
app/Services/ObjectStorage.php          Object storage internal
app/Http/Controllers/CatalogController.php
resources/js/Pages/Catalog/Index.vue    Antarmuka drive dan preview
resources/js/Components/FileThumbnail.vue
database/migrations/                    Skema PostgreSQL
tests/Feature/                          Test integrasi
```

## Instalasi

Persyaratan:

- PHP 8.3+
- Composer
- Node.js dan npm
- PostgreSQL

Jalankan:

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
```

Buat database PostgreSQL bernama `digital_library`, kemudian sesuaikan konfigurasi berikut pada `.env`:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=digital_library
DB_USERNAME=postgres
DB_PASSWORD=your-password
```

Siapkan database dan aset:

```bash
php artisan migrate --seed
npm run build
```

Jalankan aplikasi:

```bash
php artisan serve
```

Buka `http://127.0.0.1:8000`.

## Akun demo

```text
Email: admin@pustaka.test
Password: password
```

Ganti kredensial demo sebelum digunakan di lingkungan production.

## Pengembangan

Untuk menjalankan Laravel dan Vite secara bersamaan:

```bash
composer run dev
```

## Pengujian

```bash
php artisan test
npm run build
```

Test mencakup autentikasi, dashboard, pencarian, folder, upload multi-file, object storage, preview, download, bintang, validasi, kompatibilitas route lama, dan penghapusan file.

## Konvensi commit

Proyek menggunakan [Conventional Commits](https://www.conventionalcommits.org/):

```text
feat: menambahkan fitur baru
fix: memperbaiki bug
docs: memperbarui dokumentasi
test: menambahkan atau memperbarui test
refactor: merapikan kode tanpa mengubah perilaku
chore: perubahan tooling atau pemeliharaan
```

## Keamanan

- Jangan commit file `.env`.
- File tersimpan pada disk privat dan tidak diekspos melalui `public/storage`.
- Akses preview, download, upload, serta penghapusan membutuhkan autentikasi.
- Validasi ukuran dilakukan pada frontend dan backend.

## Lisensi

MIT
