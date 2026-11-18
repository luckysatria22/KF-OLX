<?php
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    $_SESSION['flash_success'] = 'Silakan masuk terlebih dahulu untuk mengedit iklan.';
    header('Location: login.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$userId = (int) $_SESSION['user_id'];
$adId   = isset($_GET['id']) ? (int) $_GET['id'] : (int) ($_POST['ad_id'] ?? 0);

// 1. Pastikan iklan ada dan benar-benar milik user yang sedang login
$stmtAd = $pdo->prepare('
    SELECT id, user_id, category_id, title, description, price, location
    FROM ads
    WHERE id = :id AND user_id = :user_id
    LIMIT 1
');
$stmtAd->execute([
    ':id'      => $adId,
    ':user_id' => $userId,
]);
$ad = $stmtAd->fetch();

if (!$ad) {
    $_SESSION['flash_error'] = 'Iklan tidak ditemukan atau Anda tidak memiliki izin untuk mengeditnya.';
    header('Location: iklan-saya.php');
    exit;
}

// 2. Ambil daftar kategori dan foto lama iklan
$stmtCat    = $pdo->query('SELECT id, name, icon FROM categories ORDER BY name ASC');
$categories = $stmtCat->fetchAll();

$stmtImages = $pdo->prepare('SELECT id, image_path FROM ad_images WHERE ad_id = :ad_id ORDER BY id ASC');
$stmtImages->execute([':ad_id' => $adId]);
$existingImages = $stmtImages->fetchAll();

$errors           = [];
$selectedCategory = (string) $ad['category_id'];
$customCategory   = '';
$title            = $ad['title'];
$description      = $ad['description'];
$priceDisplay     = number_format((float) $ad['price'], 0, ',', '.');
$location         = $ad['location'];

// 3. Proses Simpan Perubahan
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
        $errors[] = 'Sesi formulir tidak valid. Silakan muat ulang halaman dan coba lagi.';
    }

    $selectedCategory = trim($_POST['category_id'] ?? '');
    $customCategory   = trim($_POST['custom_category'] ?? '');
    $title            = trim($_POST['title'] ?? '');
    $description      = trim($_POST['description'] ?? '');
    $priceRaw         = trim($_POST['price'] ?? '');
    $location         = trim($_POST['location'] ?? '');
    $deleteImageIds   = array_map('intval', $_POST['delete_images'] ?? []);

    // Validasi Kategori
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

    // Validasi Judul
    if ($title === '') {
        $errors[] = 'Judul iklan wajib diisi.';
    } elseif (mb_strlen($title) > 150) {
        $errors[] = 'Judul iklan maksimal 150 karakter.';
    }

    // Validasi Deskripsi
    if ($description === '') {
        $errors[] = 'Deskripsi iklan wajib diisi.';
    } elseif (mb_strlen($description) > 4096) {
        $errors[] = 'Deskripsi iklan maksimal 4096 karakter.';
    }

    // Validasi Harga
    $priceDigits = preg_replace('/[^0-9]/', '', $priceRaw);
    if ($priceDigits === '') {
        $errors[] = 'Masukkan nominal harga yang valid.';
    } elseif ((float) $priceDigits > 9999999999999.99) {
        $errors[] = 'Nominal harga melebihi batas maksimal.';
    } else {
        $price        = number_format((float) $priceDigits, 2, '.', '');
        $priceDisplay = number_format((float) $priceDigits, 0, ',', '.');
    }

    // Validasi Lokasi
    if ($location === '') {
        $errors[] = 'Lokasi / alamat iklan wajib diisi.';
    } elseif (mb_strlen($location) > 100) {
        $errors[] = 'Lokasi / alamat maksimal 100 karakter.';
    }

    // Hitung sisa foto lama yang tidak dihapus
    $validExistingIds   = array_map('intval', array_column($existingImages, 'id'));
    $validDeleteIds     = array_values(array_intersect($deleteImageIds, $validExistingIds));
    $remainingOldPhotos = count($existingImages) - count($validDeleteIds);

    // Validasi File Foto Baru (jika ada)
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
                $errors[] = 'Salah satu foto baru gagal diunggah (kode error: ' . $fileError . ').';
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

    $finalPhotoCount = $remainingOldPhotos + count($uploadedFiles);
    if ($finalPhotoCount < 1) {
        $errors[] = 'Iklan wajib memiliki minimal 1 foto. Unggah foto baru jika ingin menghapus semua foto lama.';
    } elseif ($finalPhotoCount > 6) {
        $errors[] = 'Total foto iklan maksimal 6 foto (saat ini akan menjadi ' . $finalPhotoCount . ' foto).';
    }

    // 4. Update Database dalam Satu Transaksi
    if (empty($errors)) {
        $savedDiskPaths = [];
        $filesToUnlink  = [];

        try {
            $uploadDir = __DIR__ . '/uploads/ads/';
            if (!is_dir($uploadDir)) {
                if (!mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
                    throw new RuntimeException('Direktori uploads/ads gagal dibuat.');
                }
            }

            $pdo->beginTransaction();

            // Tentukan category_id
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

            // Update tabel ads
            $stmtUpdateAd = $pdo->prepare('
                UPDATE ads
                SET category_id = :category_id,
                    title       = :title,
                    description = :description,
                    price       = :price,
                    location    = :location
                WHERE id = :id AND user_id = :user_id
            ');
            $stmtUpdateAd->execute([
                ':category_id' => $finalCategoryId,
                ':title'       => $title,
                ':description' => $description,
                ':price'       => $price,
                ':location'    => $location,
                ':id'          => $adId,
                ':user_id'     => $userId,
            ]);

            // Hapus foto lama yang dicentang
            if (!empty($validDeleteIds)) {
                $stmtDelImg = $pdo->prepare('DELETE FROM ad_images WHERE id = :img_id AND ad_id = :ad_id');
                foreach ($existingImages as $img) {
                    if (in_array((int) $img['id'], $validDeleteIds, true)) {
                        $stmtDelImg->execute([
                            ':img_id' => (int) $img['id'],
                            ':ad_id'  => $adId,
                        ]);
                        $filesToUnlink[] = __DIR__ . '/' . $img['image_path'];
                    }
                }
            }

            // Simpan foto baru jika ada
            if (!empty($uploadedFiles)) {
                $stmtInsertImg = $pdo->prepare('
                    INSERT INTO ad_images (ad_id, image_path)
                    VALUES (:ad_id, :image_path)
                ');

                foreach ($uploadedFiles as $file) {
                    $randomName   = bin2hex(random_bytes(16)) . '.' . $file['ext'];
                    $fullDiskPath = $uploadDir . $randomName;
                    $dbImagePath  = 'uploads/ads/' . $randomName;

                    if (!move_uploaded_file($file['tmp_name'], $fullDiskPath)) {
                        throw new RuntimeException('Gagal memindahkan file gambar baru ke server.');
                    }

                    $savedDiskPaths[] = $fullDiskPath;

                    $stmtInsertImg->execute([
                        ':ad_id'      => $adId,
                        ':image_path' => $dbImagePath,
                    ]);
                }
            }

            $pdo->commit();

            // Hapus file fisik foto lama yang sudah dihapus dari database
            foreach ($filesToUnlink as $oldDiskPath) {
                if (is_file($oldDiskPath)) {
                    @unlink($oldDiskPath);
                }
            }

            $_SESSION['csrf_token']    = bin2hex(random_bytes(32));
            $_SESSION['flash_success'] = 'Perubahan iklan berhasil disimpan!';

            header('Location: detail.php?id=' . $adId);
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

            error_log('Edit Ad Error: ' . $e->getMessage());
            $errors[] = 'Gagal memperbarui iklan. Silakan coba beberapa saat lagi.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Iklan - KF-OLX</title>
    
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
<body class="min-h-screen flex flex-col bg-[#f7f8f9]">

    <!-- Header Minimal -->
    <header class="sticky top-0 z-50 bg-[#f7f8f9] border-b border-gray-200 shadow-2xs">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
            <div class="flex items-center gap-4">
                <a href="iklan-saya.php" class="w-9 h-9 rounded-full hover:bg-gray-200/70 text-[#002f34] flex items-center justify-center transition" title="Kembali ke Iklan Saya">
                    <i class="fa-solid fa-arrow-left text-lg"></i>
                </a>
                <a href="index.php" class="text-2xl font-extrabold tracking-tighter text-[#002f34] select-none">
                    KF<span class="text-[#23e5db]">-</span>OLX
                </a>
            </div>
            <span class="text-xs sm:text-sm font-bold text-[#5c7a7d]">
                Edit Iklan #<?= $adId ?>
            </span>
        </div>
    </header>

    <!-- Konten Utama Form Edit -->
    <main class="flex-1 py-8 px-4 sm:px-6 lg:px-8">
        <div class="max-w-3xl mx-auto">

            <h1 class="text-xl sm:text-2xl font-extrabold text-[#002f34] text-center uppercase tracking-tight mb-6">
                Perbarui Detail Iklan
            </h1>

            <?php if (!empty($errors)): ?>
                <div class="mb-6 p-4 rounded-lg bg-rose-50 border border-rose-200 text-rose-700 text-xs sm:text-sm shadow-2xs">
                    <div class="flex items-center gap-2 font-bold mb-1.5">
                        <i class="fa-solid fa-circle-exclamation"></i>
                        <span>Gagal Menyimpan Perubahan:</span>
                    </div>
                    <ul class="list-disc pl-5 space-y-1">
                        <?php foreach ($errors as $error): ?>
                            <li><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form action="" method="POST" enctype="multipart/form-data" class="bg-white border border-gray-200 rounded-lg shadow-2xs overflow-hidden">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="ad_id" value="<?= $adId ?>">
                
                <div class="h-1.5 w-full bg-gradient-to-r from-[#ffce32] via-[#23e5db] to-[#3a77ff]"></div>

                <!-- BAGIAN 1: KATEGORI -->
                <div class="p-6 sm:p-8 border-b border-gray-200">
                    <h2 class="text-base sm:text-lg font-extrabold text-[#002f34] uppercase mb-4">
                        1. Kategori Iklan
                    </h2>

                    <div class="max-w-md space-y-4">
                        <div>
                            <label for="category_id" class="block text-xs font-bold uppercase tracking-wider text-[#002f34] mb-1.5">
                                Pilih Kategori <span class="text-rose-600">*</span>
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
                                    placeholder="Ketik nama kategori baru..."
                                    class="w-full text-sm text-[#002f34] placeholder-gray-400 focus:outline-none"
                                >
                            </div>
                        </div>
                    </div>
                </div>

                <!-- BAGIAN 2: JUDUL & DESKRIPSI -->
                <div class="p-6 sm:p-8 border-b border-gray-200 space-y-5">
                    <h2 class="text-base sm:text-lg font-extrabold text-[#002f34] uppercase">
                        2. Detail Iklan
                    </h2>

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
                                class="w-full text-sm text-[#002f34] focus:outline-none"
                            >
                        </div>
                        <div class="flex justify-end mt-1.5 text-[11px] text-[#5c7a7d]">
                            <span id="titleCount"><?= mb_strlen($title) ?> / 150</span>
                        </div>
                    </div>

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
                                class="w-full text-sm text-[#002f34] focus:outline-none resize-y"
                            ><?= htmlspecialchars($description, ENT_QUOTES, 'UTF-8') ?></textarea>
                        </div>
                        <div class="flex justify-end mt-1.5 text-[11px] text-[#5c7a7d]">
                            <span id="descCount"><?= mb_strlen($description) ?> / 4096</span>
                        </div>
                    </div>
                </div>

                <!-- BAGIAN 3: HARGA -->
                <div class="p-6 sm:p-8 border-b border-gray-200">
                    <h2 class="text-base sm:text-lg font-extrabold text-[#002f34] uppercase mb-4">
                        3. Harga
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
                                class="w-full px-3.5 text-sm font-bold text-[#002f34] focus:outline-none"
                            >
                        </div>
                    </div>
                </div>

                <!-- BAGIAN 4: KELOLA FOTO IKLAN -->
                <div class="p-6 sm:p-8 border-b border-gray-200 space-y-5">
                    <div>
                        <h2 class="text-base sm:text-lg font-extrabold text-[#002f34] uppercase mb-1">
                            4. Kelola Foto Iklan
                        </h2>
                        <p class="text-xs text-[#5c7a7d]">
                            Centang foto lama jika ingin menghapusnya, atau tambahkan foto baru (Total maksimal 6 foto).
                        </p>
                    </div>

                    <!-- Foto Saat Ini -->
                    <?php if (!empty($existingImages)): ?>
                        <div>
                            <p class="text-xs font-bold uppercase tracking-wider text-[#002f34] mb-2.5">
                                Foto Terpasang Saat Ini (<?= count($existingImages) ?>)
                            </p>
                            <div class="grid grid-cols-2 sm:grid-cols-3 gap-3.5">
                                <?php foreach ($existingImages as $idx => $img): ?>
                                    <label class="relative aspect-[4/3] rounded-lg overflow-hidden border-2 border-gray-200 bg-gray-100 cursor-pointer block group">
                                        <img 
                                            src="<?= htmlspecialchars($img['image_path'], ENT_QUOTES, 'UTF-8') ?>" 
                                            alt="Foto <?= $idx + 1 ?>" 
                                            class="w-full h-full object-cover transition group-hover:opacity-90"
                                        >
                                        <?php if ($idx === 0): ?>
                                            <span class="absolute bottom-2 left-2 bg-[#ffce32] text-[#002f34] text-[10px] font-extrabold uppercase px-2 py-0.5 rounded">
                                                Sampul
                                            </span>
                                        <?php endif; ?>

                                        <div class="absolute top-2 right-2 bg-white/95 px-2.5 py-1 rounded shadow-xs flex items-center gap-1.5 text-xs font-bold text-rose-600">
                                            <input 
                                                type="checkbox" 
                                                name="delete_images[]" 
                                                value="<?= (int) $img['id'] ?>" 
                                                class="delete-old-checkbox rounded text-rose-600 focus:ring-rose-500 cursor-pointer"
                                                onchange="recalculateRemainingSlots()"
                                            >
                                            <span>Hapus</span>
                                        </div>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Dropzone Tambah Foto Baru -->
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-xs font-bold uppercase tracking-wider text-[#002f34]">
                                Tambah Foto Baru (Opsional)
                            </span>
                            <span id="photoCounter" class="text-xs font-bold text-[#5c7a7d]">
                                Sisa slot: <?= max(0, 6 - count($existingImages)) ?> foto
                            </span>
                        </div>

                        <input 
                            type="file" 
                            id="imagesInput" 
                            name="images[]" 
                            accept="image/jpeg,image/png,image/webp" 
                            multiple 
                            class="hidden"
                        >

                        <div 
                            id="dropzone" 
                            class="border-2 border-dashed border-gray-300 hover:border-[#002f34] bg-[#f7f8f9] hover:bg-[#ebf1ff]/40 rounded-lg p-6 text-center transition cursor-pointer select-none"
                        >
                            <i class="fa-solid fa-cloud-arrow-up text-2xl text-[#002f34] mb-2"></i>
                            <p class="text-xs sm:text-sm font-bold text-[#002f34]">
                                Klik untuk memilih atau seret foto baru ke sini
                            </p>
                            <p class="mt-1 text-[11px] text-[#5c7a7d]">
                                Maksimal 5MB per foto (JPG, PNG, WEBP)
                            </p>
                        </div>

                        <div id="uploadWarning" class="hidden mt-3 p-3 rounded bg-amber-50 border border-amber-200 text-amber-800 text-xs flex items-center gap-2">
                            <i class="fa-solid fa-triangle-exclamation shrink-0"></i>
                            <span id="uploadWarningText"></span>
                        </div>

                        <div id="previewGrid" class="hidden grid-cols-2 sm:grid-cols-3 gap-3.5 mt-4"></div>
                    </div>
                </div>

                <!-- BAGIAN 5: LOKASI -->
                <div class="p-6 sm:p-8 border-b border-gray-200">
                    <h2 class="text-base sm:text-lg font-extrabold text-[#002f34] uppercase mb-4">
                        5. Lokasi &amp; Alamat Barang
                    </h2>

                    <div class="max-w-lg">
                        <label for="location" class="block text-xs font-bold uppercase tracking-wider text-[#002f34] mb-1.5">
                            Lokasi / Alamat Lengkap <span class="text-rose-600">*</span>
                        </label>
                        <div class="olx-input-box flex items-center rounded px-3.5 h-12">
                            <i class="fa-solid fa-location-dot text-[#002f34] mr-3"></i>
                            <input 
                                type="text" 
                                id="location" 
                                name="location" 
                                value="<?= htmlspecialchars($location, ENT_QUOTES, 'UTF-8') ?>"
                                maxlength="100"
                                required
                                class="w-full text-sm text-[#002f34] focus:outline-none"
                            >
                        </div>
                    </div>
                </div>

                <!-- TOMBOL SIMPAN & BATAL -->
                <div class="p-6 sm:p-8 bg-white flex flex-col sm:flex-row items-center justify-end gap-3">
                    <a href="iklan-saya.php" class="w-full sm:w-auto text-center px-6 py-3 rounded border border-gray-300 hover:border-[#002f34] text-sm font-bold text-[#002f34] transition">
                        Batal
                    </a>
                    <button type="submit" class="w-full sm:w-auto bg-[#002f34] hover:bg-[#00454d] text-white font-bold text-sm px-8 py-3 rounded transition cursor-pointer shadow-xs">
                        Simpan Perubahan
                    </button>
                </div>

            </form>

        </div>
    </main>

    <!-- FOOTER -->
    <footer class="bg-[#002f34] text-white text-xs py-4 mt-8">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 flex flex-col sm:flex-row items-center justify-between gap-2">
            <p>Iklan Baris Gratis di Indonesia</p>
            <p>&copy; <?= date('Y') ?> <strong>KF-OLX</strong>. Hak Cipta Dilindungi.</p>
        </div>
    </footer>

    <script>
        function formatCurrencyInput(input) {
            let digits = input.value.replace(/\D/g, '');
            digits = digits.replace(/^0+(?=\d)/, '');
            input.value = digits.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        }

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

        function updateCharCount(inputId, counterId, max) {
            const length = document.getElementById(inputId).value.length;
            document.getElementById(counterId).textContent = length + ' / ' + max;
        }

        // Manajemen Slot Foto Baru (Maksimal Total = 6)
        const existingPhotoTotal = <?= count($existingImages) ?>;
        const dropzone = document.getElementById('dropzone');
        const imagesInput = document.getElementById('imagesInput');
        const previewGrid = document.getElementById('previewGrid');
        const photoCounter = document.getElementById('photoCounter');
        const uploadWarning = document.getElementById('uploadWarning');
        const uploadWarningText = document.getElementById('uploadWarningText');

        const MAX_TOTAL_PHOTOS = 6;
        const MAX_FILE_SIZE = 5 * 1024 * 1024;
        const ALLOWED_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
        let filesBuffer = new DataTransfer();

        function getAvailableSlots() {
            const checkedDeletes = document.querySelectorAll('.delete-old-checkbox:checked').length;
            const keptOld = existingPhotoTotal - checkedDeletes;
            return Math.max(0, MAX_TOTAL_PHOTOS - keptOld);
        }

        function recalculateRemainingSlots() {
            const available = getAvailableSlots();
            const remaining = Math.max(0, available - filesBuffer.files.length);
            photoCounter.textContent = `Sisa slot: ${remaining} foto`;
        }

        function showUploadWarning(msg) {
            if (!msg) {
                uploadWarning.classList.add('hidden');
                uploadWarningText.textContent = '';
            } else {
                uploadWarningText.textContent = msg;
                uploadWarning.classList.remove('hidden');
            }
        }

        dropzone.addEventListener('click', () => imagesInput.click());

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

        dropzone.addEventListener('drop', (e) => {
            if (e.dataTransfer.files && e.dataTransfer.files.length > 0) {
                addFilesToBuffer(e.dataTransfer.files);
            }
        });

        imagesInput.addEventListener('change', (e) => {
            if (e.target.files && e.target.files.length > 0) {
                addFilesToBuffer(e.target.files);
            }
        });

        function addFilesToBuffer(newFiles) {
            let warningMsg = '';
            const maxAllowedNew = getAvailableSlots();

            for (let i = 0; i < newFiles.length; i++) {
                const file = newFiles[i];

                if (filesBuffer.items.length >= maxAllowedNew) {
                    warningMsg = `Total foto maksimal 6. Sisa slot tersedia saat ini hanya ${maxAllowedNew} foto.`;
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
            recalculateRemainingSlots();
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
            recalculateRemainingSlots();
            renderPhotoPreviews();
        }

        function renderPhotoPreviews() {
            const total = filesBuffer.files.length;
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
                card.className = 'relative aspect-[4/3] rounded-lg overflow-hidden border-2 border-[#002f34] bg-gray-100';

                const img = document.createElement('img');
                img.className = 'w-full h-full object-cover';
                img.alt = file.name;

                const reader = new FileReader();
                reader.onload = (e) => { img.src = e.target.result; };
                reader.readAsDataURL(file);

                card.appendChild(img);

                const badge = document.createElement('span');
                badge.className = 'absolute bottom-2 left-2 bg-[#23e5db] text-[#002f34] text-[10px] font-extrabold uppercase px-2 py-0.5 rounded';
                badge.textContent = 'Baru';
                card.appendChild(badge);

                const removeBtn = document.createElement('button');
                removeBtn.type = 'button';
                removeBtn.title = 'Batalkan Foto';
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