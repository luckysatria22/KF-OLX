<?php
require_once 'config.php';

date_default_timezone_set('Asia/Jakarta');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function formatRupiah($price): string
{
    return 'Rp ' . number_format((float) $price, 0, ',', '.');
}

function formatTanggalIndo(string $timestamp, bool $denganWaktu = false): string
{
    $dt = new DateTime($timestamp);
    $bulan = [
        1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
    ];

    $hasil = $dt->format('d') . ' ' . $bulan[(int) $dt->format('n')] . ' ' . $dt->format('Y');
    if ($denganWaktu) {
        $hasil .= ', ' . $dt->format('H:i') . ' WIB';
    }
    return $hasil;
}

$flashSuccess = $_SESSION['flash_success'] ?? null;
unset($_SESSION['flash_success']);

$isLoggedIn    = isset($_SESSION['user_id']);
$currentUserId = $isLoggedIn ? (int) $_SESSION['user_id'] : 0;
$adId          = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$actionError   = '';

// 1. Proses Hapus Iklan (Khusus Pemilik Iklan)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_ad') {
    if (!$isLoggedIn) {
        header('Location: login.php');
        exit;
    }

    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $actionError = 'Sesi formulir tidak valid. Silakan muat ulang halaman.';
    } else {
        $deleteAdId = (int) ($_POST['ad_id'] ?? 0);

        try {
            $stmtOwner = $pdo->prepare('SELECT id FROM ads WHERE id = :id AND user_id = :user_id LIMIT 1');
            $stmtOwner->execute([
                ':id'      => $deleteAdId,
                ':user_id' => $currentUserId,
            ]);

            if ($stmtOwner->fetch()) {
                $stmtImgs = $pdo->prepare('SELECT image_path FROM ad_images WHERE ad_id = :ad_id');
                $stmtImgs->execute([':ad_id' => $deleteAdId]);
                $imgPaths = $stmtImgs->fetchAll(PDO::FETCH_COLUMN);

                $stmtDel = $pdo->prepare('DELETE FROM ads WHERE id = :id AND user_id = :user_id');
                $stmtDel->execute([
                    ':id'      => $deleteAdId,
                    ':user_id' => $currentUserId,
                ]);

                foreach ($imgPaths as $relPath) {
                    $fullPath = __DIR__ . '/' . $relPath;
                    if (is_file($fullPath)) {
                        @unlink($fullPath);
                    }
                }

                $_SESSION['csrf_token']    = bin2hex(random_bytes(32));
                $_SESSION['flash_success'] = 'Iklan Anda telah berhasil dihapus.';
                header('Location: index.php');
                exit;
            } else {
                $actionError = 'Anda tidak memiliki izin untuk menghapus iklan ini.';
            }
        } catch (PDOException $e) {
            error_log('Delete Ad Error: ' . $e->getMessage());
            $actionError = 'Terjadi kesalahan saat menghapus iklan. Silakan coba lagi.';
        }
    }
}

// 2. Ambil daftar kategori untuk sub-navbar
$stmtCategories = $pdo->query('SELECT id, name, icon FROM categories ORDER BY id ASC');
$categories     = $stmtCategories->fetchAll();

// 3. Ambil detail iklan utama (JOIN ads, categories, dan users termasuk kolom whatsapp)
$ad          = null;
$validImages = [];
$relatedAds  = [];

