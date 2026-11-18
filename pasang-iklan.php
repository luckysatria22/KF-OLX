<?php
require_once 'config.php';

// 1. Wajib login untuk mengakses halaman pasang iklan
if (!isset($_SESSION['user_id'])) {
    $_SESSION['flash_success'] = 'Silakan masuk terlebih dahulu untuk memasang iklan.';
    header('Location: login.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$userId = (int) $_SESSION['user_id'];

// 2. Ambil data terbaru user dari tabel users
$stmtUser = $pdo->prepare('SELECT id, name, email, whatsapp FROM users WHERE id = :id LIMIT 1');
$stmtUser->execute([':id' => $userId]);
$currentUser = $stmtUser->fetch();

if (!$currentUser) {
    session_destroy();
    header('Location: login.php');
    exit;
}

// Buat inisial nama user untuk avatar
$nameParts = preg_split('/\s+/', trim($currentUser['name']));
$initials  = '';
foreach (array_slice($nameParts, 0, 2) as $part) {
    $initials .= mb_strtoupper(mb_substr($part, 0, 1));
}
$initials = $initials !== '' ? $initials : 'KF';

// 3. Ambil daftar kategori dari tabel categories
$stmtCat    = $pdo->query('SELECT id, name, icon FROM categories ORDER BY name ASC');
$categories = $stmtCat->fetchAll();

$errors           = [];
$selectedCategory = $_POST['category_id'] ?? '';
$customCategory   = '';
$title            = '';
$description      = '';
$price            = '';
$priceDisplay     = '';
$city             = '';
$addressDetail    = '';

// 4. Proses Pengiriman Formulir
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $errors[] = 'Sesi formulir tidak valid. Silakan muat ulang halaman dan coba lagi.';
    }

    $selectedCategory = trim($_POST['category_id'] ?? '');
    $customCategory   = trim($_POST['custom_category'] ?? '');
    $title            = trim($_POST['title'] ?? '');
    $description      = trim($_POST['description'] ?? '');
    $priceRaw         = trim($_POST['price'] ?? '');
    $city             = trim($_POST['city'] ?? '');
    $addressDetail    = trim($_POST['address_detail'] ?? '');

    // Validasi Kategori (Dropdown atau Isi Sendiri)
    $validCategoryIds = array_map('strval', array_column($categories, 'id'));
    if ($selectedCategory === '') {
        $errors[] = 'Silakan pilih kategori iklan.';
    } elseif ($selectedCategory === 'other') {
        if ($customCategory === '') {
            $errors[] = 'Nama kategori baru wajib diisi.';
        } elseif (mb_strlen($customCategory) > 50) {
            $errors[] = 'Nama kategori baru maksimal 50 karakter.';
        }
    } elseif (!in_array($selectedCategory, $validCategoryIds, true)) {
        $errors[] = 'Kategori yang dipilih tidak valid.';
    }

    // Validasi Judul (ads.title - varchar(150))
    if ($title === '') {
        $errors[] = 'Judul iklan wajib diisi.';
    } elseif (mb_strlen($title) > 150) {
        $errors[] = 'Judul iklan maksimal 150 karakter.';
    }

    // Validasi Deskripsi (ads.description - text)
    if ($description === '') {
        $errors[] = 'Deskripsi iklan wajib diisi.';
    } elseif (mb_strlen($description) > 4096) {
        $errors[] = 'Deskripsi iklan maksimal 4096 karakter.';
    }

    // Validasi Harga (hapus titik pemisah ribuan sebelum disimpan ke decimal(15,2))
    $priceDigits = preg_replace('/[^0-9]/', '', $priceRaw);
    if ($priceDigits === '') {
        $errors[] = 'Masukkan nominal harga yang valid.';
    } elseif ((float) $priceDigits > 9999999999999.99) {
        $errors[] = 'Nominal harga melebihi batas maksimal.';
    } else {
        $price        = number_format((float) $priceDigits, 2, '.', '');
        $priceDisplay = number_format((float) $priceDigits, 0, ',', '.');
    }

    // Validasi & Gabungkan Alamat ke kolom ads.location (varchar(100))
    if ($city === '') {
        $errors[] = 'Kota / Kabupaten wajib diisi.';
    } else {
        $fullLocation = $addressDetail !== '' ? ($addressDetail . ', ' . $city) : $city;
        if (mb_strlen($fullLocation) > 100) {
            $errors[] = 'Gabungan alamat lengkap dan kota maksimal 100 karakter (saat ini ' . mb_strlen($fullLocation) . ' karakter).';
        }
    }

    // Validasi File Foto (maksimal 6 foto, cek MIME asli, maksimal 5MB/foto)
    $uploadedFiles = [];
    $allowedMimes  = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    if (isset($_FILES['images']) && is_array($_FILES['images']['name'])) {
        $totalFiles = min(count($_FILES['images']['name']), 6);

        for ($i = 0; $i < $totalFiles; $i++) {
            $fileError = $_FILES['images']['error'][$i];

            if ($fileError === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            if ($fileError !== UPLOAD_ERR_OK) {
                $errors[] = 'Salah satu foto gagal diunggah (kode error: ' . $fileError . ').';
                continue;
            }

            if ($_FILES['images']['size'][$i] > 5 * 1024 * 1024) {
                $errors[] = 'Ukuran setiap foto maksimal 5 MB.';
                continue;
            }

            $finfo    = finfo_open(FILEINFO_MIME_TYPE);
            $realMime = finfo_file($finfo, $_FILES['images']['tmp_name'][$i]);
            finfo_close($finfo);

            if (!isset($allowedMimes[$realMime])) {
                $errors[] = 'Format foto hanya boleh JPG, JPEG, PNG, atau WEBP.';
                continue;
            }

            $uploadedFiles[] = [
                'tmp_name' => $_FILES['images']['tmp_name'][$i],
                'ext'      => $allowedMimes[$realMime],
            ];
        }
    }

    if (empty($uploadedFiles)) {
        $errors[] = 'Minimal unggah 1 foto utama untuk iklan Anda.';
    }

    // 5. Simpan Data ke Database dalam Satu Transaksi
    if (empty($errors)) {
        $savedDiskPaths = [];

        try {
            $uploadDir = __DIR__ . '/uploads/ads/';
            if (!is_dir($uploadDir)) {
                if (!mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
                    throw new RuntimeException('Direktori uploads/ads gagal dibuat.');
                }
            }

            $pdo->beginTransaction();

            // Tentukan category_id (ambil yang ada atau buat kategori baru ke tabel categories)
            if ($selectedCategory === 'other') {
                $stmtFindCat = $pdo->prepare('SELECT id FROM categories WHERE LOWER(name) = LOWER(:name) LIMIT 1');
                $stmtFindCat->execute([':name' => $customCategory]);
                $existingCatId = $stmtFindCat->fetchColumn();

                if ($existingCatId) {
                    $finalCategoryId = (int) $existingCatId;
                } else {
                    $stmtNewCat = $pdo->prepare('
                        INSERT INTO categories (name, icon)
                        VALUES (:name, :icon)
                        RETURNING id
                    ');
                    $stmtNewCat->execute([
                        ':name' => $customCategory,
                        ':icon' => 'fa-solid fa-tag',
                    ]);
                    $finalCategoryId = (int) $stmtNewCat->fetchColumn();
                }
            } else {
                $finalCategoryId = (int) $selectedCategory;
            }

            // Insert ke tabel ads
            $stmtInsertAd = $pdo->prepare('
                INSERT INTO ads (user_id, category_id, title, description, price, location)
                VALUES (:user_id, :category_id, :title, :description, :price, :location)
                RETURNING id
            ');

            $stmtInsertAd->execute([
                ':user_id'     => $userId,
                ':category_id' => $finalCategoryId,
                ':title'       => $title,
                ':description' => $description,
                ':price'       => $price,
                ':location'    => $fullLocation,
            ]);

            $newAdId = (int) $stmtInsertAd->fetchColumn();

            // Simpan foto ke server & tabel ad_images
            $stmtInsertImg = $pdo->prepare('
                INSERT INTO ad_images (ad_id, image_path)
                VALUES (:ad_id, :image_path)
            ');

            foreach ($uploadedFiles as $file) {
                $randomName   = bin2hex(random_bytes(16)) . '.' . $file['ext'];
                $fullDiskPath = $uploadDir . $randomName;
                $dbImagePath  = 'uploads/ads/' . $randomName;

                if (!move_uploaded_file($file['tmp_name'], $fullDiskPath)) {
                    throw new RuntimeException('Gagal memindahkan file gambar ke server.');
                }

                $savedDiskPaths[] = $fullDiskPath;

                $stmtInsertImg->execute([
                    ':ad_id'      => $newAdId,
                    ':image_path' => $dbImagePath,
                ]);
            }

            $pdo->commit();

            $_SESSION['csrf_token']    = bin2hex(random_bytes(32));
            $_SESSION['flash_success'] = 'Iklan Anda berhasil dipublikasikan!';

            header('Location: detail.php?id=' . $newAdId);
            exit;

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            foreach ($savedDiskPaths as $diskPath) {
                if (is_file($diskPath)) {
                    @unlink($diskPath);
                }
            }

            error_log('Post Ad Error: ' . $e->getMessage());
            $errors[] = 'Gagal menyimpan iklan. Pastikan folder uploads memiliki hak akses tulis dan coba lagi.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pasang Iklan - KF-OLX</title>
    
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
<body class="min-h-screen flex flex-col">

    <!-- Header Minimal Khusus Halaman Pasang Iklan -->
    <header class="sticky top-0 z-50 bg-[#f7f8f9] border-b border-gray-200 shadow-2xs">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
            <div class="flex items-center gap-4">
                <a href="index.php" class="w-9 h-9 rounded-full hover:bg-gray-200/70 text-[#002f34] flex items-center justify-center transition" title="Kembali ke Beranda">
                    <i class="fa-solid fa-arrow-left text-lg"></i>
                </a>
                <a href="index.php" class="text-2xl font-extrabold tracking-tighter text-[#002f34] select-none">
                    KF<span class="text-[#23e5db]">-</span>OLX
                </a>
            </div>
            <span class="text-xs sm:text-sm font-bold text-[#5c7a7d]">
                Pasang Iklan Gratis
            </span>
        </div>
    </header>

    <!-- Konten Utama Form Pasang Iklan -->
    <main class="flex-1 py-8 px-4 sm:px-6 lg:px-8">
        <div class="max-w-3xl mx-auto">

            <h1 class="text-xl sm:text-2xl font-extrabold text-[#002f34] text-center uppercase tracking-tight mb-6">
                Pasang Iklan Anda
            </h1>

            <!-- Notifikasi Error Validasi / Upload -->
            <?php if (!empty($errors)): ?>
                <div class="mb-6 p-4 rounded-lg bg-rose-50 border border-rose-200 text-rose-700 text-xs sm:text-sm shadow-2xs">
                    <div class="flex items-center gap-2 font-bold mb-1.5">
                        <i class="fa-solid fa-circle-exclamation"></i>
                        <span>Gagal Memasang Iklan:</span>
                    </div>
                    <ul class="list-disc pl-5 space-y-1">
                        <?php foreach ($errors as $error): ?>
                            <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <!-- Form Pasang Iklan -->
            <form action="" method="POST" enctype="multipart/form-data" id="postAdForm" class="bg-white border border-gray-200 rounded-lg shadow-2xs overflow-hidden">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                
                <!-- Aksen Garis Atas Khas OLX -->
                <div class="h-1.5 w-full bg-gradient-to-r from-[#23e5db] via-[#3a77ff] to-[#ffce32]"></div>

                <!-- BAGIAN 1: PILIH KATEGORI (Dropdown + Custom) -->
                <div class="p-6 sm:p-8 border-b border-gray-200">
                    <h2 class="text-base sm:text-lg font-extrabold text-[#002f34] uppercase mb-1">
                        1. Pilih Kategori
                    </h2>
                    <p class="text-xs text-[#5c7a7d] mb-4">
                        Pilih kategori yang sesuai atau tambahkan kategori baru jika belum tersedia
                    </p>

                    <div class="max-w-md space-y-4">
                        <div>
                            <label for="category_id" class="block text-xs font-bold uppercase tracking-wider text-[#002f34] mb-1.5">
                                Kategori Iklan <span class="text-rose-600">*</span>
                            </label>
                            <div class="olx-input-box flex items-center rounded px-3.5 h-12 bg-white">
                                <i class="fa-solid fa-layer-group text-[#002f34] mr-3"></i>
                                <select 
                                    id="category_id" 
                                    name="category_id" 
                                    required
                                    onchange="toggleCustomCategory()"
                                    class="w-full text-sm font-semibold text-[#002f34] bg-transparent focus:outline-none cursor-pointer"
                                >
                                    <option value="" disabled <?= $selectedCategory === '' ? 'selected' : '' ?>>-- Pilih Kategori Barang / Jasa --</option>
                                    <?php foreach ($categories as $cat): ?>
                                        <?php $catIdStr = (string) $cat['id']; ?>
                                        <option value="<?= $catIdStr ?>" <?= $selectedCategory === $catIdStr ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?>
                                        </option>
                                    <?php endforeach; ?>
                                    <option value="other" <?= $selectedCategory === 'other' ? 'selected' : '' ?>>
                                        + Lainnya (Isi Kategori Sendiri)
                                    </option>
                                </select>
                            </div>
                        </div>

                        <!-- Input Kategori Baru -->
                        <div id="customCategoryWrapper" class="<?= $selectedCategory === 'other' ? 'block' : 'hidden' ?>">
                            <label for="custom_category" class="block text-xs font-bold uppercase tracking-wider text-[#002f34] mb-1.5">
                                Nama Kategori Baru <span class="text-rose-600">*</span>
                            </label>
                            <div class="olx-input-box flex items-center rounded px-3.5 h-12">
                                <i class="fa-solid fa-pen-to-square text-[#002f34] mr-3"></i>
                                <input 
                                    type="text" 
                                    id="custom_category" 
                                    name="custom_category" 
                                    value="<?= htmlspecialchars($customCategory, ENT_QUOTES, 'UTF-8') ?>"
                                    maxlength="50"
                                    <?= $selectedCategory === 'other' ? 'required' : '' ?>
                                    placeholder="Contoh: Alat Musik, Fashion Pria, Kamera..."
                                    class="w-full text-sm text-[#002f34] placeholder-gray-400 focus:outline-none"
                                >
                            </div>
                            <p class="mt-1 text-[11px] text-[#5c7a7d]">
                                Kategori baru akan otomatis tersimpan ke daftar kategori KF-OLX (maks. 50 karakter).
                            </p>
                        </div>
                    </div>
                </div>

                <!-- BAGIAN 2: DETAIL IKLAN (ads.title & ads.description) -->
                <div class="p-6 sm:p-8 border-b border-gray-200 space-y-5">
                    <h2 class="text-base sm:text-lg font-extrabold text-[#002f34] uppercase">
                        2. Sertakan Beberapa Detail
                    </h2>

                    <!-- Judul Iklan -->
                    <div>
                        <label for="title" class="block text-xs font-bold uppercase tracking-wider text-[#002f34] mb-1.5">
                            Judul Iklan <span class="text-rose-600">*</span>
                        </label>
                        <div class="olx-input-box flex items-center rounded px-3.5 h-12">
                            <input 
                                type="text" 
                                id="title" 
                                name="title" 
                                value="<?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>"
                                maxlength="150"
                                required
                                oninput="updateCharCount('title', 'titleCount', 150)"
                                placeholder="Contoh: Toyota Camry (2020) Tipe V AT Mulus"
                                class="w-full text-sm text-[#002f34] placeholder-gray-400 focus:outline-none"
                            >
                        </div>
                        <div class="flex items-center justify-between mt-1.5 text-[11px] text-[#5c7a7d]">
                            <span>Sebutkan merek, tipe, dan kondisi utama barang</span>
                            <span id="titleCount"><?= mb_strlen($title) ?> / 150</span>
                        </div>
                    </div>

                    <!-- Deskripsi Iklan -->
                    <div>
                        <label for="description" class="block text-xs font-bold uppercase tracking-wider text-[#002f34] mb-1.5">
                            Deskripsi <span class="text-rose-600">*</span>
                        </label>
                        <div class="olx-input-box rounded p-3.5">
                            <textarea 
                                id="description" 
                                name="description" 
                                rows="5"
                                maxlength="4096"
                                required
                                oninput="updateCharCount('description', 'descCount', 4096)"
                                placeholder="Jelaskan kondisi barang, kelengkapan, alasan dijual, dan detail penting lainnya..."
                                class="w-full text-sm text-[#002f34] placeholder-gray-400 focus:outline-none resize-y"
                            ><?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?></textarea>
                        </div>
                        <div class="flex items-center justify-between mt-1.5 text-[11px] text-[#5c7a7d]">
                            <span>Deskripsi yang jelas meningkatkan peluang barang cepat laku</span>
                            <span id="descCount"><?= mb_strlen($description) ?> / 4096</span>
                        </div>
                    </div>
                </div>

                <!-- BAGIAN 3: TENTUKAN HARGA (Otomatis Format Titik Ribuan 300.000.000) -->
                <div class="p-6 sm:p-8 border-b border-gray-200">
                    <h2 class="text-base sm:text-lg font-extrabold text-[#002f34] uppercase mb-4">
                        3. Tentukan Harga
                    </h2>

                    <div class="max-w-md">
                        <label for="price" class="block text-xs font-bold uppercase tracking-wider text-[#002f34] mb-1.5">
                            Harga (Rp) <span class="text-rose-600">*</span>
                        </label>
                        <div class="olx-input-box flex items-center rounded h-12 overflow-hidden">
                            <span class="px-3.5 h-full flex items-center bg-gray-100 border-r border-gray-300 text-sm font-extrabold text-[#002f34]">
                                Rp
                            </span>
                            <input 
                                type="text" 
                                inputmode="numeric"
                                id="price" 
                                name="price" 
                                value="<?= htmlspecialchars($priceDisplay, ENT_QUOTES, 'UTF-8') ?>"
                                maxlength="18"
                                required
                                oninput="formatCurrencyInput(this)"
                                placeholder="Contoh: 300.000.000"
                                class="w-full px-3.5 text-sm font-bold text-[#002f34] placeholder-gray-400 focus:outline-none"
                            >
                        </div>
                        <p class="mt-1.5 text-[11px] text-[#5c7a7d]">
                            Titik pemisah ribuan otomatis ditambahkan saat Anda mengetik angka.
                        </p>
                    </div>
                </div>

                <!-- BAGIAN 4: UNGGAH FOTO (Klik atau Seret & Lepas / Drag & Drop) -->
                <div class="p-6 sm:p-8 border-b border-gray-200">
                    <div class="flex items-center justify-between mb-1">
                        <h2 class="text-base sm:text-lg font-extrabold text-[#002f34] uppercase">
                            4. Unggah Foto <span class="text-rose-600">*</span>
                        </h2>
                        <span id="photoCounter" class="text-xs font-bold text-[#5c7a7d]">0 / 6 Foto</span>
                    </div>
                    <p class="text-xs text-[#5c7a7d] mb-4">
                        Foto urutan pertama otomatis menjadi sampul utama iklan Anda.
                    </p>

                    <!-- Input File Hidden (Multiple) -->
                    <input 
                        type="file" 
                        id="imagesInput" 
                        name="images[]" 
                        accept="image/jpeg,image/png,image/webp" 
                        multiple 
                        class="hidden"
                    >

                    <!-- Area Dropzone Klik / Seret & Lepas -->
                    <div 
                        id="dropzone" 
                        class="border-2 border-dashed border-gray-300 hover:border-[#002f34] bg-[#f7f8f9] hover:bg-[#ebf1ff]/40 rounded-lg p-8 text-center transition cursor-pointer select-none"
                    >
                        <div class="w-14 h-14 mx-auto mb-3 rounded-full bg-gray-200/80 text-[#5c7a7d] flex items-center justify-center">
                            <i class="fa-solid fa-cloud-arrow-up text-2xl text-[#002f34]"></i>
                        </div>
                        <p class="text-sm font-bold text-[#002f34]">
                            Klik untuk memilih atau seret dan lepas foto di sini
                        </p>
                        <p class="mt-1 text-xs text-[#5c7a7d]">
                            Ukuran maksimal 5MB per foto (JPG, PNG, JPEG, WEBP) &bull; Maksimal 6 foto
                        </p>
                    </div>

                    <!-- Pesan Peringatan Client-side Upload -->
                    <div id="uploadWarning" class="hidden mt-3 p-3 rounded bg-amber-50 border border-amber-200 text-amber-800 text-xs flex items-center gap-2">
                        <i class="fa-solid fa-triangle-exclamation shrink-0"></i>
                        <span id="uploadWarningText"></span>
                    </div>

                    <!-- Grid Preview Foto Terpilih -->
                    <div id="previewGrid" class="hidden grid-cols-2 sm:grid-cols-3 gap-3.5 mt-4"></div>
                </div>

                <!-- BAGIAN 5: KONFIRMASI LOKASI & ALAMAT LENGKAP (ads.location) -->
                <div class="p-6 sm:p-8 border-b border-gray-200 space-y-4">
                    <div>
                        <h2 class="text-base sm:text-lg font-extrabold text-[#002f34] uppercase mb-1">
                            5. Konfirmasi Lokasi &amp; Alamat Barang
                        </h2>
                        <p class="text-xs text-[#5c7a7d]">
                            Isi Kota/Kabupaten (wajib), dan tambahkan alamat lengkap jalan/kecamatan jika ingin lebih rinci
                        </p>
                    </div>

                    <div class="max-w-lg space-y-4">
                        <!-- Kota / Kabupaten (Wajib) -->
                        <div>
                            <label for="city" class="block text-xs font-bold uppercase tracking-wider text-[#002f34] mb-1.5">
                                Kota / Kabupaten <span class="text-rose-600">*</span>
                            </label>
                            <div class="olx-input-box flex items-center rounded px-3.5 h-12">
                                <i class="fa-solid fa-location-dot text-[#002f34] mr-3"></i>
                                <input 
                                    type="text" 
                                    id="city" 
                                    name="city" 
                                    value="<?= htmlspecialchars($city, ENT_QUOTES, 'UTF-8') ?>"
                                    list="locationList"
                                    maxlength="50"
                                    required
                                    placeholder="Ketik atau pilih kota (Contoh: Surabaya, Jawa Timur)"
                                    class="w-full text-sm text-[#002f34] placeholder-gray-400 focus:outline-none"
                                >
                                <datalist id="locationList">
                                    <option value="Surabaya, Jawa Timur">
                                    <option value="Sidoarjo, Jawa Timur">
                                    <option value="Gresik, Jawa Timur">
                                    <option value="Malang Kota, Jawa Timur">
                                    <option value="Kabupaten Malang, Jawa Timur">
                                    <option value="Batu, Jawa Timur">
                                    <option value="Jakarta Timur">
                                    <option value="Jakarta Selatan">
                                    <option value="Bandung, Jawa Barat">
                                    <option value="Yogyakarta, DI Yogyakarta">
                                </datalist>
                            </div>
                        </div>

                        <!-- Alamat Lengkap / Kecamatan / Patokan (Opsional) -->
                        <div>
                            <label for="address_detail" class="block text-xs font-bold uppercase tracking-wider text-[#002f34] mb-1.5">
                                Alamat Lengkap / Kecamatan / Patokan <span class="text-[#5c7a7d] font-normal lowercase">(opsional)</span>
                            </label>
                            <div class="olx-input-box flex items-center rounded px-3.5 h-12">
                                <i class="fa-solid fa-map-pin text-[#5c7a7d] mr-3"></i>
                                <input 
                                    type="text" 
                                    id="address_detail" 
                                    name="address_detail" 
                                    value="<?= htmlspecialchars($addressDetail, ENT_QUOTES, 'UTF-8') ?>"
                                    maxlength="60"
                                    placeholder="Contoh: Jl. Dharmawangsa No. 12, Gubeng"
                                    class="w-full text-sm text-[#002f34] placeholder-gray-400 focus:outline-none"
                                >
                            </div>
                            <p class="mt-1 text-[11px] text-[#5c7a7d]">
                                Jika tidak tahu atau demi privasi, kolom ini boleh dikosongkan.
                            </p>
                        </div>
                    </div>
                </div>

                <!-- BAGIAN 6: TINJAU DETAIL PENJUAL -->
                <div class="p-6 sm:p-8 border-b border-gray-200 bg-[#f7f8f9]/60">
                    <h2 class="text-base sm:text-lg font-extrabold text-[#002f34] uppercase mb-4">
                        6. Tinjau Detail Akun Anda
                    </h2>

                    <div class="flex items-center gap-4 max-w-md bg-white border border-gray-200 rounded-lg p-4">
                        <div class="w-14 h-14 rounded-full bg-[#002f34] text-[#23e5db] font-extrabold text-xl flex items-center justify-center shrink-0">
                            <?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?>
                        </div>
                        <div class="min-w-0 flex-1 space-y-1">
                            <p class="text-xs text-[#5c7a7d]">Nama Penjual</p>
                            <p class="text-sm font-bold text-[#002f34] truncate">
                                <?= htmlspecialchars($currentUser['name'], ENT_QUOTES, 'UTF-8') ?>
                            </p>
                            <p class="text-xs text-[#5c7a7d] truncate">
                                <i class="fa-regular fa-envelope mr-1"></i><?= htmlspecialchars($currentUser['email'], ENT_QUOTES, 'UTF-8') ?>
                            </p>
                            <p class="text-xs text-[#5c7a7d] truncate">
                                <i class="fa-brands fa-whatsapp text-emerald-600 mr-1"></i><?= htmlspecialchars(formatWhatsAppDisplay($currentUser['whatsapp'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- TOMBOL SUBMIT PASANG IKLAN -->
                <div class="p-6 sm:p-8 bg-white flex flex-col sm:flex-row items-center justify-between gap-4">
                    <p class="text-xs text-[#5c7a7d]">
                        Pastikan semua informasi iklan sudah benar dan sesuai aturan KF-OLX.
                    </p>
                    <button type="submit" class="w-full sm:w-auto bg-[#002f34] hover:bg-[#00454d] text-white font-bold text-sm px-8 py-3.5 rounded transition cursor-pointer shrink-0 shadow-xs">
                        Pasang Iklan Sekarang
                    </button>
                </div>

            </form>

        </div>
    </main>

    <!-- Bottom Bar Sederhana -->
    <footer class="bg-[#002f34] text-white text-xs py-4 mt-8">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 flex flex-col sm:flex-row items-center justify-between gap-2">
            <p>Iklan Baris Gratis di Indonesia</p>
            <p>&copy; <?= date('Y') ?> <strong>KF-OLX</strong>. Hak Cipta Dilindungi.</p>
        </div>
    </footer>

    <script>
        // 1. Format Otomatis Angka Harga dengan Pemisah Titik Ribuan (contoh: 300.000.000)
        function formatCurrencyInput(input) {
            let digits = input.value.replace(/\D/g, '');
            digits = digits.replace(/^0+(?=\d)/, '');
            input.value = digits.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        }

        // 2. Toggle Input Kategori Kustom
        function toggleCustomCategory() {
            const select = document.getElementById('category_id');
            const wrapper = document.getElementById('customCategoryWrapper');
            const input = document.getElementById('custom_category');

            if (select.value === 'other') {
                wrapper.classList.remove('hidden');
                input.required = true;
                input.focus();
            } else {
                wrapper.classList.add('hidden');
                input.required = false;
                input.value = '';
            }
        }

        // 3. Penghitung Karakter
        function updateCharCount(inputId, counterId, max) {
            const length = document.getElementById(inputId).value.length;
            document.getElementById(counterId).textContent = length + ' / ' + max;
        }

        // 4. Drag & Drop + Klik Multi-Upload Foto (Maksimal 6 Foto, 5MB/Foto)
        const dropzone = document.getElementById('dropzone');
        const imagesInput = document.getElementById('imagesInput');
        const previewGrid = document.getElementById('previewGrid');
        const photoCounter = document.getElementById('photoCounter');
        const uploadWarning = document.getElementById('uploadWarning');
        const uploadWarningText = document.getElementById('uploadWarningText');

        const MAX_FILES = 6;
        const MAX_FILE_SIZE = 5 * 1024 * 1024; // 5MB
        const ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
        let filesBuffer = new DataTransfer();

        function showUploadWarning(msg) {
            if (!msg) {
                uploadWarning.classList.add('hidden');
                uploadWarningText.textContent = '';
            } else {
                uploadWarningText.textContent = msg;
                uploadWarning.classList.remove('hidden');
            }
        }

        // Klik kotak dropzone untuk membuka dialog pilih file
        dropzone.addEventListener('click', () => {
            imagesInput.click();
        });

        // Efek visual saat file diseret di atas dropzone
        ['dragenter', 'dragover'].forEach(eventName => {
            dropzone.addEventListener(eventName, (e) => {
                e.preventDefault();
                e.stopPropagation();
                dropzone.classList.add('border-[#002f34]', 'bg-[#ebf1ff]/70');
            });
        });

        ['dragleave', 'drop'].forEach(eventName => {
            dropzone.addEventListener(eventName, (e) => {
                e.preventDefault();
                e.stopPropagation();
                dropzone.classList.remove('border-[#002f34]', 'bg-[#ebf1ff]/70');
            });
        });

        // Tangkap file yang dilepas (drop)
        dropzone.addEventListener('drop', (e) => {
            const droppedFiles = e.dataTransfer.files;
            if (droppedFiles && droppedFiles.length > 0) {
                addFilesToBuffer(droppedFiles);
            }
        });

        // Tangkap file yang dipilih lewat dialog klik
        imagesInput.addEventListener('change', (e) => {
            if (e.target.files && e.target.files.length > 0) {
                addFilesToBuffer(e.target.files);
            }
        });

        function addFilesToBuffer(newFiles) {
            let warningMsg = '';

            for (let i = 0; i < newFiles.length; i++) {
                const file = newFiles[i];

                if (filesBuffer.items.length >= MAX_FILES) {
                    warningMsg = 'Maksimal hanya 6 foto yang dapat diunggah.';
                    break;
                }

                if (!ALLOWED_TYPES.includes(file.type)) {
                    warningMsg = 'Beberapa file dilewati karena format harus JPG, PNG, atau WEBP.';
                    continue;
                }

                if (file.size > MAX_FILE_SIZE) {
                    warningMsg = `File "${file.name}" melebihi batas maksimal 5MB.`;
                    continue;
                }

                filesBuffer.items.add(file);
            }

            imagesInput.files = filesBuffer.files;
            showUploadWarning(warningMsg);
            renderPhotoPreviews();
        }

        function removePhotoAt(index) {
            const updatedBuffer = new DataTransfer();
            for (let i = 0; i < filesBuffer.files.length; i++) {
                if (i !== index) {
                    updatedBuffer.items.add(filesBuffer.files[i]);
                }
            }
            filesBuffer = updatedBuffer;
            imagesInput.files = filesBuffer.files;
            showUploadWarning('');
            renderPhotoPreviews();
        }

        function renderPhotoPreviews() {
            const total = filesBuffer.files.length;
            photoCounter.textContent = `${total} / ${MAX_FILES} Foto`;
            previewGrid.innerHTML = '';

            if (total === 0) {
                previewGrid.classList.add('hidden');
                previewGrid.classList.remove('grid');
                return;
            }

            previewGrid.classList.remove('hidden');
            previewGrid.classList.add('grid');

            Array.from(filesBuffer.files).forEach((file, idx) => {
                const card = document.createElement('div');
                card.className = 'relative aspect-[4/3] rounded-lg overflow-hidden border-2 border-[#002f34] bg-gray-100 group';

                const img = document.createElement('img');
                img.className = 'w-full h-full object-cover';
                img.alt = file.name;

                const reader = new FileReader();
                reader.onload = (e) => {
                    img.src = e.target.result;
                };
                reader.readAsDataURL(file);

                card.appendChild(img);

                if (idx === 0) {
                    const badge = document.createElement('span');
                    badge.className = 'absolute bottom-2 left-2 bg-[#ffce32] text-[#002f34] text-[10px] font-extrabold uppercase px-2 py-0.5 rounded shadow-2xs';
                    badge.textContent = 'Sampul';
                    card.appendChild(badge);
                }

                const removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.title = 'Hapus Foto';
                removeBtn.className = 'absolute top-2 right-2 w-7 h-7 rounded-full bg-black/75 hover:bg-rose-600 text-white text-xs flex items-center justify-center transition cursor-pointer';
                removeBtn.innerHTML = '<i class="fa-solid fa-xmark"></i>';
                removeBtn.onclick = (e) => {
                    e.stopPropagation();
                    removePhotoAt(idx);
                };

                card.appendChild(removeBtn);
                previewGrid.appendChild(card);
            });
        }
    </script>

</body>
</html>