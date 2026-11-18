<?php
require_once 'config.php';

date_default_timezone_set('Asia/Jakarta');

// Fungsi bantu format harga Rupiah
function formatRupiah($price): string
{
    return 'Rp ' . number_format((float) $price, 0, ',', '.');
}

// Fungsi bantu format tanggal posting ala OLX
function formatTanggalIklan(string $timestamp): string
{
    $created = new DateTime($timestamp);
    $now     = new DateTime('now');
    $diff    = $now->diff($created);

    if ($diff->days === 0) {
        if ($diff->h === 0) {
            return $diff->i <= 1 ? 'Baru saja' : $diff->i . ' menit lalu';
        }
        return 'Hari ini';
    }
    if ($diff->days === 1) {
        return 'Kemarin';
    }
    if ($diff->days < 7) {
        return $diff->days . ' hari lalu';
    }

    $bulan = [
        1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun',
        'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'
    ];
    return $created->format('d') . ' ' . $bulan[(int) $created->format('n')] . ' ' . $created->format('Y');
}

// 1. Ambil semua kategori dari tabel categories
$stmtCategories = $pdo->query('SELECT id, name, icon FROM categories ORDER BY id ASC');
$categories     = $stmtCategories->fetchAll();

// 2. Ambil daftar lokasi unik yang sudah ada di tabel ads untuk saran dropdown/datalist
$stmtLocations  = $pdo->query('SELECT DISTINCT location FROM ads WHERE location IS NOT NULL AND location <> \'\' ORDER BY location ASC LIMIT 20');
$locations      = $stmtLocations->fetchAll(PDO::FETCH_COLUMN);

// 3. Tangkap parameter filter & pencarian dari URL (GET)
$selectedCatId  = isset($_GET['category']) && $_GET['category'] !== '' ? (int) $_GET['category'] : 0;
$keyword        = trim($_GET['q'] ?? '');
$locationFilter = trim($_GET['location'] ?? '');

// 4. Susun Query Dinamis untuk mengambil data iklan + foto sampul pertama
$sql = '
    SELECT 
        a.id,
        a.title,
        a.price,
        a.location,
        a.created_at,
        c.id AS category_id,
        c.name AS category_name,
        img.image_path AS cover_image
    FROM ads a
    JOIN categories c ON a.category_id = c.id
    LEFT JOIN LATERAL (
        SELECT image_path
        FROM ad_images
        WHERE ad_id = a.id
        ORDER BY id ASC
        LIMIT 1
    ) img ON true
    WHERE 1=1
';

$params = [];

if ($selectedCatId > 0) {
    $sql .= ' AND a.category_id = :category_id';
    $params[':category_id'] = $selectedCatId;
}

if ($keyword !== '') {
    // Gunakan parameter terpisah karena ATTR_EMULATE_PREPARES = false di PostgreSQL
    $sql .= ' AND (a.title ILIKE :kw1 OR a.description ILIKE :kw2)';
    $params[':kw1'] = '%' . $keyword . '%';
    $params[':kw2'] = '%' . $keyword . '%';
}

if ($locationFilter !== '') {
    $sql .= ' AND a.location ILIKE :location';
    $params[':location'] = '%' . $locationFilter . '%';
}

$sql .= ' ORDER BY a.created_at DESC, a.id DESC LIMIT 24';

$stmtAds = $pdo->prepare($sql);
$stmtAds->execute($params);
$ads = $stmtAds->fetchAll();

// Cek nama kategori yang sedang aktif jika difilter
$activeCategoryName = '';
if ($selectedCatId > 0) {
    foreach ($categories as $cat) {
        if ((int) $cat['id'] === $selectedCatId) {
            $activeCategoryName = $cat['name'];
            break;
        }
    }
}

$isFiltering = ($selectedCatId > 0 || $keyword !== '' || $locationFilter !== '');
$isLoggedIn  = isset($_SESSION['user_id']);

