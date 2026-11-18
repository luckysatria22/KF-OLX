<?php
require_once 'config.php';

date_default_timezone_set('Asia/Jakarta');

if (!isset($_SESSION['user_id'])) {
    $_SESSION['flash_success'] = 'Silakan masuk terlebih dahulu untuk mengakses profil Anda.';
    header('Location: login.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$userId       = (int) $_SESSION['user_id'];
$flashSuccess = $_SESSION['flash_success'] ?? null;
$flashError   = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// Ambil data terbaru user dari PostgreSQL
$stmtUser = $pdo->prepare('SELECT id, name, email, whatsapp, password, created_at FROM users WHERE id = :id LIMIT 1');
$stmtUser->execute([':id' => $userId]);
$user = $stmtUser->fetch();

if (!$user) {
    session_destroy();
    header('Location: login.php');
    exit;
}

$profileErrors  = [];
$passwordErrors = [];

$nameInput  = $user['name'];
$emailInput = $user['email'];
// Tampilkan awalan 08... di kolom input agar familiar saat diedit
$waInput    = preg_replace('/^62/', '0', (string) ($user['whatsapp'] ?? ''));

// Proses Form Submit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $_SESSION['flash_error'] = 'Sesi formulir tidak valid. Silakan coba lagi.';
        header('Location: edit-profil.php');
        exit;
    }

    // A. PROSES UPDATE DATA PROFIL (Nama, Email, WhatsApp)
    if ($action === 'update_profile') {
        $nameInput  = trim($_POST['name'] ?? '');
        $emailInput = strtolower(trim($_POST['email'] ?? ''));
        $waInput    = trim($_POST['whatsapp'] ?? '');
        $waNormalized = normalizeWhatsApp($waInput);

        if ($nameInput === '') {
            $profileErrors[] = 'Nama lengkap wajib diisi.';
        } elseif (mb_strlen($nameInput) > 100) {
            $profileErrors[] = 'Nama lengkap maksimal 100 karakter.';
        }

        if ($emailInput === '') {
            $profileErrors[] = 'Alamat email wajib diisi.';
        } elseif (!filter_var($emailInput, FILTER_VALIDATE_EMAIL)) {
            $profileErrors[] = 'Format alamat email tidak valid.';
        } elseif (mb_strlen($emailInput) > 100) {
            $profileErrors[] = 'Alamat email maksimal 100 karakter.';
        }

        if ($waInput === '') {
            $profileErrors[] = 'Nomor WhatsApp wajib diisi.';
        } elseif (!preg_match('/^628[0-9]{7,13}$/', $waNormalized)) {
            $profileErrors[] = 'Format nomor WhatsApp tidak valid (contoh: 081234567890).';
        }

        if (empty($profileErrors)) {
            try {
                $stmtUpdate = $pdo->prepare('
                    UPDATE users
                    SET name = :name,
                        email = :email,
                        whatsapp = :whatsapp
                    WHERE id = :id
                ');
                $stmtUpdate->execute([
                    ':name'     => $nameInput,
                    ':email'    => $emailInput,
                    ':whatsapp' => $waNormalized,
                    ':id'       => $userId,
                ]);

                // Perbarui data sesi aktif agar nama di navbar langsung berubah
                $_SESSION['user_name']     = $nameInput;
                $_SESSION['user_email']    = $emailInput;
                $_SESSION['user_whatsapp'] = $waNormalized;
                $_SESSION['csrf_token']    = bin2hex(random_bytes(32));
                $_SESSION['flash_success'] = 'Profil akun Anda berhasil diperbarui!';

                header('Location: edit-profil.php');
                exit;
            } catch (PDOException $e) {
                if ($e->getCode() === '23505') {
                    if (str_contains($e->getMessage(), 'whatsapp')) {
                        $profileErrors[] = 'Nomor WhatsApp ini sudah digunakan oleh akun lain.';
                    } else {
                        $profileErrors[] = 'Alamat email ini sudah digunakan oleh akun lain.';
                    }
                } else {
                    error_log('Update Profile Error: ' . $e->getMessage());
                    $profileErrors[] = 'Terjadi kesalahan sistem saat menyimpan profil.';
                }
            }
        }
    }

    // B. PROSES GANTI KATA SANDI
    if ($action === 'update_password') {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword     = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if ($currentPassword === '') {
            $passwordErrors[] = 'Kata sandi saat ini wajib diisi.';
        } elseif (!password_verify($currentPassword, $user['password'])) {
            $passwordErrors[] = 'Kata sandi saat ini tidak sesuai.';
        }

        if ($newPassword === '') {
            $passwordErrors[] = 'Kata sandi baru wajib diisi.';
        } elseif (strlen($newPassword) < 6) {
            $passwordErrors[] = 'Kata sandi baru minimal 6 karakter.';
        } elseif (strlen($newPassword) > 72) {
            $passwordErrors[] = 'Kata sandi baru maksimal 72 karakter.';
        }

        if ($newPassword !== $confirmPassword) {
            $passwordErrors[] = 'Konfirmasi kata sandi baru tidak cocok.';
        }

        if (empty($passwordErrors)) {
            try {
                $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmtPass = $pdo->prepare('UPDATE users SET password = :password WHERE id = :id');
                $stmtPass->execute([
                    ':password' => $newHash,
                    ':id'       => $userId,
                ]);

                session_regenerate_id(true);
                $_SESSION['csrf_token']    = bin2hex(random_bytes(32));
                $_SESSION['flash_success'] = 'Kata sandi Anda berhasil diubah!';

                header('Location: edit-profil.php');
                exit;
            } catch (PDOException $e) {
                error_log('Update Password Error: ' . $e->getMessage());
                $passwordErrors[] = 'Gagal mengubah kata sandi. Silakan coba lagi.';
            }
        }
    }
}

// Buat inisial nama untuk avatar profil
$nameParts = preg_split('/\s+/', trim($user['name']));
$initials  = '';
foreach (array_slice($nameParts, 0, 2) as $part) {
    $initials .= mb_strtoupper(mb_substr($part, 0, 1));
}
$initials = $initials !== '' ? $initials : 'KF';

$joinedDate = new DateTime($user['created_at']);
$bulanIndo  = [
    1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
    'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
];
$joinedFormatted = $joinedDate->format('d') . ' ' . $bulanIndo[(int) $joinedDate->format('n')] . ' ' . $joinedDate->format('Y');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Profil - KF-OLX</title>
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Custom CSS Terpisah -->
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="min-h-screen flex flex-col bg-[#f7f8f9] text-[#002f34]">

    <!-- NAVBAR -->
    <header class="sticky top-0 z-50 bg-[#f7f8f9] border-b border-gray-200 shadow-2xs">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 sm:h-20 flex items-center justify-between gap-4">
            <div class="flex items-center gap-6">
                <a href="index.php" class="text-2xl sm:text-3xl font-extrabold tracking-tighter text-[#002f34] select-none">
                    KF<span class="text-[#23e5db]">-</span>OLX
                </a>
                <nav class="hidden sm:flex items-center gap-5 text-sm">
                    <a href="index.php" class="font-semibold text-[#5c7a7d] hover:text-[#002f34] transition">Beranda</a>
                    <a href="iklan-saya.php" class="font-semibold text-[#5c7a7d] hover:text-[#002f34] transition">Iklan Saya</a>
                    <a href="edit-profil.php" class="font-extrabold text-[#002f34] underline decoration-2 underline-offset-4">Edit Profil</a>
                </nav>
            </div>

            <div class="flex items-center gap-3 sm:gap-5">
                <a href="logout.php" class="text-xs sm:text-sm font-bold text-rose-600 hover:text-rose-700 underline underline-offset-4">
                    Keluar
                </a>

                <a href="pasang-iklan.php" class="inline-flex items-center gap-1.5 bg-white hover:bg-gray-50 text-[#002f34] font-extrabold text-xs sm:text-sm px-4 sm:px-5 py-2 rounded-full border-4 border-t-[#23e5db] border-r-[#3a77ff] border-b-[#ffce32] border-l-[#ffce32] shadow-xs transition">
                    <i class="fa-solid fa-plus"></i>
                    <span>JUAL</span>
                </a>
            </div>
        </div>
    </header>

    <!-- KONTEN UTAMA -->
    <main class="flex-1 max-w-3xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-8">

        <!-- Session Notice -->
        <?php if ($flashSuccess): ?>
            <div class="mb-6 p-4 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs sm:text-sm flex items-center gap-3 shadow-2xs">
                <i class="fa-solid fa-circle-check text-emerald-600 text-lg shrink-0"></i>
                <span class="font-semibold"><?= htmlspecialchars($flashSuccess, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        <?php endif; ?>

        <?php if ($flashError): ?>
            <div class="mb-6 p-4 rounded-lg bg-rose-50 border border-rose-200 text-rose-700 text-xs sm:text-sm flex items-center gap-3 shadow-2xs">
                <i class="fa-solid fa-circle-exclamation text-rose-600 text-lg shrink-0"></i>
                <span class="font-semibold"><?= htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        <?php endif; ?>

        <!-- Header Kartu Profil -->
        <div class="bg-white border border-gray-200 rounded-lg p-6 mb-6 shadow-2xs flex flex-col sm:flex-row items-center gap-4">
            <div class="w-16 h-16 rounded-full bg-[#002f34] text-[#23e5db] font-extrabold text-2xl flex items-center justify-center shrink-0">
                <?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?>
            </div>
            <div class="text-center sm:text-left flex-1 min-w-0">
                <h1 class="text-xl font-extrabold text-[#002f34] truncate">
                    <?= htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') ?>
                </h1>
                <p class="text-xs text-[#5c7a7d] mt-1">
                    Anggota sejak <?= htmlspecialchars($joinedFormatted, ENT_QUOTES, 'UTF-8') ?> &bull; <?= htmlspecialchars(formatWhatsAppDisplay($user['whatsapp']), ENT_QUOTES, 'UTF-8') ?>
                </p>
            </div>
            <a href="iklan-saya.php" class="text-xs font-bold text-[#002f34] border border-gray-300 hover:border-[#002f34] px-4 py-2 rounded transition shrink-0">
                Kelola Iklan Saya
            </a>
        </div>

        <div class="space-y-6">

            <!-- FORM 1: INFORMASI DASAR PROFIL -->
            <form action="" method="POST" class="bg-white border border-gray-200 rounded-lg shadow-2xs overflow-hidden">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="update_profile">

                <div class="h-1.5 w-full bg-gradient-to-r from-[#23e5db] via-[#3a77ff] to-[#ffce32]"></div>

                <div class="p-6 sm:p-8 space-y-5">
                    <div>
                        <h2 class="text-base sm:text-lg font-extrabold text-[#002f34] uppercase">
                            Informasi Profil &amp; Kontak
                        </h2>
                        <p class="text-xs text-[#5c7a7d] mt-0.5">
                            Nomor WhatsApp ini akan ditampilkan pada tombol hubungi penjual di semua iklan Anda.
                        </p>
                    </div>

                    <?php if (!empty($profileErrors)): ?>
                        <div class="p-3.5 rounded-md bg-rose-50 border border-rose-200 text-rose-700 text-xs sm:text-sm">
                            <ul class="list-disc pl-5 space-y-1">
                                <?php foreach ($profileErrors as $err): ?>
                                    <li><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <!-- Nama Lengkap -->
                    <div>
                        <label for="name" class="block text-xs font-bold uppercase tracking-wider text-[#002f34] mb-1.5">
                            Nama Lengkap <span class="text-rose-600">*</span>
                        </label>
                        <div class="olx-input-box flex items-center rounded px-3.5 h-12">
                            <i class="fa-regular fa-user text-[#5c7a7d] mr-3"></i>
                            <input 
                                type="text" 
                                id="name" 
                                name="name" 
                                value="<?= htmlspecialchars($nameInput, ENT_QUOTES, 'UTF-8') ?>"
                                maxlength="100"
                                required
                                class="w-full text-sm text-[#002f34] focus:outline-none"
                            >
                        </div>
                    </div>

                    <!-- Alamat Email -->
                    <div>
                        <label for="email" class="block text-xs font-bold uppercase tracking-wider text-[#002f34] mb-1.5">
                            Alamat Email <span class="text-rose-600">*</span>
                        </label>
                        <div class="olx-input-box flex items-center rounded px-3.5 h-12">
                            <i class="fa-regular fa-envelope text-[#5c7a7d] mr-3"></i>
                            <input 
                                type="email" 
                                id="email" 
                                name="email" 
                                value="<?= htmlspecialchars($emailInput, ENT_QUOTES, 'UTF-8') ?>"
                                maxlength="100"
                                required
                                class="w-full text-sm text-[#002f34] focus:outline-none"
                            >
                        </div>
                    </div>

                    <!-- Nomor WhatsApp -->
                    <div>
                        <label for="whatsapp" class="block text-xs font-bold uppercase tracking-wider text-[#002f34] mb-1.5">
                            Nomor WhatsApp <span class="text-rose-600">*</span>
                        </label>
                        <div class="olx-input-box flex items-center rounded px-3.5 h-12">
                            <i class="fa-brands fa-whatsapp text-emerald-600 text-base mr-3"></i>
                            <input 
                                type="tel" 
                                id="whatsapp" 
                                name="whatsapp" 
                                value="<?= htmlspecialchars($waInput, ENT_QUOTES, 'UTF-8') ?>"
                                maxlength="20"
                                required
                                placeholder="Contoh: 081234567890"
                                class="w-full text-sm text-[#002f34] focus:outline-none"
                            >
                        </div>
                    </div>

                    <div class="pt-2 flex justify-end">
                        <button type="submit" class="w-full sm:w-auto bg-[#002f34] hover:bg-[#00454d] text-white font-bold text-sm px-7 py-3 rounded transition cursor-pointer shadow-2xs">
                            Simpan Perubahan Profil
                        </button>
                    </div>
                </div>
            </form>

            <!-- FORM 2: UBAH KATA SANDI -->
            <form action="" method="POST" class="bg-white border border-gray-200 rounded-lg shadow-2xs overflow-hidden">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="update_password">

                <div class="p-6 sm:p-8 space-y-5">
                    <div>
                        <h2 class="text-base sm:text-lg font-extrabold text-[#002f34] uppercase">
                            Ubah Kata Sandi
                        </h2>
                        <p class="text-xs text-[#5c7a7d] mt-0.5">
                            Kosongkan bagian ini jika Anda tidak ingin mengganti kata sandi.
                        </p>
                    </div>

                    <?php if (!empty($passwordErrors)): ?>
                        <div class="p-3.5 rounded-md bg-rose-50 border border-rose-200 text-rose-700 text-xs sm:text-sm">
                            <ul class="list-disc pl-5 space-y-1">
                                <?php foreach ($passwordErrors as $err): ?>
                                    <li><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <!-- Kata Sandi Lama -->
                    <div>
                        <label for="current_password" class="block text-xs font-bold uppercase tracking-wider text-[#002f34] mb-1.5">
                            Kata Sandi Saat Ini <span class="text-rose-600">*</span>
                        </label>
                        <div class="olx-input-box flex items-center rounded px-3.5 h-12">
                            <i class="fa-solid fa-lock text-[#5c7a7d] mr-3"></i>
                            <input 
                                type="password" 
                                id="current_password" 
                                name="current_password" 
                                required
                                placeholder="Masukkan kata sandi lama"
                                class="w-full text-sm text-[#002f34] focus:outline-none"
                            >
                            <button type="button" onclick="togglePassword('current_password', 'eyeCur')" class="text-[#5c7a7d] hover:text-[#002f34] pl-2 cursor-pointer">
                                <i id="eyeCur" class="fa-regular fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Kata Sandi Baru -->
                    <div>
                        <label for="new_password" class="block text-xs font-bold uppercase tracking-wider text-[#002f34] mb-1.5">
                            Kata Sandi Baru <span class="text-rose-600">*</span>
                        </label>
                        <div class="olx-input-box flex items-center rounded px-3.5 h-12">
                            <i class="fa-solid fa-key text-[#5c7a7d] mr-3"></i>
                            <input 
                                type="password" 
                                id="new_password" 
                                name="new_password" 
                                minlength="6"
                                maxlength="72"
                                required
                                placeholder="Minimal 6 karakter"
                                class="w-full text-sm text-[#002f34] focus:outline-none"
                            >
                            <button type="button" onclick="togglePassword('new_password', 'eyeNew')" class="text-[#5c7a7d] hover:text-[#002f34] pl-2 cursor-pointer">
                                <i id="eyeNew" class="fa-regular fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Konfirmasi Kata Sandi Baru -->
                    <div>
                        <label for="confirm_password" class="block text-xs font-bold uppercase tracking-wider text-[#002f34] mb-1.5">
                            Konfirmasi Kata Sandi Baru <span class="text-rose-600">*</span>
                        </label>
                        <div class="olx-input-box flex items-center rounded px-3.5 h-12">
                            <i class="fa-solid fa-shield-halved text-[#5c7a7d] mr-3"></i>
                            <input 
                                type="password" 
                                id="confirm_password" 
                                name="confirm_password" 
                                minlength="6"
                                maxlength="72"
                                required
                                placeholder="Ulangi kata sandi baru"
                                class="w-full text-sm text-[#002f34] focus:outline-none"
                            >
                            <button type="button" onclick="togglePassword('confirm_password', 'eyeConf')" class="text-[#5c7a7d] hover:text-[#002f34] pl-2 cursor-pointer">
                                <i id="eyeConf" class="fa-regular fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="pt-2 flex justify-end">
                        <button type="submit" class="w-full sm:w-auto border-2 border-[#002f34] text-[#002f34] hover:bg-[#002f34] hover:text-white font-bold text-sm px-7 py-2.5 rounded transition cursor-pointer">
                            Perbarui Kata Sandi
                        </button>
                    </div>
                </div>
            </form>

        </div>
    </main>

    <!-- FOOTER -->
    <footer class="bg-[#002f34] text-white text-xs py-4 mt-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex flex-col sm:flex-row items-center justify-between gap-2">
            <p>Iklan Baris Gratis di Indonesia</p>
            <p>&copy; <?= date('Y') ?> <strong>KF-OLX</strong>. Hak Cipta Dilindungi.</p>
        </div>
    </footer>

    <script>
        function togglePassword(inputId, iconId) {
            const input = document.getElementById(inputId);
            const icon = document.getElementById(iconId);
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.replace('fa-eye-slash', 'fa-eye');
            }
        }
    </script>

</body>
</html>