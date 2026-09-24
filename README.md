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

## Aplikasi desktop Windows

Source aplikasi tersedia di `desktop/` (Electron). Aplikasi harus login dengan akun
Pustaka Digital sebelum folder dapat disinkronkan. File desktop tampil pada folder
**Desktop Sync** di web dan hanya dapat diakses pemiliknya; tautan berbagi yang dibuat
pemilik tetap dapat digunakan penerima sesuai masa berlakunya.

```powershell
php artisan migrate
cd desktop
npm.cmd ci
npm.cmd test
npm.cmd start
npm.cmd run dist
```

Installer: `desktop/dist/Pustaka-Desktop-Setup-1.0.0.exe`. Hasil build tidak disimpan
di Git. Jalankan installer lalu isi alamat server, email, dan password. Pilih folder
khusus dan klik **Mulai sinkronisasi**. Server Laravel harus tetap berjalan.
`http://127.0.0.1:8000` hanya sesuai jika server dan desktop ada di komputer yang sama;
komputer lain memerlukan server HTTPS yang dapat dijangkau.

- Sinkronisasi file baru/perubahan dua arah dalam pasangan folder desktop yang sama,
  diperiksa setiap 15 detik. File upload web biasa belum otomatis masuk pasangan ini.
- Ukuran maksimum 100 MB per file; server PHP/proxy harus mengizinkan ukuran tersebut.
- Checksum SHA-256 mencegah upload berulang; API menggunakan version check untuk
  menolak penimpaan saat versi server berubah. Versi bentrok disimpan sebagai file lain.
- Mode salinan aman: penghapusan tidak diteruskan. File yang hilang di satu sisi
  disalin kembali dari sisi lainnya pada sinkronisasi berikutnya.
- Folder bertingkat dipertahankan melalui path relatif. Folder kosong, rename dari web,
  virtual drive / Files On-Demand, dan sinkronisasi pasangan yang sama lintas komputer
  belum tersedia. Web menampilkan file dalam kategori Desktop Sync secara datar.
- Link/junction, `.git`, `node_modules`, serta file sementara tertentu dilewati.
- Tray, jeda/lanjut, aktivitas transfer, pilihan startup Windows, dan percobaan ulang
  setelah offline tersedia. Password tidak disimpan; token 30 hari dienkripsi oleh
  penyimpanan kredensial OS melalui Electron safeStorage. Logout mencabut token perangkat.
- Logo executable, taskbar, tray, dan installer berasal dari SVG logo aplikasi web.
  Progress total berdasarkan jumlah file yang harus disinkronkan, termasuk progress
  transfer file aktif; 100% memerlukan respons server dan checksum yang cocok.
- Folder pilihan di Windows Explorer menggunakan ikon Pustaka dengan status: centang
  hijau (tersinkron), biru (proses), kuning (jeda), atau merah (error/file dilewati).
  Ini merupakan ikon folder pilihan melalui `desktop.ini`, bukan overlay per-file
  atau kolom Status dari integrasi Windows Cloud Files. Ikon Explorer kadang memerlukan
  refresh F5. `desktop.ini` kustom yang sudah ada tidak ditimpa.
- Installer pengembangan belum ditandatangani sertifikat penerbit, sehingga Windows
  dapat menampilkan peringatan penerbit tidak dikenal.

Implementasi autentikasi mengikuti [Laravel Sanctum](https://laravel.com/docs/13.x/sanctum),
dan pemisahan proses desktop mengikuti [panduan keamanan Electron](https://www.electronjs.org/docs/latest/tutorial/security).

## Pengujian aplikasi web

```bash
php artisan test
npm run build
```

Test mencakup autentikasi, dashboard, pencarian, folder, upload multi-file, object storage, preview, download, bintang, validasi, kompatibilitas route lama, dan penghapusan file.

## Pencarian isi dokumen dan OCR

Pengguna yang login dapat mencari kata dalam PDF, gambar scan (PNG/JPG/TIFF/BMP/WebP), DOCX, XLSX, PPTX, serta TXT/CSV/MD/JSON/XML. OCR Indonesia + Inggris berjalan lokal; dokumen tidak dikirim ke layanan eksternal. File desktop tetap hanya terlihat oleh pemiliknya. Ini pencarian kata/full-text, belum pencarian semantik atau sinonim AI.

Siapkan Python 3.12+ dan Tesseract, lalu dari direktori proyek:

```powershell
python -m venv storage/app/search-runtime
storage/app/search-runtime/Scripts/python.exe -m pip install -r resources/document-search/requirements.txt
New-Item -ItemType Directory -Force storage/app/ocr-tessdata
curl.exe -fL https://raw.githubusercontent.com/tesseract-ocr/tessdata_fast/4.1.0/ind.traineddata -o storage/app/ocr-tessdata/ind.traineddata
curl.exe -fL https://raw.githubusercontent.com/tesseract-ocr/tessdata_fast/4.1.0/eng.traineddata -o storage/app/ocr-tessdata/eng.traineddata
php artisan migrate
php artisan search:reindex
php artisan queue:work document-indexing --queue=indexing --timeout=600 --tries=2
```

Worker harus tetap berjalan agar file baru otomatis terindeks. Di Linux gunakan `storage/app/search-runtime/bin/python` untuk instalasi pip, dan kelola worker dengan process supervisor. Lokasi runtime dapat diatur melalui `DOCUMENT_SEARCH_PYTHON`, `DOCUMENT_SEARCH_TESSERACT`, `DOCUMENT_SEARCH_TESSDATA`, dan `DOCUMENT_SEARCH_LANGUAGES` (default `ind+eng`).

`php artisan search:reindex --status` menampilkan jumlah per status; `--force` mengantrekan ulang semua file. Tanpa `--force`, hanya status pending/gagal yang diantrekan ulang. Refresh halaman untuk melihat status terbaru. PDF terenkripsi/rusak bisa gagal; video, format Office lama DOC/XLS/PPT, dan format tidak didukung tetap dapat dicari berdasarkan nama/metadata. Akurasi OCR bergantung kualitas dan orientasi scan. Batas ekstraksi: 100 halaman/gambar, 500.000 karakter, 100 MB file, dan sekitar 8 menit per dokumen; hasil terpotong ditandai sebagian.

Uji OCR lokal: `storage/app/search-runtime/Scripts/python.exe -m unittest discover -s resources/document-search -p "test_*.py"`.

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
