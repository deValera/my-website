<?php

declare(strict_types=1);

ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
date_default_timezone_set('Asia/Jakarta');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirectTo(string $view, string $date): never
{
    header('Location: index.php?view=' . rawurlencode($view) . '&date=' . rawurlencode($date));
    exit;
}

function validDate(string $date): bool
{
    $parsed = DateTime::createFromFormat('!Y-m-d', $date);
    return $parsed !== false && $parsed->format('Y-m-d') === $date;
}

function formatIndonesianDate(string $date, string $format = 'd F Y'): string
{
    $formatted = date($format, strtotime($date));
    return strtr($formatted, [
        'Monday' => 'Senin',
        'Tuesday' => 'Selasa',
        'Wednesday' => 'Rabu',
        'Thursday' => 'Kamis',
        'Friday' => 'Jumat',
        'Saturday' => 'Sabtu',
        'Sunday' => 'Minggu',
        'January' => 'Januari',
        'February' => 'Februari',
        'March' => 'Maret',
        'April' => 'April',
        'May' => 'Mei',
        'June' => 'Juni',
        'July' => 'Juli',
        'August' => 'Agustus',
        'September' => 'September',
        'October' => 'Oktober',
        'November' => 'November',
        'December' => 'Desember',
    ]);
}

$viewNames = ['dashboard', 'students', 'attendance', 'grades', 'announcements'];
$view = in_array($_GET['view'] ?? 'dashboard', $viewNames, true) ? (string) ($_GET['view'] ?? 'dashboard') : 'dashboard';
$today = date('Y-m-d');
$selectedDate = (string) ($_GET['date'] ?? $today);
if (!validDate($selectedDate)) {
    $selectedDate = $today;
}