if ($adId > 0) {
    $stmtAd = $pdo->prepare('
        SELECT 
            a.id,
            a.user_id,
            a.category_id,
            a.title,
            a.description,
            a.price,
            a.location,
            a.created_at,
            c.name AS category_name,
            c.icon AS category_icon,
            u.name AS seller_name,
            u.email AS seller_email,
            u.whatsapp AS seller_whatsapp,
            u.created_at AS seller_joined_at
        FROM ads a
        JOIN categories c ON a.category_id = c.id
        JOIN users u ON a.user_id = u.id
        WHERE a.id = :id
        LIMIT 1
    ');
    $stmtAd->execute([':id' => $adId]);
    $ad = $stmtAd->fetch();

    if ($ad) {
        $stmtImages = $pdo->prepare('
            SELECT id, image_path
            FROM ad_images
            WHERE ad_id = :ad_id
            ORDER BY id ASC
        ');
        $stmtImages->execute([':ad_id' => $adId]);
        $rawImages = $stmtImages->fetchAll();

        foreach ($rawImages as $img) {
            if (!empty($img['image_path']) && is_file(__DIR__ . '/' . $img['image_path'])) {
                $validImages[] = $img['image_path'];
            }
        }

        $sellerParts    = preg_split('/\s+/', trim($ad['seller_name']));
        $sellerInitials = '';
        foreach (array_slice($sellerParts, 0, 2) as $part) {
            $sellerInitials .= mb_strtoupper(mb_substr($part, 0, 1));
        }
        $sellerInitials = $sellerInitials !== '' ? $sellerInitials : 'KF';

        $stmtRelated = $pdo->prepare('
            SELECT 
                a.id,
                a.title,
                a.price,
                a.location,
                a.created_at,
                img.image_path AS cover_image
            FROM ads a
            LEFT JOIN LATERAL (
                SELECT image_path
                FROM ad_images
                WHERE ad_id = a.id
                ORDER BY id ASC
                LIMIT 1
            ) img ON true
            WHERE a.category_id = :category_id
              AND a.id <> :current_id
            ORDER BY a.created_at DESC, a.id DESC
            LIMIT 4
        ');
        $stmtRelated->execute([
            ':category_id' => (int) $ad['category_id'],
            ':current_id'  => $adId,
        ]);
        $relatedAds = $stmtRelated->fetchAll();
    }
}

$isOwner = ($ad && $isLoggedIn && $currentUserId === (int) $ad['user_id']);

// Siapkan link WhatsApp & pesan otomatis untuk pembeli
$sellerWaNumber = $ad ? normalizeWhatsApp((string) ($ad['seller_whatsapp'] ?? '')) : '';
$waChatUrl      = '';
if ($ad && $sellerWaNumber !== '') {
    $waMessage = "Halo {$ad['seller_name']}, saya tertarik dengan iklan \"{$ad['title']}\" (" . formatRupiah($ad['price']) . ") di KF-OLX. Apakah barangnya masih tersedia?";
    $waChatUrl = 'https://wa.me/' . $sellerWaNumber . '?text=' . rawurlencode($waMessage);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $ad ? htmlspecialchars($ad['title'], ENT_QUOTES, 'UTF-8') . ' - KF-OLX' : 'Iklan Tidak Ditemukan - KF-OLX' ?></title>
    
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

    <!-- NAVBAR UTAMA -->
    <header class="sticky top-0 z-50 bg-[#f7f8f9] border-b border-gray-200 shadow-2xs">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="h-16 sm:h-20 flex items-center justify-between gap-3 sm:gap-5">
                
                <a href="index.php" class="text-2xl sm:text-3xl font-extrabold tracking-tighter text-[#002f34] shrink-0 select-none">
                    KF<span class="text-[#23e5db]">-</span>OLX
                </a>

                <form action="index.php" method="GET" class="hidden md:flex flex-1 items-center gap-3 max-w-3xl">
                    <div class="olx-input-box flex items-center bg-white rounded px-3 h-12 w-60 shrink-0">
                        <i class="fa-solid fa-location-dot text-[#002f34] mr-2.5"></i>
                        <input 
                            type="text" 
                            name="location" 
                            placeholder="Semua Indonesia"
                            class="w-full text-sm font-medium text-[#002f34] placeholder-gray-400 focus:outline-none"
                        >
                    </div>

                    <div class="olx-input-box flex items-center bg-white rounded overflow-hidden flex-1 h-12">
                        <input 
                            type="text" 
                            name="q" 
                            placeholder="Temukan Mobil, Handphone, Properti dan lainnya..."
                            class="w-full px-3.5 text-sm text-[#002f34] placeholder-gray-400 focus:outline-none"
                        >
                        <button type="submit" class="bg-[#002f34] hover:bg-[#00454d] text-white h-full px-5 flex items-center justify-center transition cursor-pointer" aria-label="Cari Iklan">
                            <i class="fa-solid fa-magnifying-glass text-base"></i>
                        </button>
                    </div>
                </form>

                <div class="flex items-center gap-3 sm:gap-5 shrink-0">
                    <?php if ($isLoggedIn): ?>
                        <div class="relative">
                            <button
                                type="button"
                                id="profileDropdownButton"
                                class="flex items-center gap-2"
                                aria-haspopup="true"
                                aria-expanded="false"
                                aria-label="Buka menu profil"
                            >
                                <span class="w-9 h-9 rounded-full bg-[#002f34] text-[#23e5db] font-extrabold text-xs flex items-center justify-center">
                                    <?= htmlspecialchars(mb_strtoupper(mb_substr($_SESSION['user_name'] ?? 'U', 0, 1)), ENT_QUOTES, 'UTF-8') ?>
                                </span>
                                <i id="profileChevron" class="fa-solid fa-chevron-down text-xs text-[#002f34] transition-transform duration-200"></i>
                            </button>
                            <div id="profileDropdownMenu" class="hidden absolute right-0 top-full mt-2.5 w-72 bg-white rounded-lg shadow-xl border border-gray-200 overflow-hidden z-50">
                                <div class="p-4">
                                    <div class="flex items-center gap-3 mb-4">
                                        <div class="w-12 h-12 rounded-full bg-[#002f34] text-[#23e5db] font-extrabold text-sm flex items-center justify-center shrink-0">
                                            <?= htmlspecialchars(mb_strtoupper(mb_substr($_SESSION['user_name'] ?? 'U', 0, 1)), ENT_QUOTES, 'UTF-8') ?>
                                        </div>
                                        <div class="min-w-0">
                                            <p class="font-bold text-sm text-[#002f34] truncate"><?= htmlspecialchars($_SESSION['user_name'] ?? 'Pengguna', ENT_QUOTES, 'UTF-8') ?></p>
                                            <p class="text-xs text-gray-500 truncate"><?= htmlspecialchars($_SESSION['user_email'] ?? '', ENT_QUOTES, 'UTF-8') ?></p>
                                        </div>
                                    </div>
                                    <a href="edit-profil.php" class="bg-[#002f34] hover:bg-[#00454d] text-white font-bold text-sm py-2.5 rounded text-center block transition">
                                        Lihat dan edit profil
                                    </a>
                                </div>
                                <div class="border-t border-gray-100 py-1.5">
                                    <a href="iklan-saya.php" class="flex items-center gap-3 px-4 py-2.5 text-sm text-[#002f34] hover:bg-gray-50 transition">
                                        <i class="fa-regular fa-rectangle-list w-4 text-center"></i>
                                        Iklan Saya
                                    </a>
                                    <a href="pasang-iklan.php" class="flex items-center gap-3 px-4 py-2.5 text-sm text-[#002f34] hover:bg-gray-50 transition">
                                        <i class="fa-solid fa-camera w-4 text-center"></i>
                                        Pasang Iklan Baru
                                    </a>
                                </div>
                                <div class="border-t border-gray-100 py-1.5">
                                    <a href="logout.php" class="flex items-center gap-3 px-4 py-2.5 text-sm text-rose-600 hover:bg-rose-50 font-semibold transition">
                                        <i class="fa-solid fa-arrow-right-from-bracket w-4 text-center"></i>
                                        Keluar
                                    </a>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <a href="login.php" class="text-sm font-extrabold text-[#002f34] underline decoration-2 underline-offset-4 hover:no-underline">
                            Login / Daftar
                        </a>
                    <?php endif; ?>

                    <a href="pasang-iklan.php" class="inline-flex items-center gap-1.5 bg-white hover:bg-gray-50 text-[#002f34] font-extrabold text-xs sm:text-sm px-4 sm:px-5 py-2 rounded-full border-4 border-t-[#23e5db] border-r-[#3a77ff] border-b-[#ffce32] border-l-[#ffce32] shadow-xs transition">
                        <i class="fa-solid fa-plus"></i>
                        <span>JUAL</span>
                    </a>
                </div>
            </div>
        </div>

        <!-- SUB-NAVBAR KATEGORI -->
        <nav class="bg-white border-t border-gray-200">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex items-center gap-5 overflow-x-auto py-2.5 text-xs sm:text-sm whitespace-nowrap no-scrollbar">
                    <a href="index.php" class="font-extrabold uppercase tracking-wide text-[#5c7a7d] hover:text-[#002f34]">
                        Semua Kategori
                    </a>
                    <span class="text-gray-300">|</span>
                    <?php foreach ($categories as $cat): ?>
                        <?php $catId = (int) $cat['id']; ?>
                        <a 
                            href="index.php?category=<?= $catId ?>" 
                            class="inline-flex items-center gap-1.5 transition <?= ($ad && (int) $ad['category_id'] === $catId) ? 'font-extrabold text-[#002f34] underline decoration-2 underline-offset-4' : 'font-medium text-[#002f34]/80 hover:text-[#002f34]' ?>"
                        >
                            <i class="<?= htmlspecialchars($cat['icon'] ?: 'fa-solid fa-tag', ENT_QUOTES, 'UTF-8') ?> text-xs text-[#5c7a7d]"></i>
                            <span><?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </nav>
    </header>

    <!-- KONTEN UTAMA -->
    <main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6">

        <?php if ($flashSuccess): ?>
            <div class="mb-5 p-4 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs sm:text-sm flex items-center gap-3 shadow-2xs">
                <i class="fa-solid fa-circle-check text-emerald-600 text-lg shrink-0"></i>
                <span class="font-semibold"><?= htmlspecialchars($flashSuccess, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        <?php endif; ?>

        <?php if ($actionError !== ''): ?>
            <div class="mb-5 p-4 rounded-lg bg-rose-50 border border-rose-200 text-rose-700 text-xs sm:text-sm flex items-center gap-3 shadow-2xs">
                <i class="fa-solid fa-circle-exclamation text-rose-600 text-lg shrink-0"></i>
                <span class="font-semibold"><?= htmlspecialchars($actionError, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        <?php endif; ?>

        <?php if (!$ad): ?>
            <div class="bg-white border border-gray-200 rounded-lg p-12 text-center shadow-2xs my-6">
                <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-gray-100 text-[#5c7a7d] flex items-center justify-center text-2xl">
                    <i class="fa-solid fa-magnifying-glass"></i>
                </div>
                <h1 class="text-xl font-extrabold text-[#002f34]">
                    Iklan Tidak Ditemukan
                </h1>
                <p class="mt-1.5 text-sm text-[#5c7a7d] max-w-md mx-auto">
                    Iklan yang Anda cari mungkin sudah terjual, dihapus oleh pemiliknya, atau tautan tidak valid.
                </p>
                <a href="index.php" class="mt-6 inline-flex items-center gap-2 px-6 py-3 rounded bg-[#002f34] hover:bg-[#00454d] text-white text-sm font-bold transition">
                    <i class="fa-solid fa-arrow-left"></i>
                    <span>Kembali ke Beranda</span>
                </a>
            </div>
        <?php else: ?>

            <!-- Breadcrumb Navigasi -->
            <nav aria-label="Breadcrumb" class="mb-4 flex flex-wrap items-center gap-2 text-xs text-[#5c7a7d]">
                <a href="index.php" class="hover:text-[#002f34] hover:underline">Beranda</a>
                <i class="fa-solid fa-chevron-right text-[10px]"></i>
                <a href="index.php?category=<?= (int) $ad['category_id'] ?>" class="hover:text-[#002f34] hover:underline">
                    <?= htmlspecialchars($ad['category_name'], ENT_QUOTES, 'UTF-8') ?>
                </a>
                <i class="fa-solid fa-chevron-right text-[10px]"></i>
                <span class="text-[#002f34] font-semibold truncate max-w-xs sm:max-w-md">
                    <?= htmlspecialchars($ad['title'], ENT_QUOTES, 'UTF-8') ?>
                </span>
            </nav>

            <!-- Grid Utama 2 Kolom -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                <!-- KOLOM KIRI (2/3): Galeri Foto & Deskripsi Iklan -->
                <div class="lg:col-span-2 space-y-5">

                    <!-- Galeri Foto -->
                    <div class="bg-white border border-gray-200 rounded-lg overflow-hidden shadow-2xs">
                        <div class="relative bg-[#00171a] aspect-[16/10] sm:aspect-[16/9] flex items-center justify-center overflow-hidden select-none">
                            <?php if (!empty($validImages)): ?>
                                <img 
                                    id="mainGalleryImage" 
                                    src="<?= htmlspecialchars($validImages[0], ENT_QUOTES, 'UTF-8') ?>" 
                                    alt="<?= htmlspecialchars($ad['title'], ENT_QUOTES, 'UTF-8') ?>" 
                                    class="max-h-full max-w-full object-contain transition duration-200"
                                >

                                <?php if (count($validImages) > 1): ?>
                                    <button 
                                        type="button" 
                                        onclick="changeSlide(-1)" 
                                        class="absolute left-3 top-1/2 -translate-y-1/2 w-10 h-10 rounded-full bg-white/90 hover:bg-white text-[#002f34] flex items-center justify-center shadow-md transition cursor-pointer"
                                        aria-label="Foto Sebelumnya"
                                    >
                                        <i class="fa-solid fa-chevron-left"></i>
                                    </button>

                                    <button 
                                        type="button" 
                                        onclick="changeSlide(1)" 
                                        class="absolute right-3 top-1/2 -translate-y-1/2 w-10 h-10 rounded-full bg-white/90 hover:bg-white text-[#002f34] flex items-center justify-center shadow-md transition cursor-pointer"
                                        aria-label="Foto Berikutnya"
                                    >
                                        <i class="fa-solid fa-chevron-right"></i>
                                    </button>
                                <?php endif; ?>

                                <span id="slideCounter" class="absolute bottom-3 right-3 bg-black/75 text-white text-xs font-bold px-3 py-1 rounded-full">
                                    1 / <?= count($validImages) ?>
                                </span>
                            <?php else: ?>
                                <div class="flex flex-col items-center justify-center text-gray-400 py-12">
                                    <i class="fa-regular fa-image text-5xl mb-2"></i>
                                    <span class="text-sm">Penjual tidak menyertakan foto</span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <?php if (count($validImages) > 1): ?>
                            <div class="p-3.5 bg-white border-t border-gray-200 flex items-center gap-2.5 overflow-x-auto">
                                <?php foreach ($validImages as $index => $imgPath): ?>
                                    <button 
                                        type="button" 
                                        onclick="goToSlide(<?= $index ?>)"
                                        id="thumb-<?= $index ?>"
                                        class="thumb-btn shrink-0 w-16 h-16 sm:w-20 sm:h-20 rounded-md overflow-hidden border-2 transition cursor-pointer <?= $index === 0 ? 'border-[#002f34] ring-2 ring-[#23e5db]' : 'border-gray-200 opacity-70 hover:opacity-100' ?>"
                                    >
                                        <img 
                                            src="<?= htmlspecialchars($imgPath, ENT_QUOTES, 'UTF-8') ?>" 
                                            alt="Thumbnail <?= $index + 1 ?>" 
                                            class="w-full h-full object-cover"
                                        >
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Card Detail & Deskripsi -->
                    <div class="bg-white border border-gray-200 rounded-lg p-5 sm:p-7 shadow-2xs">
                        <h2 class="text-lg font-extrabold text-[#002f34] pb-3 border-b border-gray-200">
                            Detail &amp; Deskripsi
                        </h2>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 py-4 border-b border-gray-200 text-xs sm:text-sm">
                            <div class="flex items-center justify-between sm:justify-start sm:gap-3">
                                <span class="text-[#5c7a7d]">Kategori:</span>
                                <a href="index.php?category=<?= (int) $ad['category_id'] ?>" class="font-bold text-[#002f34] underline">
                                    <?= htmlspecialchars($ad['category_name'], ENT_QUOTES, 'UTF-8') ?>
                                </a>
                            </div>
                            <div class="flex items-center justify-between sm:justify-start sm:gap-3">
                                <span class="text-[#5c7a7d]">ID Iklan:</span>
                                <span class="font-bold text-[#002f34]">#<?= (int) $ad['id'] ?></span>
                            </div>
                        </div>

                        <div class="pt-4">
                            <h3 class="text-xs font-bold uppercase tracking-wider text-[#5c7a7d] mb-2">
                                Deskripsi Barang / Jasa
                            </h3>
                            <div class="text-sm text-[#002f34] leading-relaxed break-words">
                                <?= nl2br(htmlspecialchars($ad['description'], ENT_QUOTES, 'UTF-8')) ?>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- KOLOM KANAN (1/3): Harga, Info Penjual (WhatsApp), Aksi Pemilik & Tips -->
                <div class="space-y-5">

                    <!-- Card Harga & Judul -->
                    <div class="bg-white border border-gray-200 rounded-lg p-5 sm:p-6 shadow-2xs relative overflow-hidden">
                        <div class="h-1 w-full bg-[#ffce32] absolute top-0 left-0"></div>

                        <div class="flex items-start justify-between gap-3">
                            <p class="text-2xl sm:text-3xl font-extrabold text-[#002f34]">
                                <?= formatRupiah($ad['price']) ?>
                            </p>
                            <button 
                                type="button" 
                                onclick="copyAdLink()" 
                                id="shareBtn"
                                class="w-9 h-9 rounded-full border border-gray-200 hover:border-[#002f34] text-[#002f34] flex items-center justify-center transition shrink-0 cursor-pointer"
                                title="Salin Tautan Iklan"
                            >
                                <i class="fa-solid fa-share-nodes text-sm"></i>
                            </button>
                        </div>

                        <p id="copyToast" class="hidden mt-1.5 text-xs font-bold text-emerald-600">
                            <i class="fa-solid fa-check mr-1"></i>Tautan iklan berhasil disalin!
                        </p>

                        <h1 class="mt-2.5 text-base sm:text-lg font-semibold text-[#002f34]/90 leading-snug">
                            <?= htmlspecialchars($ad['title'], ENT_QUOTES, 'UTF-8') ?>
                        </h1>

                        <div class="mt-5 pt-4 border-t border-gray-100 flex flex-col gap-2 text-xs text-[#5c7a7d]">
                            <div class="flex items-start gap-2">
                                <i class="fa-solid fa-location-dot text-[#002f34] mt-0.5 shrink-0"></i>
                                <span><?= htmlspecialchars($ad['location'], ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                            <div class="flex items-center gap-2">
                                <i class="fa-regular fa-clock text-[#002f34] shrink-0"></i>
                                <span>Ditayangkan <?= formatTanggalIndo($ad['created_at'], true) ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Card Informasi Penjual & Chat WhatsApp -->
                    <div class="bg-white border border-gray-200 rounded-lg p-5 sm:p-6 shadow-2xs">
                        <h2 class="text-xs font-bold uppercase tracking-wider text-[#5c7a7d] mb-3.5">
                            Informasi Penjual
                        </h2>

                        <div class="flex items-center gap-3.5">
                            <div class="w-14 h-14 rounded-full bg-[#002f34] text-[#23e5db] font-extrabold text-xl flex items-center justify-center shrink-0">
                                <?= htmlspecialchars($sellerInitials, ENT_QUOTES, 'UTF-8') ?>
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="text-base font-extrabold text-[#002f34] truncate">
                                    <?= htmlspecialchars($ad['seller_name'], ENT_QUOTES, 'UTF-8') ?>
                                </p>
                                <p class="text-xs text-[#5c7a7d] mt-0.5">
                                    Anggota sejak <?= formatTanggalIndo($ad['seller_joined_at']) ?>
                                </p>
                            </div>
                        </div>

                        <div class="mt-5 space-y-2.5">
                            <?php if ($isOwner): ?>
                                <div class="p-3 rounded bg-[#ebf1ff] text-[#002f34] text-xs font-semibold text-center">
                                    Ini adalah iklan yang Anda pasang.
                                </div>

                                <a href="edit-iklan.php?id=<?= (int) $ad['id'] ?>" class="w-full h-11 rounded bg-[#002f34] hover:bg-[#00454d] text-white font-bold text-sm flex items-center justify-center gap-2 transition">
                                    <i class="fa-regular fa-pen-to-square"></i>
                                    <span>Edit Iklan Ini</span>
                                </a>

                                <form action="" method="POST" onsubmit="return confirm('Apakah Anda yakin ingin menghapus iklan ini secara permanen?');">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="action" value="delete_ad">
                                    <input type="hidden" name="ad_id" value="<?= (int) $ad['id'] ?>">
                                    <button type="submit" class="w-full h-11 rounded border-2 border-rose-600 text-rose-600 hover:bg-rose-600 hover:text-white font-bold text-sm flex items-center justify-center gap-2 transition cursor-pointer">
                                        <i class="fa-regular fa-trash-can"></i>
                                        <span>Hapus Iklan Ini</span>
                                    </button>
                                </form>
                            <?php elseif ($waChatUrl !== ''): ?>
                                <a 
                                    href="<?= htmlspecialchars($waChatUrl, ENT_QUOTES, 'UTF-8') ?>" 
                                    target="_blank" 
                                    rel="noopener noreferrer"
                                    class="w-full h-12 rounded bg-[#25D366] hover:bg-[#1ebe5d] text-white font-extrabold text-sm flex items-center justify-center gap-2.5 transition shadow-xs"
                                >
                                    <i class="fa-brands fa-whatsapp text-xl"></i>
                                    <span>Hubungi Penjual via WhatsApp</span>
                                </a>

                                <div class="p-3 rounded bg-[#f7f8f9] border border-gray-200 text-center">
                                    <p class="text-[11px] text-[#5c7a7d]">Nomor WhatsApp Penjual</p>
                                    <p class="text-xs font-extrabold text-[#002f34] select-all mt-0.5">
                                        <?= htmlspecialchars(formatWhatsAppDisplay($sellerWaNumber), ENT_QUOTES, 'UTF-8') ?>
                                    </p>
                                </div>
                            <?php else: ?>
                                <div class="p-3 rounded bg-amber-50 border border-amber-200 text-amber-800 text-xs text-center">
                                    Penjual belum mencantumkan nomor WhatsApp.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Card Tips Keamanan -->
                    <div class="bg-white border border-gray-200 rounded-lg p-5 shadow-2xs">
                        <h3 class="text-sm font-extrabold text-[#002f34] flex items-center gap-2 mb-2.5">
                            <i class="fa-solid fa-shield-halved text-[#002f34]"></i>
                            <span>Tips Aman Bertransaksi</span>
                        </h3>
                        <ul class="text-xs text-[#5c7a7d] space-y-1.5 list-disc pl-4 leading-relaxed">
                            <li>Usahakan bertemu langsung (COD) di tempat umum yang ramai.</li>
                            <li>Periksa fisik dan kelengkapan barang sebelum melakukan pembayaran.</li>
                            <li>Hindari transfer uang muka (DP) sebelum mengecek barang secara langsung.</li>
                        </ul>
                    </div>

                </div>
            </div>

            <!-- SEKSI IKLAN SERUPA -->
            <?php if (!empty($relatedAds)): ?>
                <section class="mt-10 pt-8 border-t border-gray-200">
                    <div class="flex items-center justify-between mb-5">
                        <h2 class="text-lg sm:text-xl font-extrabold text-[#002f34]">
                            Iklan Serupa di <?= htmlspecialchars($ad['category_name'], ENT_QUOTES, 'UTF-8') ?>
                        </h2>
                        <a href="index.php?category=<?= (int) $ad['category_id'] ?>" class="text-xs sm:text-sm font-bold text-[#002f34] underline">
                            Lihat Semua
                        </a>
                    </div>

                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3.5 sm:gap-5">
                        <?php foreach ($relatedAds as $rel): ?>
                            <?php
                                $relCover = !empty($rel['cover_image']) ? $rel['cover_image'] : '';
                                $relHasImg = ($relCover !== '' && is_file(__DIR__ . '/' . $relCover));
                            ?>
                            <a href="detail.php?id=<?= (int) $rel['id'] ?>" class="group bg-white border border-gray-200 hover:border-[#002f34] rounded-lg overflow-hidden flex flex-col transition shadow-2xs hover:shadow-md">
                                <div class="aspect-[4/3] bg-gray-100 overflow-hidden">
                                    <?php if ($relHasImg): ?>
                                        <img 
                                            src="<?= htmlspecialchars($relCover, ENT_QUOTES, 'UTF-8') ?>" 
                                            alt="<?= htmlspecialchars($rel['title'], ENT_QUOTES, 'UTF-8') ?>" 
                                            loading="lazy"
                                            class="w-full h-full object-cover group-hover:scale-105 transition duration-300"
                                        >
                                    <?php else: ?>
                                        <div class="w-full h-full flex flex-col items-center justify-center text-gray-400">
                                            <i class="fa-regular fa-image text-2xl mb-1"></i>
                                            <span class="text-[11px]">Tanpa Foto</span>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="p-3.5 flex-1 flex flex-col justify-between border-l-4 border-l-[#ffce32]">
                                    <div>
                                        <p class="text-sm sm:text-base font-extrabold text-[#002f34] truncate">
                                            <?= formatRupiah($rel['price']) ?>
                                        </p>
                                        <h3 class="mt-1 text-xs sm:text-sm font-medium text-[#002f34]/90 line-clamp-2">
                                            <?= htmlspecialchars($rel['title'], ENT_QUOTES, 'UTF-8') ?>
                                        </h3>
                                    </div>
                                    <p class="mt-3 pt-2 border-t border-gray-100 text-[11px] text-[#5c7a7d] truncate">
                                        <i class="fa-solid fa-location-dot mr-1"></i><?= htmlspecialchars($rel['location'], ENT_QUOTES, 'UTF-8') ?>
                                    </p>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>

        <?php endif; ?>

    </main>

    <!-- FOOTER -->
    <footer class="bg-[#ebeeef] border-t border-gray-200 mt-12">
        <div class="bg-[#002f34] text-white text-xs py-4">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex flex-col sm:flex-row items-center justify-between gap-2">
                <p>Iklan Baris Gratis di Indonesia</p>
                <p>&copy; <?= date('Y') ?> <strong>KF-OLX</strong>. Hak Cipta Dilindungi.</p>
            </div>
        </div>
    </footer>

    <script>
        const galleryImages = <?= json_encode($validImages, JSON_UNESCAPED_SLASHES) ?>;
        let currentSlide = 0;

        function goToSlide(index) {
            if (!galleryImages.length) return;
            currentSlide = (index + galleryImages.length) % galleryImages.length;

            const mainImg = document.getElementById('mainGalleryImage');
            const counter = document.getElementById('slideCounter');
            if (mainImg) {
                mainImg.src = galleryImages[currentSlide];
            }
            if (counter) {
                counter.textContent = (currentSlide + 1) + ' / ' + galleryImages.length;
            }

            document.querySelectorAll('.thumb-btn').forEach((btn, idx) => {
                if (idx === currentSlide) {
                    btn.classList.add('border-[#002f34]', 'ring-2', 'ring-[#23e5db]');
                    btn.classList.remove('border-gray-200', 'opacity-70');
                } else {
                    btn.classList.remove('border-[#002f34]', 'ring-2', 'ring-[#23e5db]');
                    btn.classList.add('border-gray-200', 'opacity-70');
                }
            });
        }

        function changeSlide(step) {
            goToSlide(currentSlide + step);
        }

        document.addEventListener('keydown', (e) => {
            if (galleryImages.length > 1) {
                if (e.key === 'ArrowLeft') changeSlide(-1);
                if (e.key === 'ArrowRight') changeSlide(1);
            }
        });

        function copyAdLink() {
            navigator.clipboard.writeText(window.location.href).then(() => {
                const toast = document.getElementById('copyToast');
                toast.classList.remove('hidden');
                setTimeout(() => toast.classList.add('hidden'), 2500);
            });
        }
    </script>

    <script>
        (() => {
            const profileButton = document.getElementById('profileDropdownButton');
            const profileMenu = document.getElementById('profileDropdownMenu');
            const profileChevron = document.getElementById('profileChevron');

            if (!profileButton || !profileMenu || !profileChevron) return;

            const setProfileMenuOpen = (isOpen) => {
                profileMenu.classList.toggle('hidden', !isOpen);
                profileChevron.classList.toggle('rotate-180', isOpen);
                profileButton.setAttribute('aria-expanded', String(isOpen));
            };

            profileButton.addEventListener('click', () => {
                setProfileMenuOpen(profileMenu.classList.contains('hidden'));
            });

            document.addEventListener('click', (event) => {
                if (!profileMenu.parentElement.contains(event.target)) {
                    setProfileMenuOpen(false);
                }
            });

            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') setProfileMenuOpen(false);
            });
        })();
    </script>
</body>
</html>