$flashSuccess = $_SESSION['flash_success'] ?? null;
$flashError   = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KF-OLX - Jual Beli Mudah &amp; Cepat di Indonesia</title>
    
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
                
                <!-- Brand Logo -->
                <a href="index.php" class="text-2xl sm:text-3xl font-extrabold tracking-tighter text-[#002f34] shrink-0 select-none">
                    KF<span class="text-[#23e5db]">-</span>OLX
                </a>

                <!-- Form Pencarian & Lokasi (Desktop & Tablet) -->
                <form action="index.php" method="GET" class="hidden md:flex flex-1 items-center gap-3 max-w-3xl">
                    <?php if ($selectedCatId > 0): ?>
                        <input type="hidden" name="category" value="<?= $selectedCatId ?>">
                    <?php endif; ?>

                    <!-- Input Lokasi -->
                    <div class="olx-input-box flex items-center bg-white rounded px-3 h-12 w-60 shrink-0">
                        <i class="fa-solid fa-location-dot text-[#002f34] mr-2.5"></i>
                        <input 
                            type="text" 
                            name="location" 
                            value="<?= htmlspecialchars($locationFilter, ENT_QUOTES, 'UTF-8') ?>"
                            list="navLocationList"
                            placeholder="Semua Indonesia"
                            class="w-full text-sm font-medium text-[#002f34] placeholder-gray-400 focus:outline-none"
                        >
                        <datalist id="navLocationList">
                            <?php foreach ($locations as $loc): ?>
                                <option value="<?= htmlspecialchars($loc, ENT_QUOTES, 'UTF-8') ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>

                    <!-- Input Kata Kunci -->
                    <div class="olx-input-box flex items-center bg-white rounded overflow-hidden flex-1 h-12">
                        <input 
                            type="text" 
                            name="q" 
                            value="<?= htmlspecialchars($keyword, ENT_QUOTES, 'UTF-8') ?>"
                            placeholder="Temukan Mobil, Handphone, Properti dan lainnya..."
                            class="w-full px-3.5 text-sm text-[#002f34] placeholder-gray-400 focus:outline-none"
                        >
                        <button type="submit" class="bg-[#002f34] hover:bg-[#00454d] text-white h-full px-5 flex items-center justify-center transition cursor-pointer" aria-label="Cari Iklan">
                            <i class="fa-solid fa-magnifying-glass text-base"></i>
                        </button>
                    </div>
                </form>

                <!-- Aksi Pengguna & Tombol Jual -->
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

                    <!-- Tombol + JUAL Khas OLX -->
                    <a href="pasang-iklan.php" class="inline-flex items-center gap-1.5 bg-white hover:bg-gray-50 text-[#002f34] font-extrabold text-xs sm:text-sm px-4 sm:px-5 py-2 rounded-full border-4 border-t-[#23e5db] border-r-[#3a77ff] border-b-[#ffce32] border-l-[#ffce32] shadow-xs transition">
                        <i class="fa-solid fa-plus"></i>
                        <span>JUAL</span>
                    </a>
                </div>
            </div>

            <!-- Form Pencarian Mobile -->
            <form action="index.php" method="GET" class="pb-3 md:hidden flex gap-2">
                <?php if ($selectedCatId > 0): ?>
                    <input type="hidden" name="category" value="<?= $selectedCatId ?>">
                <?php endif; ?>
                <div class="olx-input-box flex items-center bg-white rounded overflow-hidden flex-1 h-10">
                    <input 
                        type="text" 
                        name="q" 
                        value="<?= htmlspecialchars($keyword, ENT_QUOTES, 'UTF-8') ?>"
                        placeholder="Cari barang di KF-OLX..."
                        class="w-full px-3 text-xs text-[#002f34] placeholder-gray-400 focus:outline-none"
                    >
                    <button type="submit" class="bg-[#002f34] text-white h-full px-4 flex items-center justify-center">
                        <i class="fa-solid fa-magnifying-glass text-xs"></i>
                    </button>
                </div>
            </form>
        </div>

        <!-- SUB-NAVBAR KATEGORI -->
        <nav class="bg-white border-t border-gray-200">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex items-center gap-5 overflow-x-auto py-2.5 text-xs sm:text-sm whitespace-nowrap no-scrollbar">
                    <a href="index.php" class="font-extrabold uppercase tracking-wide <?= $selectedCatId === 0 ? 'text-[#002f34] underline decoration-2 underline-offset-4' : 'text-[#5c7a7d] hover:text-[#002f34]' ?>">
                        Semua Kategori
                    </a>
                    <span class="text-gray-300">|</span>
                    <?php foreach ($categories as $cat): ?>
                        <?php $catId = (int) $cat['id']; ?>
                        <a 
                            href="index.php?category=<?= $catId ?><?= $keyword !== '' ? '&q=' . urlencode($keyword) : '' ?><?= $locationFilter !== '' ? '&location=' . urlencode($locationFilter) : '' ?>" 
                            class="inline-flex items-center gap-1.5 transition <?= $selectedCatId === $catId ? 'font-extrabold text-[#002f34] underline decoration-2 underline-offset-4' : 'font-medium text-[#002f34]/80 hover:text-[#002f34]' ?>"
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
    <main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6 sm:py-8">
        <?php if ($flashSuccess): ?>
            <div class="mb-5 p-4 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs sm:text-sm flex items-center gap-3 shadow-2xs">
                <i class="fa-solid fa-circle-check text-emerald-600 text-lg shrink-0"></i>
                <span class="font-semibold"><?= htmlspecialchars($flashSuccess, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        <?php endif; ?>

        <?php if ($flashError): ?>
            <div class="mb-5 p-4 rounded-lg bg-rose-50 border border-rose-200 text-rose-700 text-xs sm:text-sm flex items-center gap-3 shadow-2xs">
                <i class="fa-solid fa-circle-exclamation text-rose-600 text-lg shrink-0"></i>
                <span class="font-semibold"><?= htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        <?php endif; ?>

        <!-- Banner Hero (Tampil saat tidak sedang mencari/filter) -->
        <?php if (!$isFiltering): ?>
            <section class="mb-8 rounded-xl overflow-hidden bg-[#002f34] text-white relative shadow-xs">
                <div class="h-1.5 w-full bg-gradient-to-r from-[#23e5db] via-[#3a77ff] to-[#ffce32]"></div>
                <div class="p-6 sm:p-10 flex flex-col md:flex-row items-start md:items-center justify-between gap-6">
                    <div class="max-w-xl">
                        <span class="inline-block bg-[#23e5db] text-[#002f34] text-[11px] font-extrabold uppercase tracking-wider px-2.5 py-1 rounded mb-3">
                            Pasar Online Terpercaya
                        </span>
                        <h1 class="text-2xl sm:text-3xl font-extrabold leading-tight">
                            Jual Barang Bekas Jadi Uang Tunai dalam Hitungan Menit
                        </h1>
                        <p class="mt-2 text-xs sm:text-sm text-gray-300">
                            Pasang iklan gratis tanpa ribet dan temukan ribuan penawaran terbaik di sekitar kota Anda.
                        </p>
                    </div>
                    <a href="pasang-iklan.php" class="bg-[#ffce32] hover:bg-[#ffd859] text-[#002f34] font-extrabold text-sm px-6 py-3.5 rounded-md transition shrink-0 shadow-xs">
                        Mulai Pasang Iklan
                    </a>
                </div>
            </section>

            <!-- Jelajahi Kategori (Grid Ikon) -->
            <?php if (!empty($categories)): ?>
                <section class="mb-9">
                    <h2 class="text-lg sm:text-xl font-extrabold text-[#002f34] mb-4">
                        Jelajahi Kategori
                    </h2>
                    <div class="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-8 gap-3">
                        <?php foreach ($categories as $cat): ?>
                            <a href="index.php?category=<?= (int) $cat['id'] ?>" class="group bg-white border border-gray-200 hover:border-[#002f34] rounded-lg p-3.5 flex flex-col items-center text-center transition shadow-2xs">
                                <div class="w-11 h-11 rounded-full bg-[#ebf1ff] group-hover:bg-[#23e5db]/30 text-[#002f34] flex items-center justify-center text-lg mb-2 transition">
                                    <i class="<?= htmlspecialchars($cat['icon'] ?: 'fa-solid fa-tag', ENT_QUOTES, 'UTF-8') ?>"></i>
                                </div>
                                <span class="text-xs font-bold text-[#002f34] line-clamp-1">
                                    <?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
        <?php endif; ?>

        <!-- Header Daftar Iklan + Status Filter -->
        <section>
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-5">
                <div>
                    <h2 class="text-lg sm:text-xl font-extrabold text-[#002f34]">
                        <?php if ($isFiltering): ?>
                            Menampilkan Hasil Iklan (<?= count($ads) ?>)
                        <?php else: ?>
                            Rekomendasi Terbaru
                        <?php endif; ?>
                    </h2>

                    <?php if ($isFiltering): ?>
                        <div class="flex flex-wrap items-center gap-2 mt-2 text-xs">
                            <?php if ($activeCategoryName !== ''): ?>
                                <span class="bg-white border border-gray-300 px-2.5 py-1 rounded-full font-semibold">
                                    Kategori: <?= htmlspecialchars($activeCategoryName, ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            <?php endif; ?>
                            <?php if ($keyword !== ''): ?>
                                <span class="bg-white border border-gray-300 px-2.5 py-1 rounded-full font-semibold">
                                    Kata kunci: "<?= htmlspecialchars($keyword, ENT_QUOTES, 'UTF-8') ?>"
                                </span>
                            <?php endif; ?>
                            <?php if ($locationFilter !== ''): ?>
                                <span class="bg-white border border-gray-300 px-2.5 py-1 rounded-full font-semibold">
                                    Lokasi: <?= htmlspecialchars($locationFilter, ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            <?php endif; ?>
                            <a href="index.php" class="text-rose-600 hover:underline font-bold ml-1">
                                Reset Filter
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Grid Kartu Iklan -->
            <?php if (empty($ads)): ?>
                <div class="bg-white border border-gray-200 rounded-lg p-10 text-center shadow-2xs">
                    <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-gray-100 text-[#5c7a7d] flex items-center justify-center text-2xl">
                        <i class="fa-regular fa-folder-open"></i>
                    </div>
                    <h3 class="text-base sm:text-lg font-extrabold text-[#002f34]">
                        Belum ada iklan yang ditemukan
                    </h3>
                    <p class="mt-1 text-xs sm:text-sm text-[#5c7a7d] max-w-md mx-auto">
                        <?= $isFiltering ? 'Coba ubah kata kunci pencarian, lokasi, atau pilih kategori lainnya.' : 'Jadilah yang pertama memasang iklan di KF-OLX sekarang!' ?>
                    </p>
                    <div class="mt-5 flex items-center justify-center gap-3">
                        <?php if ($isFiltering): ?>
                            <a href="index.php" class="px-5 py-2.5 rounded border border-[#002f34] text-sm font-bold text-[#002f34] hover:bg-gray-50 transition">
                                Lihat Semua Iklan
                            </a>
                        <?php endif; ?>
                        <a href="pasang-iklan.php" class="px-5 py-2.5 rounded bg-[#002f34] hover:bg-[#00454d] text-white text-sm font-bold transition">
                            + Pasang Iklan
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3.5 sm:gap-5">
                    <?php foreach ($ads as $ad): ?>
                        <?php
                            $coverPath = !empty($ad['cover_image']) ? $ad['cover_image'] : '';
                            $hasValidImage = ($coverPath !== '' && is_file(__DIR__ . '/' . $coverPath));
                        ?>
                        <a href="detail.php?id=<?= (int) $ad['id'] ?>" class="group bg-white border border-gray-200 hover:border-[#002f34] rounded-lg overflow-hidden flex flex-col transition shadow-2xs hover:shadow-md">
                            <!-- Foto Sampul Iklan -->
                            <div class="relative aspect-[4/3] bg-gray-100 overflow-hidden">
                                <?php if ($hasValidImage): ?>
                                    <img 
                                        src="<?= htmlspecialchars($coverPath, ENT_QUOTES, 'UTF-8') ?>" 
                                        alt="<?= htmlspecialchars($ad['title'], ENT_QUOTES, 'UTF-8') ?>" 
                                        loading="lazy"
                                        class="w-full h-full object-cover group-hover:scale-105 transition duration-300"
                                    >
                                <?php else: ?>
                                    <div class="w-full h-full flex flex-col items-center justify-center text-gray-400">
                                        <i class="fa-regular fa-image text-3xl mb-1"></i>
                                        <span class="text-[11px]">Tanpa Foto</span>
                                    </div>
                                <?php endif; ?>

                                <!-- Badge Kategori -->
                                <span class="absolute top-2.5 left-2.5 bg-white/95 backdrop-blur-xs text-[#002f34] text-[10px] font-extrabold uppercase px-2 py-0.5 rounded shadow-2xs">
                                    <?= htmlspecialchars($ad['category_name'], ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </div>

                            <!-- Detail Ringkas Kartu -->
                            <div class="p-3.5 flex-1 flex flex-col justify-between border-l-4 border-l-[#ffce32]">
                                <div>
                                    <p class="text-base sm:text-lg font-extrabold text-[#002f34] truncate">
                                        <?= formatRupiah($ad['price']) ?>
                                    </p>
                                    <h3 class="mt-1 text-xs sm:text-sm font-medium text-[#002f34]/90 line-clamp-2 leading-snug">
                                        <?= htmlspecialchars($ad['title'], ENT_QUOTES, 'UTF-8') ?>
                                    </h3>
                                </div>

                                <div class="mt-3 pt-2.5 border-t border-gray-100 flex items-center justify-between gap-2 text-[11px] text-[#5c7a7d]">
                                    <span class="truncate">
                                        <i class="fa-solid fa-location-dot mr-1 text-[#002f34]/70"></i><?= htmlspecialchars($ad['location'], ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                    <span class="shrink-0">
                                        <?= formatTanggalIklan($ad['created_at']) ?>
                                    </span>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

    </main>

    <!-- FOOTER -->
    <footer class="bg-[#ebeeef] border-t border-gray-200 mt-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 grid grid-cols-1 sm:grid-cols-3 gap-6 text-xs text-[#5c7a7d]">
            <div>
                <span class="text-xl font-extrabold tracking-tighter text-[#002f34]">
                    KF<span class="text-[#23e5db]">-</span>OLX
                </span>
                <p class="mt-2 leading-relaxed">
                    Platform iklan baris online untuk jual beli kendaraan, properti, gadget, dan kebutuhan harian di seluruh Indonesia.
                </p>
            </div>
            <div>
                <h4 class="font-extrabold text-[#002f34] uppercase mb-2.5">Kategori Populer</h4>
                <ul class="space-y-1.5">
                    <?php foreach (array_slice($categories, 0, 4) as $cat): ?>
                        <li>
                            <a href="index.php?category=<?= (int) $cat['id'] ?>" class="hover:text-[#002f34] transition">
                                <?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div>
                <h4 class="font-extrabold text-[#002f34] uppercase mb-2.5">Akun &amp; Bantuan</h4>
                <ul class="space-y-1.5">
                    <li><a href="pasang-iklan.php" class="hover:text-[#002f34] transition">Pasang Iklan Gratis</a></li>
                    <?php if ($isLoggedIn): ?>
                        <li><a href="logout.php" class="hover:text-[#002f34] transition">Keluar Akun</a></li>
                    <?php else: ?>
                        <li><a href="login.php" class="hover:text-[#002f34] transition">Masuk / Daftar</a></li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
        <div class="bg-[#002f34] text-white text-xs py-4">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex flex-col sm:flex-row items-center justify-between gap-2">
                <p>Iklan Baris Gratis di Indonesia</p>
                <p>&copy; <?= date('Y') ?> <strong>KF-OLX</strong>. Hak Cipta Dilindungi.</p>
            </div>
        </div>
    </footer>

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