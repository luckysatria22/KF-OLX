<?php
require_once 'config.php';

if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$flashSuccess = $_SESSION['flash_success'] ?? null;
unset($_SESSION['flash_success']);

$errors     = [];
$identifier = '';

$_SESSION['login_attempts'] = $_SESSION['login_attempts'] ?? 0;
$_SESSION['lockout_until']  = $_SESSION['lockout_until'] ?? 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (time() < $_SESSION['lockout_until']) {
        $sisaDetik = $_SESSION['lockout_until'] - time();
        $errors[]  = "Terlalu banyak percobaan masuk gagal. Silakan tunggu {$sisaDetik} detik.";
    }

    if (empty($errors) && !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $errors[] = 'Sesi formulir tidak valid. Silakan muat ulang halaman.';
    }

    $identifier = trim($_POST['identifier'] ?? '');
    $password   = $_POST['password'] ?? '';

    if (empty($errors)) {
        if ($identifier === '') {
            $errors[] = 'Masukkan alamat email atau nomor WhatsApp Anda.';
        }
        if ($password === '') {
            $errors[] = 'Kata sandi wajib diisi.';
        }
    }

    if (empty($errors)) {
        try {
            $emailInput = strtolower($identifier);
            $waInput    = normalizeWhatsApp($identifier);

            // Cocokkan berdasarkan email ATAU nomor WhatsApp yang sudah dinormalisasi
            $stmt = $pdo->prepare('
                SELECT id, name, email, whatsapp, password 
                FROM users 
                WHERE LOWER(email) = :email 
                   OR (whatsapp IS NOT NULL AND whatsapp <> \'\' AND whatsapp = :whatsapp)
                LIMIT 1
            ');
            $stmt->execute([
                ':email'    => $emailInput,
                ':whatsapp' => $waInput,
            ]);
            $user = $stmt->fetch();

            $hashToVerify = $user['password'] ?? '$2y$10$abcdefghijklmnopqrstuvABCDEFGHIJKLMNOPQRSTUVWXYZ01234';
            $isValid      = password_verify($password, $hashToVerify);

            if ($user && $isValid) {
                $_SESSION['login_attempts'] = 0;
                $_SESSION['lockout_until']  = 0;
                session_regenerate_id(true);

                $_SESSION['user_id']       = $user['id'];
                $_SESSION['user_name']     = $user['name'];
                $_SESSION['user_email']    = $user['email'];
                $_SESSION['user_whatsapp'] = $user['whatsapp'];
                $_SESSION['csrf_token']    = bin2hex(random_bytes(32));

                header('Location: index.php');
                exit;
            } else {
                $_SESSION['login_attempts']++;
                if ($_SESSION['login_attempts'] >= 5) {
                    $_SESSION['lockout_until']  = time() + 60;
                    $_SESSION['login_attempts'] = 0;
                    $errors[] = 'Terlalu banyak percobaan gagal. Akun dikunci sementara selama 60 detik.';
                } else {
                    $errors[] = 'Email / nomor WhatsApp atau kata sandi salah.';
                }
            }
        } catch (PDOException $e) {
            error_log('Login Error: ' . $e->getMessage());
            $errors[] = 'Terjadi kesalahan sistem. Silakan coba beberapa saat lagi.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Masuk - KF-OLX</title>
    
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
            <div class="h-1.5 w-full bg-gradient-to-r from-[#23e5db] via-[#3a77ff] to-[#ffce32]"></div>

            <div class="p-6 sm:p-8">
                <div class="text-center mb-7">
                    <a href="index.php" class="inline-block text-3xl font-extrabold tracking-tighter text-[#002f34] select-none">
                        KF<span class="text-[#23e5db]">-</span>OLX
                    </a>
                    <h1 class="mt-3 text-xl font-extrabold text-[#002f34]">
                        Masuk ke Akun Anda
                    </h1>
                    <p class="mt-1 text-xs sm:text-sm text-[#5c7a7d]">
                        Gunakan Email (Gmail) atau Nomor WhatsApp yang terdaftar
                    </p>
                </div>

                <?php if ($flashSuccess): ?>
                    <div class="mb-5 p-3.5 rounded-md bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs sm:text-sm flex items-center gap-2.5">
                        <i class="fa-solid fa-circle-check text-emerald-600 text-base shrink-0"></i>
                        <span><?= htmlspecialchars($flashSuccess, ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                <?php endif; ?>

                <?php if (!empty($errors)): ?>
                    <div class="mb-5 p-3.5 rounded-md bg-rose-50 border border-rose-200 text-rose-700 text-xs sm:text-sm">
                        <div class="flex items-center gap-2 font-bold mb-1">
                            <i class="fa-solid fa-circle-exclamation"></i>
                            <span>Gagal Masuk:</span>
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

                    <!-- Input Email atau Nomor WhatsApp -->
                    <div>
                        <label for="identifier" class="block text-xs font-bold uppercase tracking-wider text-[#002f34] mb-1.5">
                            Email atau Nomor WhatsApp
                        </label>
                        <div class="olx-input-box flex items-center rounded px-3.5 h-12">
                            <i class="fa-regular fa-id-badge text-[#5c7a7d] mr-3"></i>
                            <input 
                                type="text" 
                                id="identifier" 
                                name="identifier" 
                                value="<?= htmlspecialchars($identifier, ENT_QUOTES, 'UTF-8') ?>"
                                maxlength="100"
                                required
                                placeholder="nama@gmail.com atau 081234567890"
                                class="w-full text-sm text-[#002f34] placeholder-gray-400 focus:outline-none"
                            >
                        </div>
                    </div>

                    <!-- Input Password -->
                    <div>
                        <div class="flex items-center justify-between mb-1.5">
                            <label for="password" class="block text-xs font-bold uppercase tracking-wider text-[#002f34]">
                                Kata Sandi
                            </label>
                        </div>
                        <div class="olx-input-box flex items-center rounded px-3.5 h-12">
                            <i class="fa-solid fa-lock text-[#5c7a7d] mr-3"></i>
                            <input 
                                type="password" 
                                id="password" 
                                name="password" 
                                maxlength="255"
                                required
                                placeholder="Masukkan kata sandi"
                                class="w-full text-sm text-[#002f34] placeholder-gray-400 focus:outline-none"
                            >
                            <button type="button" onclick="togglePassword('password', 'eyeLogin')" class="text-[#5c7a7d] hover:text-[#002f34] pl-2 focus:outline-none cursor-pointer" aria-label="Tampilkan Kata Sandi">
                                <i id="eyeLogin" class="fa-regular fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="flex items-center pt-1">
                        <input 
                            type="checkbox" 
                            id="remember" 
                            name="remember"
                            class="w-4 h-4 rounded border-gray-300 text-[#002f34] focus:ring-[#23e5db] cursor-pointer"
                        >
                        <label for="remember" class="ml-2 text-xs sm:text-sm text-[#5c7a7d] cursor-pointer">
                            Ingat sesi masuk saya
                        </label>
                    </div>

                    <div class="pt-2">
                        <button type="submit" class="w-full bg-[#002f34] hover:bg-[#00454d] text-white font-bold text-sm h-12 rounded transition cursor-pointer shadow-xs">
                            Masuk
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
                    Belum punya akun KF-OLX?
                    <a href="register.php" class="font-bold text-[#002f34] underline decoration-2 underline-offset-4 hover:no-underline ml-1">
                        Daftar Sekarang
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