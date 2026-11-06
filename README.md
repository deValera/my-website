# KelasKita — LMS Wali Kelas

Aplikasi web untuk wali kelas mengelola data siswa, presensi harian, nilai, dan informasi kelas. Dibuat dengan PHP, MySQL, CSS, dan JavaScript tanpa framework tambahan.

## Fitur

- Ringkasan jumlah siswa, presensi hari ini, dan rata-rata nilai.
- Tambah dan hapus data siswa; data presensi dan nilai siswa ikut terhapus saat siswa dihapus.
- Catat atau perbarui presensi harian dengan status hadir, terlambat, izin, sakit, atau alpa.
- Input nilai per siswa, mata pelajaran, dan semester. Input berulang untuk kombinasi yang sama memperbarui nilai.
- Terbitkan dan hapus informasi kelas.
- Login satu akun wali kelas, penyiapan kata sandi sekali pada penggunaan pertama, dan logout.
- Kata sandi disimpan sebagai hash; formulir memakai token CSRF dan query database berparameter.

## Menjalankan dengan XAMPP

1. Salin folder proyek ke `C:\xampp\htdocs\cobaweb` (atau folder web server yang digunakan).
2. Jalankan Apache dan MySQL dari XAMPP.
3. Buka phpMyAdmin, impor file `database.sql`. File tersebut membuat database `lms_walikelas`, tabel, dan profil wali kelas Agus Triyono — XII TKJ, SMKN 2 SELAYAR.
4. Buka `http://localhost/cobaweb/`. Pada penggunaan pertama, buat kata sandi dengan panjang minimal 12 karakter. Nama pengguna awal `agus.triyono` dapat diubah pada formulir penyiapan.
5. Setelah penyiapan, masuk menggunakan nama pengguna dan kata sandi tersebut. Halaman penyiapan awal hanya tersedia sebelum kata sandi akun disetel.
6. Untuk kredensial MySQL selain bawaan XAMPP (`root` tanpa kata sandi), atur environment variable `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, dan `DB_PASS`.

Pastikan ekstensi PHP `pdo_mysql` aktif. Jika aplikasi atau databasenya sudah dipasang sebelumnya, impor `database.sql` lagi untuk membuat tabel profil wali kelas tanpa menghapus tabel atau data yang sudah ada. Data siswa dimulai kosong agar data kelas yang sebenarnya bisa dimasukkan.
