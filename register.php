<?php
require_once 'config.php';

if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$errors   = [];
$name     = '';
$email    = '';
$whatsapp = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $errors[] = 'Sesi formulir tidak valid. Silakan muat ulang halaman dan coba lagi.';
    }

    if (!empty($_POST['website'])) {
        $errors[] = 'Permintaan ditolak oleh sistem keamanan.';
    }

    $name             = trim($_POST['name'] ?? '');
    $email            = strtolower(trim($_POST['email'] ?? ''));
    $whatsappRaw      = trim($_POST['whatsapp'] ?? '');
    $whatsapp         = normalizeWhatsApp($whatsappRaw);
    $password         = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $terms            = isset($_POST['terms']);

    if (empty($errors)) {
        if ($name === '') {
            $errors[] = 'Nama lengkap wajib diisi.';
        } elseif (mb_strlen($name) > 100) {
            $errors[] = 'Nama lengkap maksimal 100 karakter.';
        }

        if ($email === '') {
            $errors[] = 'Alamat email wajib diisi.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Format alamat email tidak valid.';
        } elseif (mb_strlen($email) > 100) {
            $errors[] = 'Alamat email maksimal 100 karakter.';
        }

        if ($whatsappRaw === '') {
            $errors[] = 'Nomor WhatsApp wajib diisi.';
        } elseif (!preg_match('/^628[0-9]{7,13}$/', $whatsapp)) {
            $errors[] = 'Format nomor WhatsApp tidak valid (contoh: 081234567890).';
        }

        if ($password === '') {
            $errors[] = 'Kata sandi wajib diisi.';
        } elseif (strlen($password) < 6) {
            $errors[] = 'Kata sandi minimal 6 karakter.';
        } elseif (strlen($password) > 72) {
            $errors[] = 'Kata sandi maksimal 72 karakter.';
        }

        if ($password !== $confirm_password) {
            $errors[] = 'Konfirmasi kata sandi tidak cocok.';
        }

        if (!$terms) {
            $errors[] = 'Anda harus menyetujui Syarat & Ketentuan serta Kebijakan Privasi.';
        }
    }

    if (empty($errors)) {
        try {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

            $stmtInsert = $pdo->prepare('
                INSERT INTO users (name, email, whatsapp, password)
                VALUES (:name, :email, :whatsapp, :password)
            ');

            $stmtInsert->execute([
                ':name'     => $name,
                ':email'    => $email,
                ':whatsapp' => $whatsapp,
                ':password' => $hashedPassword,
            ]);

            $_SESSION['csrf_token']    = bin2hex(random_bytes(32));
            $_SESSION['flash_success'] = 'Pendaftaran berhasil! Silakan masuk menggunakan Email atau Nomor WhatsApp Anda.';

            header('Location: login.php');
            exit;
        } catch (PDOException $e) {
            if ($e->getCode() === '23505') {
                if (str_contains($e->getMessage(), 'users_whatsapp_unique')) {
                    $errors[] = 'Nomor WhatsApp ini sudah terdaftar. Silakan gunakan nomor lain atau masuk.';
                } else {
                    $errors[] = 'Email ini sudah terdaftar. Silakan gunakan email lain atau masuk.';
                }
            } else {
                error_log('Register Error: ' . $e->getMessage());
                $errors[] = 'Gagal membuat akun. Silakan coba beberapa saat lagi.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daftar Akun - KF-OLX</title>
    
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
<body class="min-h-screen flex flex-col items-center justify-center py-8 px-4 sm:px-6 lg:px-8">

    <div class="max-w-md w-full">

        <div class="mb-4">
            <a href="index.php" class="inline-flex items-center gap-2 text-sm font-bold text-[#002f34] hover:text-[#007a87] transition">
                <i class="fa-solid fa-arrow-left"></i>
                <span>Kembali ke Beranda</span>
            </a>
        </div>

        <div class="bg-white border border-gray-200 rounded-lg shadow-sm overflow-hidden">
            <div class="h-1.5 w-full bg-gradient-to-r from-[#ffce32] via-[#23e5db] to-[#3a77ff]"></div>

            <div class="p-6 sm:p-8">
                <div class="text-center mb-7">
                    <a href="index.php" class="inline-block text-3xl font-extrabold tracking-tighter text-[#002f34] select-none">
                        KF<span class="text-[#23e5db]">-</span>OLX
                    </a>
                    <h1 class="mt-3 text-xl font-extrabold text-[#002f34]">
                        Buat Akun Baru
                    </h1>
                    <p class="mt-1 text-xs sm:text-sm text-[#5c7a7d]">
                        Daftar gratis untuk mulai memasang iklan di KF-OLX
                    </p>
                </div>

                <?php if (!empty($errors)): ?>
                    <div class="mb-5 p-3.5 rounded-md bg-rose-50 border border-rose-200 text-rose-700 text-xs sm:text-sm">
                        <div class="flex items-center gap-2 font-bold mb-1">
                            <i class="fa-solid fa-circle-exclamation"></i>
                            <span>Pendaftaran Gagal:</span>
                        </div>
                        <ul class="list-disc pl-5 space-y-0.5">
                            <?php foreach ($errors as $error): ?>
                                <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form action="" method="POST" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">

                    <div class="hidden" aria-hidden="true">
                        <label for="website">Website</label>
                        <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
                    </div>

                    <!-- Input Nama Lengkap -->
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
                                value="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>"
                                maxlength="100"
                                required
                                placeholder="Masukkan nama lengkap Anda"
                                class="w-full text-sm text-[#002f34] placeholder-gray-400 focus:outline-none"
                            >
                        </div>
                    </div>

                    <!-- Input Email -->
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
                                value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>"
                                maxlength="100"
                                required
                                placeholder="nama@gmail.com"
                                class="w-full text-sm text-[#002f34] placeholder-gray-400 focus:outline-none"
                            >
                        </div>
                    </div>

                    <!-- Input Nomor WhatsApp (Wajib) -->
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
                                value="<?= htmlspecialchars($_POST['whatsapp'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                maxlength="20"
                                required
                                placeholder="Contoh: 081234567890"
                                class="w-full text-sm text-[#002f34] placeholder-gray-400 focus:outline-none"
                            >
                        </div>
                        <p class="mt-1 text-[11px] text-[#5c7a7d]">
                            Nomor ini digunakan untuk masuk akun dan dihubungi pembeli via WhatsApp.
                        </p>
                    </div>

                    <!-- Input Password -->
                    <div>
                        <label for="password" class="block text-xs font-bold uppercase tracking-wider text-[#002f34] mb-1.5">
                            Kata Sandi <span class="text-rose-600">*</span>
                        </label>
                        <div class="olx-input-box flex items-center rounded px-3.5 h-12">
                            <i class="fa-solid fa-lock text-[#5c7a7d] mr-3"></i>
                            <input 
                                type="password" 
                                id="password" 
                                name="password" 
                                minlength="6"
                                maxlength="72"
                                required
                                placeholder="Minimal 6 karakter"
                                class="w-full text-sm text-[#002f34] placeholder-gray-400 focus:outline-none"
                            >
                            <button type="button" onclick="togglePassword('password', 'eyeReg1')" class="text-[#5c7a7d] hover:text-[#002f34] pl-2 focus:outline-none cursor-pointer" aria-label="Tampilkan Kata Sandi">
                                <i id="eyeReg1" class="fa-regular fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Konfirmasi Password -->
                    <div>
                        <label for="confirm_password" class="block text-xs font-bold uppercase tracking-wider text-[#002f34] mb-1.5">
                            Konfirmasi Kata Sandi <span class="text-rose-600">*</span>
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
                                placeholder="Ulangi kata sandi"
                                class="w-full text-sm text-[#002f34] placeholder-gray-400 focus:outline-none"
                            >
                            <button type="button" onclick="togglePassword('confirm_password', 'eyeReg2')" class="text-[#5c7a7d] hover:text-[#002f34] pl-2 focus:outline-none cursor-pointer" aria-label="Tampilkan Konfirmasi Kata Sandi">
                                <i id="eyeReg2" class="fa-regular fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Persetujuan Syarat & Ketentuan -->
                    <div class="flex items-start pt-1">
                        <input 
                            type="checkbox" 
                            id="terms" 
                            name="terms"
                            required
                            class="w-4 h-4 mt-0.5 rounded border-gray-300 text-[#002f34] focus:ring-[#23e5db] cursor-pointer"
                        >
                        <label for="terms" class="ml-2 text-xs text-[#5c7a7d] leading-relaxed cursor-pointer">
                            Saya menyetujui <a href="#" class="font-semibold text-[#002f34] underline">Syarat &amp; Ketentuan</a> serta <a href="#" class="font-semibold text-[#002f34] underline">Kebijakan Privasi</a> yang berlaku di KF-OLX.
                        </label>
                    </div>

                    <div class="pt-2">
                        <button type="submit" class="w-full bg-[#002f34] hover:bg-[#00454d] text-white font-bold text-sm h-12 rounded transition cursor-pointer shadow-xs">
                            Daftar Sekarang
                        </button>
                    </div>
                </form>

                <div class="relative my-6">
                    <div class="absolute inset-0 flex items-center">
                        <div class="w-full border-t border-gray-200"></div>
                    </div>
                    <div class="relative flex justify-center text-xs">
                        <span class="bg-white px-3 text-[#5c7a7d]">atau</span>
                    </div>
                </div>

                <p class="text-center text-sm text-[#5c7a7d]">
                    Sudah punya akun?
                    <a href="login.php" class="font-bold text-[#002f34] underline decoration-2 underline-offset-4 hover:no-underline ml-1">
                        Masuk di sini
                    </a>
                </p>
            </div>
        </div>

    </div>

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