$pdo = null;
$databaseError = '';
$teacherProfile = null;
try {
    $pdo = require __DIR__ . '/config/database.php';
    $teacherProfile = $pdo->query(
        'SELECT id, username, password_hash, teacher_name, class_name, school_name
         FROM teacher_profiles WHERE id = 1'
    )->fetch() ?: null;
} catch (PDOException $exception) {
    error_log('LMS database connection failed: ' . $exception->getMessage());
    $databaseError = 'Database belum siap. Jalankan MySQL dan impor ulang database.sql untuk menyiapkan tabel aplikasi dan login wali kelas.';
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $postedView = (string) ($_POST['return_view'] ?? 'dashboard');
    $postedView = in_array($postedView, $viewNames, true) ? $postedView : 'dashboard';
    $postedDate = (string) ($_POST['return_date'] ?? $today);
    $postedDate = validDate($postedDate) ? $postedDate : $today;

    try {
        if (!hash_equals($_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Sesi formulir tidak valid. Muat ulang halaman, lalu coba lagi.');
        }
        if ($pdo === null) {
            throw new RuntimeException($databaseError);
        }

        $action = (string) ($_POST['action'] ?? '');
        if (!in_array($action, ['login', 'setup_account', 'logout'], true)
            && ($teacherProfile === null || $teacherProfile['password_hash'] === null
                || (int) ($_SESSION['teacher_id'] ?? 0) !== 1)) {
            throw new RuntimeException('Silakan masuk terlebih dahulu untuk melanjutkan.');
        }
        if ($action === 'setup_account') {
            $username = strtolower(trim((string) ($_POST['username'] ?? '')));
            $password = (string) ($_POST['password'] ?? '');
            $passwordConfirmation = (string) ($_POST['password_confirmation'] ?? '');
            if (!preg_match('/\A[a-z0-9._-]{3,50}\z/', $username)) {
                throw new RuntimeException('Nama pengguna harus 3–50 karakter dan hanya boleh berisi huruf, angka, titik, garis bawah, atau tanda hubung.');
            }
            if (mb_strlen($password, 'UTF-8') < 12 || strlen($password) > 72) {
                throw new RuntimeException('Kata sandi harus memiliki minimal 12 karakter dan maksimal 72 byte.');
            }
            if (!hash_equals($password, $passwordConfirmation)) {
                throw new RuntimeException('Konfirmasi kata sandi tidak cocok.');
            }

            $pdo->beginTransaction();
            $profile = $pdo->query('SELECT id, password_hash FROM teacher_profiles WHERE id = 1 FOR UPDATE')->fetch();
            if ($profile !== false && $profile['password_hash'] !== null) {
                throw new RuntimeException('Akun wali kelas sudah disiapkan. Silakan masuk.');
            }
            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
            if ($passwordHash === false) {
                throw new RuntimeException('Kata sandi tidak dapat diproses. Silakan coba lagi.');
            }
            if ($profile === false) {
                $statement = $pdo->prepare(
                    "INSERT INTO teacher_profiles (id, username, password_hash, teacher_name, class_name, school_name)
                     VALUES (1, ?, ?, 'Agus Triyono', 'XII TKJ', 'SMKN 2 SELAYAR')"
                );
                $statement->execute([$username, $passwordHash]);
            } else {
                $statement = $pdo->prepare('UPDATE teacher_profiles SET username = ?, password_hash = ? WHERE id = 1');
                $statement->execute([$username, $passwordHash]);
            }
            $pdo->commit();
            session_regenerate_id(true);
            $_SESSION['teacher_id'] = 1;
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Akun wali kelas berhasil disiapkan. Selamat datang, Pak Agus.'];
            redirectTo('dashboard', $today);
        } elseif ($action === 'login') {
            $username = strtolower(trim((string) ($_POST['username'] ?? '')));
            $password = (string) ($_POST['password'] ?? '');
            $statement = $pdo->prepare(
                'SELECT id, password_hash FROM teacher_profiles WHERE username = ? AND id = 1'
            );
            $statement->execute([$username]);
            $account = $statement->fetch();
            if ($account === false || $account['password_hash'] === null
                || !password_verify($password, $account['password_hash'])) {
                throw new RuntimeException('Nama pengguna atau kata sandi tidak sesuai.');
            }
            session_regenerate_id(true);
            $_SESSION['teacher_id'] = (int) $account['id'];
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Berhasil masuk. Selamat datang kembali, Pak Agus.'];
            redirectTo('dashboard', $today);
        } elseif ($action === 'logout') {
            unset($_SESSION['teacher_id']);
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            session_regenerate_id(true);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Anda sudah keluar dari aplikasi.'];
            header('Location: index.php');
            exit;
        } elseif ($action === 'add_student') {
            $nis = trim((string) ($_POST['nis'] ?? ''));
            $name = trim((string) ($_POST['name'] ?? ''));
            $gender = (string) ($_POST['gender'] ?? '');
            $phone = trim((string) ($_POST['phone'] ?? ''));
            $parentName = trim((string) ($_POST['parent_name'] ?? ''));
            if ($nis === '' || $name === '' || !in_array($gender, ['L', 'P'], true)) {
                throw new RuntimeException('NIS, nama siswa, dan jenis kelamin wajib diisi.');
            }
            $statement = $pdo->prepare(
                'INSERT INTO students (nis, name, gender, phone, parent_name) VALUES (?, ?, ?, ?, ?)'
            );
            $statement->execute([$nis, $name, $gender, $phone ?: null, $parentName ?: null]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Siswa berhasil ditambahkan.'];
            redirectTo('students', $postedDate);
        } elseif ($action === 'delete_student') {
            $studentId = filter_var($_POST['student_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$studentId || $studentId < 1) {
                throw new RuntimeException('Data siswa tidak valid.');
            }
            $statement = $pdo->prepare('DELETE FROM students WHERE id = ?');
            $statement->execute([$studentId]);
            if ($statement->rowCount() === 0) {
                throw new RuntimeException('Siswa tidak ditemukan.');
            }
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Siswa dan data terkait berhasil dihapus.'];
            redirectTo('students', $postedDate);
        } elseif ($action === 'save_attendance') {
            $attendanceDate = (string) ($_POST['attendance_date'] ?? '');
            $records = $_POST['attendance'] ?? null;
            if (!validDate($attendanceDate) || !is_array($records)) {
                throw new RuntimeException('Tanggal atau data presensi tidak valid.');
            }
            $studentIds = $pdo->query('SELECT id FROM students')->fetchAll(PDO::FETCH_COLUMN);
            $upsert = $pdo->prepare(
                'INSERT INTO attendance (student_id, attendance_date, status)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE status = VALUES(status)'
            );
            $allowedStatuses = ['Hadir', 'Terlambat', 'Izin', 'Sakit', 'Alpa'];
            $pdo->beginTransaction();
            foreach ($studentIds as $studentId) {
                $status = (string) ($records[(string) $studentId] ?? '');
                if (!in_array($status, $allowedStatuses, true)) {
                    throw new RuntimeException('Pilih status presensi untuk setiap siswa.');
                }
                $upsert->execute([$studentId, $attendanceDate, $status]);
            }
            $pdo->commit();
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Presensi berhasil disimpan.'];
            redirectTo('attendance', $attendanceDate);
        } elseif ($action === 'save_grade') {
            $studentId = filter_var($_POST['student_id'] ?? null, FILTER_VALIDATE_INT);
            $subject = trim((string) ($_POST['subject'] ?? ''));
            $semester = (string) ($_POST['semester'] ?? '');
            $scoreInput = (string) ($_POST['score'] ?? '');
            if (!$studentId || $studentId < 1 || $subject === '' || !in_array($semester, ['Ganjil', 'Genap'], true)
                || !is_numeric($scoreInput) || (float) $scoreInput < 0 || (float) $scoreInput > 100) {
                throw new RuntimeException('Lengkapi data nilai dengan benar. Nilai harus antara 0 sampai 100.');
            }
            $statement = $pdo->prepare(
                'INSERT INTO grades (student_id, subject, semester, score) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE score = VALUES(score)'
            );
            $statement->execute([$studentId, $subject, $semester, $scoreInput]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Nilai berhasil disimpan.'];
            redirectTo('grades', $postedDate);
        } elseif ($action === 'add_announcement') {
            $title = trim((string) ($_POST['title'] ?? ''));
            $body = trim((string) ($_POST['body'] ?? ''));
            if ($title === '' || $body === '') {
                throw new RuntimeException('Judul dan isi informasi kelas wajib diisi.');
            }
            $statement = $pdo->prepare('INSERT INTO announcements (title, body) VALUES (?, ?)');
            $statement->execute([$title, $body]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Informasi kelas berhasil diterbitkan.'];
            redirectTo('announcements', $postedDate);
        } elseif ($action === 'delete_announcement') {
            $announcementId = filter_var($_POST['announcement_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$announcementId || $announcementId < 1) {
                throw new RuntimeException('Informasi kelas tidak valid.');
            }
            $statement = $pdo->prepare('DELETE FROM announcements WHERE id = ?');
            $statement->execute([$announcementId]);
            if ($statement->rowCount() === 0) {
                throw new RuntimeException('Informasi kelas tidak ditemukan.');
            }
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Informasi kelas berhasil dihapus.'];
            redirectTo('announcements', $postedDate);
        } else {
            throw new RuntimeException('Aksi yang diminta tidak dikenal.');
        }
    } catch (Throwable $exception) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('LMS request failed: ' . $exception->getMessage());
        $action = (string) ($_POST['action'] ?? '');
        $errorMessage = $exception instanceof PDOException
            ? (in_array($action, ['login', 'setup_account'], true)
                ? 'Akun belum dapat disiapkan. Nama pengguna mungkin sudah digunakan; pastikan database sudah diperbarui.'
                : 'Data tidak dapat disimpan. Pastikan NIS unik dan semua data sudah benar.')
            : $exception->getMessage();
        $_SESSION['flash'] = ['type' => 'error', 'message' => $errorMessage];
        redirectTo($postedView, $postedDate);
    }
}

$authenticated = $teacherProfile !== null
    && $teacherProfile['password_hash'] !== null
    && (int) ($_SESSION['teacher_id'] ?? 0) === 1;
if (!$authenticated) {
    unset($_SESSION['teacher_id']);
}
$teacherName = (string) ($teacherProfile['teacher_name'] ?? 'Agus Triyono');
$teacherClass = (string) ($teacherProfile['class_name'] ?? 'XII TKJ');
$schoolName = (string) ($teacherProfile['school_name'] ?? 'SMKN 2 SELAYAR');
$teacherInitials = '';
foreach (array_slice(preg_split('/\s+/', trim($teacherName)) ?: [], 0, 2) as $namePart) {
    $teacherInitials .= mb_strtoupper(mb_substr($namePart, 0, 1));
}
$teacherFirstName = explode(' ', trim($teacherName))[0] ?: 'Agus';

if (!$authenticated):
    $isFirstSetup = $teacherProfile === null || $teacherProfile['password_hash'] === null;
    $authTitle = $isFirstSetup ? 'Siapkan akun wali kelas' : 'Selamat datang kembali';
    $authDescription = $isFirstSetup
        ? 'Buat kata sandi pribadi untuk mulai mengelola kelas.'
        : 'Masuk untuk melanjutkan pengelolaan kelas.';
    ?>
    <!doctype html>
    <html lang="id">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <title><?= e($isFirstSetup ? 'Penyiapan akun' : 'Masuk') ?> · KelasKita</title>
        <link rel="stylesheet" href="assets/css/style.css">
    </head>
    <body class="auth-page">
    <main class="auth-layout">
        <section class="auth-story">
            <a class="brand auth-brand" href="index.php" aria-label="KelasKita">
                <span class="brand-mark">K</span>
                <span class="brand-copy"><strong>kelas<span>kita</span></strong><small>RUANG WALI KELAS</small></span>
            </a>
            <div class="auth-story-content">
                <span class="auth-kicker"><span></span> RUANG WALI KELAS DIGITAL</span>
                <h1>Belajar tumbuh,<br>bersama <span>satu kelas.</span></h1>
                <p>Kelola kehadiran, perkembangan belajar, dan informasi kelas dalam satu ruang yang sederhana.</p>
                <div class="auth-school-card">
                    <span class="auth-school-icon">✳</span>
                    <div><strong><?= e($schoolName) ?></strong><small>Wali kelas <?= e($teacherClass) ?> · <?= e($teacherName) ?></small></div>
                </div>
            </div>
            <div class="auth-story-footer">© <?= e(date('Y')) ?> KelasKita <span>·</span> Ruang tumbuh setiap siswa</div>
            <span class="auth-decoration auth-decoration-one"></span><span class="auth-decoration auth-decoration-two"></span>
        </section>
        <section class="auth-form-side">
            <div class="auth-form-wrap">
                <span class="auth-mobile-brand"><span class="brand-mark">K</span><strong>kelas<span>kita</span></strong></span>
                <div class="auth-card">
                    <div class="auth-card-icon"><?= $isFirstSetup ? '✳' : '↗' ?></div>
                    <p class="eyebrow"><?= $isFirstSetup ? 'PENYIAPAN PERTAMA' : 'PORTAL WALI KELAS' ?></p>
                    <h2><?= e($authTitle) ?></h2>
                    <p class="auth-form-description"><?= e($authDescription) ?></p>
                    <?php if ($databaseError !== ''): ?>
                        <div class="notice notice-error"><?= e($databaseError) ?> Baca petunjuk di <code>README.md</code>.</div>
                    <?php endif; ?>
                    <?php if (is_array($flash)): ?>
                        <div class="notice notice-<?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></div>
                    <?php endif; ?>
                    <?php if ($databaseError === '' && $pdo !== null && $isFirstSetup): ?>
                        <div class="setup-notice"><span>i</span><p>Akun khusus <?= e($teacherName) ?> · Wali kelas <?= e($teacherClass) ?>. Buat kata sandi minimal 12 karakter. Penyiapan ini hanya tersedia sekali.</p></div>
                    <?php endif; ?>
                    <?php if ($databaseError === '' && $pdo !== null): ?>
                        <form method="post" class="auth-form">
                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="action" value="<?= $isFirstSetup ? 'setup_account' : 'login' ?>">
                            <label for="username">Nama pengguna</label>
                            <div class="auth-input-wrap"><span aria-hidden="true">@</span><input id="username" type="text" name="username" value="<?= e($isFirstSetup ? ($teacherProfile['username'] ?? 'agus.triyono') : '') ?>" minlength="3" maxlength="50" pattern="[\w.\-]{3,50}" autocomplete="username" placeholder="Nama pengguna" required autofocus></div>
                            <label for="password"><?= $isFirstSetup ? 'Buat kata sandi' : 'Kata sandi' ?></label>
                            <div class="auth-input-wrap"><span aria-hidden="true">⌑</span><input id="password" type="password" name="password" minlength="<?= $isFirstSetup ? '12' : '1' ?>" maxlength="72" autocomplete="<?= $isFirstSetup ? 'new-password' : 'current-password' ?>" placeholder="<?= $isFirstSetup ? 'Minimal 12 karakter' : 'Masukkan kata sandi' ?>" required><button type="button" class="password-toggle" data-password-toggle="password" aria-label="Tampilkan kata sandi">Lihat</button></div>
                            <?php if ($isFirstSetup): ?>
                                <label for="password-confirmation">Ulangi kata sandi</label>
                                <div class="auth-input-wrap"><span aria-hidden="true">⌑</span><input id="password-confirmation" type="password" name="password_confirmation" minlength="12" maxlength="72" autocomplete="new-password" placeholder="Masukkan kata sandi sekali lagi" required><button type="button" class="password-toggle" data-password-toggle="password-confirmation" aria-label="Tampilkan konfirmasi kata sandi">Lihat</button></div>
                            <?php endif; ?>
                            <button class="button button-primary auth-submit" type="submit"><?= $isFirstSetup ? 'Buat akun dan masuk' : 'Masuk ke dashboard' ?><span>→</span></button>
                        </form>
                    <?php endif; ?>
                    <div class="auth-security"><span>◈</span> Akses pribadi, data kelas terjaga</div>
                </div>
                <p class="auth-help">Kelas <?= e($teacherClass) ?> <span>·</span> <?= e($schoolName) ?></p>
            </div>
        </section>
    </main>
    <script src="assets/js/app.js"></script>
    </body>
    </html>
    <?php
    exit;
endif;

$students = [];
$attendanceByStudent = [];
$grades = [];
$announcements = [];
$summary = ['students' => 0, 'present' => 0, 'late' => 0, 'permission' => 0, 'sick' => 0, 'absent' => 0, 'average' => null];

if ($pdo !== null) {
    $students = $pdo->query('SELECT id, nis, name, gender, phone, parent_name FROM students ORDER BY name')->fetchAll();
    $summary['students'] = count($students);

    $statement = $pdo->prepare('SELECT student_id, status FROM attendance WHERE attendance_date = ?');
    $statement->execute([$today]);
    $todayAttendance = $statement->fetchAll();
    $summary['present'] = count(array_filter($todayAttendance, static fn(array $row): bool => $row['status'] === 'Hadir'));
    $summary['late'] = count(array_filter($todayAttendance, static fn(array $row): bool => $row['status'] === 'Terlambat'));
    $summary['permission'] = count(array_filter($todayAttendance, static fn(array $row): bool => $row['status'] === 'Izin'));
    $summary['sick'] = count(array_filter($todayAttendance, static fn(array $row): bool => $row['status'] === 'Sakit'));
    $summary['absent'] = count(array_filter($todayAttendance, static fn(array $row): bool => $row['status'] === 'Alpa'));

    $statement = $pdo->prepare('SELECT student_id, status FROM attendance WHERE attendance_date = ?');
    $statement->execute([$selectedDate]);
    foreach ($statement->fetchAll() as $row) {
        $attendanceByStudent[(int) $row['student_id']] = $row['status'];
    }

    $grades = $pdo->query(
        'SELECT g.id, g.subject, g.semester, g.score, s.name AS student_name, s.nis
         FROM grades g JOIN students s ON s.id = g.student_id
         ORDER BY g.updated_at DESC, s.name'
    )->fetchAll();
    $summary['average'] = $pdo->query('SELECT AVG(score) FROM grades')->fetchColumn();
    $announcements = $pdo->query('SELECT id, title, body, created_at FROM announcements ORDER BY created_at DESC, id DESC')->fetchAll();
}

$navItems = [
    'dashboard' => ['icon' => '⌂', 'label' => 'Dashboard'],
    'students' => ['icon' => '♧', 'label' => 'Data siswa'],
    'attendance' => ['icon' => '✓', 'label' => 'Presensi'],
    'grades' => ['icon' => '▤', 'label' => 'Nilai siswa'],
    'announcements' => ['icon' => '◉', 'label' => 'Info kelas'],
];
$pageTitles = [
    'dashboard' => 'Dashboard',
    'students' => 'Data siswa',
    'attendance' => 'Presensi kelas',
    'grades' => 'Nilai siswa',
    'announcements' => 'Informasi kelas',
];
$greeting = (int) date('G') < 11 ? 'Selamat pagi' : ((int) date('G') < 15 ? 'Selamat siang' : ((int) date('G') < 19 ? 'Selamat sore' : 'Selamat malam'));
$dateLabel = formatIndonesianDate($today, 'l, d F Y');
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitles[$view]) ?> · KelasKita</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="app-shell">
    <aside class="sidebar" id="sidebar">
        <a class="brand" href="index.php" aria-label="KelasKita beranda">
            <span class="brand-mark">K</span>
            <span class="brand-copy"><strong>kelas<span>kita</span></strong><small>RUANG WALI KELAS</small></span>
        </a>
        <div class="class-switcher"><span class="class-avatar">XII</span><span><strong>Kelas <?= e($teacherClass) ?></strong><small><?= e($schoolName) ?></small></span><span class="switch-chevron">⌄</span></div>
        <span class="nav-caption">MENU UTAMA</span>
        <nav class="main-nav" aria-label="Menu utama">
            <?php foreach ($navItems as $key => $item): ?>
                <a class="nav-link <?= $view === $key ? 'active' : '' ?>" href="index.php?view=<?= e($key) ?>">
                    <span class="nav-icon" aria-hidden="true"><?= e($item['icon']) ?></span><span><?= e($item['label']) ?></span>
                    <?php if ($key === 'students'): ?><span class="nav-count"><?= e($summary['students']) ?></span><?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-bottom">
            <div class="help-card"><span class="help-icon">✳</span><strong>Butuh bantuan?</strong><p>Kelola kelas jadi lebih mudah bersama KelasKita.</p><a href="index.php?view=announcements">Lihat informasi <span>→</span></a></div>
            <div class="teacher-profile"><div class="teacher-avatar"><?= e($teacherInitials) ?></div><div><strong><?= e($teacherName) ?></strong><small>Wali Kelas <?= e($teacherClass) ?></small></div><form method="post" class="logout-form"><input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="logout"><button type="submit" class="logout-button" aria-label="Keluar dari aplikasi" title="Keluar">↪</button></form></div>
        </div>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <button type="button" class="menu-toggle" id="menuToggle" aria-label="Buka menu">☰</button>
            <div class="breadcrumb"><span>Ruang wali kelas</span><span class="crumb-separator">/</span><strong><?= e($pageTitles[$view]) ?></strong></div>
            <div class="topbar-actions"><span class="topbar-date"><?= e($dateLabel) ?></span><span class="topbar-divider"></span><button class="notification-button" type="button" aria-label="Informasi kelas" onclick="window.location.href='index.php?view=announcements'">♧<i></i></button><div class="topbar-avatar"><?= e($teacherInitials) ?></div></div>
        </header>

        <div class="page-content">
            <?php if ($databaseError !== ''): ?>
                <div class="notice notice-error"><strong>Database belum siap.</strong> <?= e($databaseError) ?> <span>Petunjuk lengkap tersedia di <code>README.md</code>.</span></div>
            <?php endif; ?>
            <?php if (is_array($flash)): ?>
                <div class="notice notice-<?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></div>
            <?php endif; ?>

            <?php if ($view === 'dashboard'): ?>
                <section class="welcome-row">
                    <div><p class="eyebrow"><?= e($dateLabel) ?></p><h1><?= e($greeting) ?>, Pak <?= e($teacherFirstName) ?> <span class="wave">✦</span></h1><p class="welcome-subtitle"><?= e($teacherClass) ?> · <?= e($schoolName) ?></p></div>
                    <a class="button button-primary" href="index.php?view=attendance">＋ <span>Catat presensi</span></a>
                </section>
                <section class="stats-grid" aria-label="Ringkasan kelas">
                    <article class="stat-card stat-green"><div class="stat-top"><span>Total siswa</span><span class="stat-icon">♧</span></div><strong><?= e($summary['students']) ?><small> siswa</small></strong><div class="stat-foot"><span class="stat-dot"></span> Terdaftar di kelas <?= e($teacherClass) ?></div></article>
                    <article class="stat-card"><div class="stat-top"><span>Hadir hari ini</span><span class="stat-icon icon-mint">✓</span></div><strong><?= e($summary['present']) ?><small> siswa</small></strong><div class="stat-foot"><span class="stat-dot dot-green"></span> <?= e($summary['late']) ?> terlambat</div></article>
                    <article class="stat-card"><div class="stat-top"><span>Izin &amp; sakit</span><span class="stat-icon icon-peach">◷</span></div><strong><?= e($summary['permission'] + $summary['sick']) ?><small> siswa</small></strong><div class="stat-foot"><span class="stat-dot dot-orange"></span> <?= e($summary['permission']) ?> izin · <?= e($summary['sick']) ?> sakit</div></article>
                    <article class="stat-card"><div class="stat-top"><span>Rata-rata nilai</span><span class="stat-icon icon-lilac">↗</span></div><strong><?= $summary['average'] !== null ? e(number_format((float) $summary['average'], 1, ',', '.')) : '—' ?><small><?= $summary['average'] !== null ? ' / 100' : '' ?></small></strong><div class="stat-foot"><span class="stat-dot dot-purple"></span> Dari seluruh nilai tersimpan</div></article>
                </section>
                <section class="dashboard-grid">
                    <article class="panel attendance-panel">
                        <div class="panel-heading"><div><h2>Ringkasan presensi</h2><p>Kehadiran siswa hari ini</p></div><a class="text-link" href="index.php?view=attendance">Lihat presensi <span>→</span></a></div>
                        <?php $attendanceTotal = count($todayAttendance ?? []); $attendancePercent = $summary['students'] > 0 ? (int) round(($summary['present'] + $summary['late']) / $summary['students'] * 100) : 0; ?>
                        <div class="attendance-summary"><div class="donut" style="--progress:<?= e($attendancePercent) ?>%"><div><strong><?= e($attendancePercent) ?>%</strong><span>kehadiran</span></div></div><div class="attendance-legend"><div><i class="legend-dot green"></i><span>Hadir</span><strong><?= e($summary['present']) ?></strong></div><div><i class="legend-dot yellow"></i><span>Terlambat</span><strong><?= e($summary['late']) ?></strong></div><div><i class="legend-dot blue"></i><span>Izin / sakit</span><strong><?= e($summary['permission'] + $summary['sick']) ?></strong></div><div><i class="legend-dot red"></i><span>Alpa</span><strong><?= e($summary['absent']) ?></strong></div></div></div>
                        <?php if ($attendanceTotal === 0 && $summary['students'] > 0): ?><p class="empty-hint">Belum ada presensi hari ini. <a href="index.php?view=attendance">Mulai catat →</a></p><?php endif; ?>
                    </article>
                    <article class="panel announcements-panel">
                        <div class="panel-heading"><div><h2>Informasi kelas</h2><p>Pengumuman terbaru untuk kelas</p></div><a class="text-link" href="index.php?view=announcements">Semua <span>→</span></a></div>
                        <?php if ($announcements === []): ?>
                            <div class="empty-state compact"><span>◉</span><p>Belum ada informasi kelas.</p></div>
                        <?php else: ?>
                            <?php foreach (array_slice($announcements, 0, 3) as $announcement): ?>
                                <div class="announcement-item"><span class="announcement-mark">✳</span><div><strong><?= e($announcement['title']) ?></strong><p><?= e(mb_strimwidth($announcement['body'], 0, 95, '…')) ?></p><small><?= e(formatIndonesianDate($announcement['created_at'], 'd F Y')) ?></small></div></div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </article>
                </section>
                <section class="panel student-preview">
                    <div class="panel-heading"><div><h2>Siswa di kelas</h2><p>Kenali dan pantau perkembangan siswa</p></div><a class="text-link" href="index.php?view=students">Lihat semua siswa <span>→</span></a></div>
                    <?php if ($students === []): ?><div class="empty-state"><span>♧</span><h3>Belum ada data siswa</h3><p>Tambahkan siswa agar presensi dan nilai dapat dikelola.</p><a class="button button-primary" href="index.php?view=students">Tambah siswa</a></div>
                    <?php else: ?>
                        <div class="table-wrap"><table><thead><tr><th>NAMA SISWA</th><th>NIS</th><th>JENIS KELAMIN</th><th>NO. TELEPON</th><th>STATUS HARI INI</th></tr></thead><tbody>
                        <?php foreach (array_slice($students, 0, 5) as $student): $status = array_column($todayAttendance ?? [], 'status', 'student_id')[$student['id']] ?? 'Belum dicatat'; ?>
                            <tr><td><span class="student-cell"><span class="student-avatar avatar-<?= (int) $student['id'] % 5 ?>"><?= e(mb_strtoupper(mb_substr($student['name'], 0, 1))) ?></span><strong><?= e($student['name']) ?></strong></span></td><td><?= e($student['nis']) ?></td><td><?= $student['gender'] === 'L' ? 'Laki-laki' : 'Perempuan' ?></td><td><?= e($student['phone'] ?: '—') ?></td><td><span class="status-badge status-<?= e(strtolower(str_replace(' ', '-', $status))) ?>"><?= e($status) ?></span></td></tr>
                        <?php endforeach; ?>
                        </tbody></table></div>
                    <?php endif; ?>
                </section>

            <?php elseif ($view === 'students'): ?>
                <section class="welcome-row page-title-row"><div><p class="eyebrow">KELAS <?= e($teacherClass) ?></p><h1>Data siswa</h1><p class="welcome-subtitle">Kelola informasi dan kontak siswa di kelasmu.</p></div></section>
                <div class="content-grid students-grid">
                    <section class="panel"><div class="panel-heading"><div><h2>Daftar siswa <span class="heading-count"><?= e(count($students)) ?></span></h2><p>Informasi siswa dan orang tua</p></div></div>
                        <?php if ($students === []): ?><div class="empty-state"><span>♧</span><h3>Belum ada data siswa</h3><p>Gunakan formulir untuk menambahkan siswa.</p></div>
                        <?php else: ?><div class="table-wrap"><table><thead><tr><th>NAMA SISWA</th><th>NIS</th><th>JK</th><th>TELEPON / WALI</th><th></th></tr></thead><tbody>
                        <?php foreach ($students as $student): ?><tr><td><span class="student-cell"><span class="student-avatar avatar-<?= (int) $student['id'] % 5 ?>"><?= e(mb_strtoupper(mb_substr($student['name'], 0, 1))) ?></span><strong><?= e($student['name']) ?></strong></span></td><td><?= e($student['nis']) ?></td><td><?= e($student['gender']) ?></td><td><?= e($student['phone'] ?: '—') ?><small class="cell-subtext"><?= e($student['parent_name'] ?: 'Kontak orang tua belum diisi') ?></small></td><td><form method="post" class="inline-form" data-confirm="Hapus siswa ini? Data presensi dan nilainya juga akan terhapus."><input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>"><input type="hidden" name="return_view" value="students"><input type="hidden" name="return_date" value="<?= e($selectedDate) ?>"><input type="hidden" name="action" value="delete_student"><input type="hidden" name="student_id" value="<?= e($student['id']) ?>"><button class="icon-button danger-text" type="submit" aria-label="Hapus <?= e($student['name']) ?>">×</button></form></td></tr><?php endforeach; ?>
                        </tbody></table></div><?php endif; ?>
                    </section>
                    <section class="panel form-panel"><div class="panel-heading"><div><h2>Tambah siswa</h2><p>Isi data siswa baru</p></div><span class="form-heading-icon">＋</span></div>
                        <form method="post" class="stack-form"><input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>"><input type="hidden" name="return_view" value="students"><input type="hidden" name="return_date" value="<?= e($selectedDate) ?>"><input type="hidden" name="action" value="add_student">
                            <label>NIS <span>*</span><input type="text" name="nis" maxlength="30" placeholder="Contoh: 24007" required></label>
                            <label>Nama lengkap <span>*</span><input type="text" name="name" maxlength="120" placeholder="Nama siswa" required></label>
                            <label>Jenis kelamin <span>*</span><select name="gender" required><option value="">Pilih jenis kelamin</option><option value="L">Laki-laki</option><option value="P">Perempuan</option></select></label>
                            <label>No. telepon<input type="tel" name="phone" maxlength="30" placeholder="08xxxxxxxxxx"></label>
                            <label>Nama orang tua / wali<input type="text" name="parent_name" maxlength="120" placeholder="Nama orang tua atau wali"></label>
                            <button class="button button-primary full-button" type="submit">＋ Tambahkan siswa</button>
                        </form>
                    </section>
                </div>

            <?php elseif ($view === 'attendance'): ?>
                <section class="welcome-row page-title-row"><div><p class="eyebrow">KEHADIRAN HARIAN</p><h1>Presensi kelas</h1><p class="welcome-subtitle">Catat status kehadiran setiap siswa.</p></div></section>
                <section class="panel attendance-table-panel"><div class="panel-heading"><div><h2>Daftar presensi</h2><p><?= e(count($students)) ?> siswa · pilih tanggal dan status kehadiran</p></div><form method="get" class="date-filter"><input type="hidden" name="view" value="attendance"><label for="attendance-date">Tanggal</label><input id="attendance-date" type="date" name="date" value="<?= e($selectedDate) ?>"><button class="button button-secondary" type="submit">Tampilkan</button></form></div>
                    <?php if ($students === []): ?><div class="empty-state"><span>✓</span><h3>Belum ada siswa untuk dipresensi</h3><p>Tambahkan data siswa terlebih dahulu.</p><a class="button button-primary" href="index.php?view=students">Buka data siswa</a></div>
                    <?php else: ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>"><input type="hidden" name="return_view" value="attendance"><input type="hidden" name="return_date" value="<?= e($selectedDate) ?>"><input type="hidden" name="action" value="save_attendance"><input type="hidden" name="attendance_date" value="<?= e($selectedDate) ?>">
                        <div class="table-wrap"><table><thead><tr><th>NAMA SISWA</th><th>NIS</th><th>STATUS KEHADIRAN</th></tr></thead><tbody><?php foreach ($students as $student): ?><tr><td><span class="student-cell"><span class="student-avatar avatar-<?= (int) $student['id'] % 5 ?>"><?= e(mb_strtoupper(mb_substr($student['name'], 0, 1))) ?></span><strong><?= e($student['name']) ?></strong></span></td><td><?= e($student['nis']) ?></td><td><select class="attendance-select" name="attendance[<?= e($student['id']) ?>]" aria-label="Status presensi <?= e($student['name']) ?>" required><option value="">Pilih status</option><?php foreach (['Hadir', 'Terlambat', 'Izin', 'Sakit', 'Alpa'] as $status): ?><option value="<?= e($status) ?>" <?= ($attendanceByStudent[(int) $student['id']] ?? '') === $status ? 'selected' : '' ?>><?= e($status) ?></option><?php endforeach; ?></select></td></tr><?php endforeach; ?></tbody></table></div>
                        <div class="form-footer"><span>Presensi untuk <?= e(date('d/m/Y', strtotime($selectedDate))) ?></span><button class="button button-primary" type="submit">Simpan presensi</button></div></form>
                    <?php endif; ?>
                </section>

            <?php elseif ($view === 'grades'): ?>
                <section class="welcome-row page-title-row"><div><p class="eyebrow">HASIL BELAJAR</p><h1>Nilai siswa</h1><p class="welcome-subtitle">Catat dan pantau nilai mata pelajaran siswa.</p></div></section>
                <div class="content-grid grades-grid"><section class="panel"><div class="panel-heading"><div><h2>Daftar nilai <span class="heading-count"><?= e(count($grades)) ?></span></h2><p>Nilai terbaru berdasarkan mata pelajaran dan semester</p></div></div>
                    <?php if ($grades === []): ?><div class="empty-state"><span>▤</span><h3>Belum ada nilai tersimpan</h3><p>Tambahkan nilai siswa menggunakan formulir.</p></div>
                    <?php else: ?><div class="table-wrap"><table><thead><tr><th>NAMA SISWA</th><th>MATA PELAJARAN</th><th>SEMESTER</th><th>NILAI</th></tr></thead><tbody><?php foreach ($grades as $grade): ?><tr><td><strong><?= e($grade['student_name']) ?></strong><small class="cell-subtext">NIS <?= e($grade['nis']) ?></small></td><td><?= e($grade['subject']) ?></td><td><?= e($grade['semester']) ?></td><td><span class="grade-score <?= (float) $grade['score'] >= 75 ? 'score-good' : 'score-low' ?>"><?= e(number_format((float) $grade['score'], 1, ',', '.')) ?></span></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
                    </section>
                    <section class="panel form-panel"><div class="panel-heading"><div><h2>Input nilai</h2><p>Nilai yang sama akan diperbarui</p></div><span class="form-heading-icon">↗</span></div>
                        <?php if ($students === []): ?><div class="empty-state compact"><p>Tambahkan siswa sebelum menginput nilai.</p><a class="text-link" href="index.php?view=students">Buka data siswa →</a></div>
                        <?php else: ?><form method="post" class="stack-form"><input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>"><input type="hidden" name="return_view" value="grades"><input type="hidden" name="return_date" value="<?= e($selectedDate) ?>"><input type="hidden" name="action" value="save_grade">
                            <label>Nama siswa <span>*</span><select name="student_id" required><option value="">Pilih siswa</option><?php foreach ($students as $student): ?><option value="<?= e($student['id']) ?>"><?= e($student['name']) ?> — <?= e($student['nis']) ?></option><?php endforeach; ?></select></label>
                            <label>Mata pelajaran <span>*</span><input type="text" name="subject" maxlength="100" placeholder="Contoh: Matematika" required></label>
                            <label>Semester <span>*</span><select name="semester" required><option value="">Pilih semester</option><option value="Ganjil">Ganjil</option><option value="Genap">Genap</option></select></label>
                            <label>Nilai (0–100) <span>*</span><input type="number" name="score" min="0" max="100" step="0.01" placeholder="Contoh: 85" required></label>
                            <button class="button button-primary full-button" type="submit">Simpan nilai</button>
                        </form><?php endif; ?>
                    </section>
                </div>

            <?php else: ?>
                <section class="welcome-row page-title-row"><div><p class="eyebrow">PAPAN INFORMASI</p><h1>Informasi kelas</h1><p class="welcome-subtitle">Bagikan pengumuman dan catatan penting untuk kelas <?= e($teacherClass) ?>.</p></div></section>
                <div class="content-grid announcements-grid"><section class="panel"><div class="panel-heading"><div><h2>Informasi terbaru <span class="heading-count"><?= e(count($announcements)) ?></span></h2><p>Pengumuman yang sudah diterbitkan</p></div></div>
                    <?php if ($announcements === []): ?><div class="empty-state"><span>◉</span><h3>Belum ada informasi kelas</h3><p>Terbitkan pengumuman pertama untuk siswa.</p></div>
                    <?php else: ?><div class="announcement-list"><?php foreach ($announcements as $announcement): ?><article class="announcement-card"><div class="announcement-card-icon">✳</div><div class="announcement-card-content"><div class="announcement-card-top"><div><h3><?= e($announcement['title']) ?></h3><small><?= e(formatIndonesianDate($announcement['created_at'], 'd F Y, H:i')) ?></small></div><form method="post" class="inline-form" data-confirm="Hapus informasi kelas ini?"><input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>"><input type="hidden" name="return_view" value="announcements"><input type="hidden" name="return_date" value="<?= e($selectedDate) ?>"><input type="hidden" name="action" value="delete_announcement"><input type="hidden" name="announcement_id" value="<?= e($announcement['id']) ?>"><button class="icon-button danger-text" type="submit" aria-label="Hapus informasi">×</button></form></div><p><?= nl2br(e($announcement['body'])) ?></p></div></article><?php endforeach; ?></div><?php endif; ?>
                    </section>
                    <section class="panel form-panel"><div class="panel-heading"><div><h2>Buat informasi</h2><p>Pesan akan terlihat di dashboard kelas</p></div><span class="form-heading-icon">＋</span></div>
                        <form method="post" class="stack-form"><input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>"><input type="hidden" name="return_view" value="announcements"><input type="hidden" name="return_date" value="<?= e($selectedDate) ?>"><input type="hidden" name="action" value="add_announcement"><label>Judul informasi <span>*</span><input type="text" name="title" maxlength="150" placeholder="Contoh: Jadwal kerja bakti" required></label><label>Isi informasi <span>*</span><textarea name="body" rows="6" placeholder="Tulis informasi untuk siswa dan orang tua..." required></textarea></label><button class="button button-primary full-button" type="submit">Terbitkan informasi</button></form>
                    </section>
                </div>
            <?php endif; ?>

            <footer class="page-footer"><span>© <?= e(date('Y')) ?> KelasKita</span><span>Dibuat untuk mendampingi tumbuh kembang siswa <i>♥</i></span></footer>
        </div>
    </main>
</div>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script src="assets/js/app.js"></script>
</body>
</html>
