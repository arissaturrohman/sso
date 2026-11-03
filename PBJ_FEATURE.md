# Fitur Akses Berbasis PBJ di SSO Server

## Deskripsi
Fitur ini memungkinkan pembatasan akses ke aplikasi klien berdasarkan status PBJ (Penataan Bangunan dan Jalan) user.

## Fitur yang Diimplementasikan

### 1. Struktur Database
- Menambahkan kolom `is_pbj` di tabel `users` (boolean, default: false)
- Kolom ini menentukan apakah user adalah staf PBJ atau user biasa

### 2. User Model Update
- Menambahkan `is_pbj` ke dalam `$fillable` array
- Menambahkan `is_pbj` ke dalam casts method untuk otomatis boolean conversion

### 3. Middleware PBJ
- Membuat `EnsurePbjAccess` middleware
- Middleware ini memeriksa apakah user memiliki akses PBJ
- Jika bukan PBJ, menampilkan halaman error 403

### 4. Seeder Data
- `UserPbjSeeder` untuk mengupdate existing users dengan email pattern PBJ
- Menambahkan sample PBJ users untuk testing
- Mengupdate admin user sebagai PBJ

### 5. UI Updates
- Halaman sessions sekarang menampilkan informasi status PBJ user
- User PBJ melihat badge hijau "Staf PBJ"
- User biasa melihat badge peringatan "User OPD"

### 6. Route Protection
- Route `/sessions` sekarah diproteksi dengan middleware `pbj`
- User non-PBJ akan diarahkan ke halaman error akses ditolak

## Cara Kerja

### Untuk User PBJ (`is_pbj = true`)
- Bisa melihat semua aplikasi klien yang tersedia
- Bisa mengakses halaman sessions management
- Melihat badge "Staf PBJ" di dashboard

### Untuk User Biasa (`is_pbj = false`)
- Hanya bisa melihat aplikasi klien untuk OPD-nya (belum diimplementasikan)
- Bisa mengakses halaman sessions management
- Melihat badge "User OPD" di dashboard
- Jika mencoba akses halaman PBJ-only, akan ditampilkan error

## Konfigurasi

### Environment Variables
```env
# Tidak ada konfigurasi khusus yang dibutuhkan
```

### Database Configuration
- Kolom `is_pbj` otomatis ditambahkan melalui migration
- Seeder mengupdate data existing users

## Testing

### 1. Testing User PBJ
- Login dengan user PBJ (ahmad@pbj.go.id, siti@pbj.go.id, budi@pbj.go.id)
- Pastikan bisa mengakses halaman `/sessions`
- Pastikan melihat badge "Staf PBJ"

### 2. Testing User Biasa
- Login dengan user non-PBJ
- Pastikan masih bisa mengakses halaman dasar
- Pastikan melihat badge "User OPD"

### 3. Testing Akses Ditolak
- Coba akses `/sessions` dengan user non-PBJ
- Pastikan ditampilkan halaman error akses ditolak

## Data Sample

### PBJ Users
- ahmad@pbj.go.id (NIP: 198501012023041001)
- siti@pbj.go.id (NIP: 199002022023041002)
- budi@pbj.go.id (NIP: 199103032023041003)

### Password Semua Sample Users
- password123

## Implementasi Mendatang

### 1. Pembatasan Aplikasi Klien (IMPLEMENTED)
- User non-PBJ hanya bisa melihat aplikasi klien untuk OPD-nya
- Menggunakan tabel `client_access` untuk menyimpan akses user ke aplikasi
- PBJ users bisa melihat semua aplikasi klien
- User biasa hanya melihat aplikasi yang diizinkan

### 2. Management Command
- `client-access:manage` command untuk mengelola akses aplikasi
- Fitur: grant, revoke, list, dan reset akses
- Dapat digunakan untuk bulk update akses

### 3. API untuk Client Apps (FUTURE)
- API endpoint untuk memeriksa status PBJ user
- Endpoint untuk mendapatkan list aplikasi yang diizinkan

### 4. Admin Panel (FUTURE)
- Fitur untuk admin mengubah status PBJ user
- Bulk update untuk banyak user sekaligus

## File yang Diubah

### Baru
- `database/migrations/2026_11_03_000000_add_is_pbj_to_users_table.php`
- `app/Http/Middleware/EnsurePbjAccess.php`
- `database/seeders/UserPbjSeeder.php`
- `resources/views/errors/pbj-access-denied.blade.php`

### Diubah
- `app/Models/User.php`
- `app/Http/Controllers/SessionsController.php`
- `resources/views/sessions/index.blade.php`
- `routes/web.php`
- `bootstrap/app.php`

## Notes
- Fitur ini hanya mengubah tampilan dan akses ke halaman sessions
- Logika pembatasan aplikasi klien perlu diimplementasikan di tahap berikutnya
- Tidak ada perubahan pada aplikasi klien manapun