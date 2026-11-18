<?php
require_once 'config.php';

date_default_timezone_set('Asia/Jakarta');

if (!isset($_SESSION['user_id'])) {$_SESSION['flash_success'] = 'Silakan masuk terlebih dahulu untuk melihat iklan Anda.';
    header('Location: login.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$userId       = (int)$_SESSION['user_id'];
$flashSuccess =$_SESSION['flash_success'] ?? null;
$flashError   =$_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'],$_SESSION['flash_error']);

function formatRupiah($price): string
{
    return 'Rp ' . number_format((float) $price, 0, ',', '.');
}

function formatTanggalSingkat(string $timestamp): string
{
    $dt = new DateTime($timestamp);$bulan = [
        1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun',
        'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'
    ];
    return $dt->format('d') . ' ' .$bulan[(int) $dt->format('n')] . ' ' .$dt->format('Y');
}

// Proses Hapus Iklan dari Halaman Iklan Saya
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_ad') {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {$_SESSION['flash_error'] = 'Sesi formulir tidak valid. Silakan coba lagi.';
        header('Location: iklan-saya.php');
        exit;
    }

    $deleteAdId = (int) ($_POST['ad_id'] ?? 0);

    try {
        $stmtOwner = $pdo->prepare('SELECT id FROM ads WHERE id = :id AND user_id = :user_id LIMIT 1');$stmtOwner->execute([
            ':id'      => $deleteAdId,
            ':user_id' => $userId,
        ]);

        if ($stmtOwner->fetch()) {
            $stmtImgs =$pdo->prepare('SELECT image_path FROM ad_images WHERE ad_id = :ad_id');
            $stmtImgs->execute([':ad_id' =>$deleteAdId]);
            $imgPaths =$stmtImgs->fetchAll(PDO::FETCH_COLUMN);

            $stmtDel = $pdo->prepare('DELETE FROM ads WHERE id = :id AND user_id = :user_id');$stmtDel->execute([
                ':id'      => $deleteAdId,
                ':user_id' => $userId,
            ]);

            foreach ($imgPaths as$relPath) {
                $fullPath = __DIR__ . '/' .$relPath;
                if (is_file($fullPath)) {
                    @unlink($fullPath);
                }
            }

            $_SESSION['csrf_token']    = bin2hex(random_bytes(32));$_SESSION['flash_success'] = 'Iklan berhasil dihapus secara permanen.';
        } else {
            $_SESSION['flash_error'] = 'Iklan tidak ditemukan atau Anda tidak memiliki akses.';
        }
    } catch (PDOException $e) {
        error_log('Delete My Ad Error: ' . $e->getMessage());$_SESSION['flash_error'] = 'Terjadi kesalahan saat menghapus iklan.';
    }

    header('Location: iklan-saya.php');
    exit;
}

// Ambil semua iklan milik user yang sedang login
$stmtMyAds =$pdo->prepare('
    SELECT 
        a.id,
        a.title,
        a.price,
        a.location,
        a.created_at,
        c.name AS category_name,
        img.image_path AS cover_image,
        (SELECT COUNT(*) FROM ad_images WHERE ad_id = a.id) AS total_images
    FROM ads a
    JOIN categories c ON a.category_id = c.id
    LEFT JOIN LATERAL (
        SELECT image_path
        FROM ad_images
        WHERE ad_id = a.id
        ORDER BY id ASC
        LIMIT 1
    ) img ON true
    WHERE a.user_id = :user_id
    ORDER BY a.created_at DESC, a.id DESC
');
$stmtMyAds->execute([':user_id' =>$userId]);
$myAds =$stmtMyAds->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iklan Saya - KF-OLX</title>
    
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
                    <a href="iklan-saya.php" class="font-extrabold text-[#002f34] underline decoration-2 underline-offset-4">Iklan Saya</a>
                </nav>
            </div>

            <div class="flex items-center gap-3 sm:gap-5">
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

                <a href="pasang-iklan.php" class="inline-flex items-center gap-1.5 bg-white hover:bg-gray-50 text-[#002f34] font-extrabold text-xs sm:text-sm px-4 sm:px-5 py-2 rounded-full border-4 border-t-[#23e5db] border-r-[#3a77ff] border-b-[#ffce32] border-l-[#ffce32] shadow-xs transition">
                    <i class="fa-solid fa-plus"></i>
                    <span>JUAL</span>
                </a>
            </div>
        </div>
    </header>

    <!-- KONTEN UTAMA -->
    <main class="flex-1 max-w-5xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6 sm:py-8">

        <!-- Session Notice -->
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

        <!-- Header Halaman -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
            <div>
                <h1 class="text-xl sm:text-2xl font-extrabold text-[#002f34]">
                    Iklan Saya
                </h1>
                <p class="mt-1 text-xs sm:text-sm text-[#5c7a7d]">
                    Kelola semua barang dan jasa yang Anda tawarkan (Total: <strong><?= count($myAds) ?></strong> iklan)
                </p>
            </div>

            <a href="pasang-iklan.php" class="inline-flex items-center justify-center gap-2 bg-[#002f34] hover:bg-[#00454d] text-white font-bold text-xs sm:text-sm px-5 py-3 rounded transition shadow-2xs">
                <i class="fa-solid fa-plus"></i>
                <span>Pasang Iklan Baru</span>
            </a>
        </div>

        <!-- Daftar Iklan User -->
        <?php if (empty($myAds)): ?>
            <div class="bg-white border border-gray-200 rounded-lg p-12 text-center shadow-2xs">
                <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-gray-100 text-[#5c7a7d] flex items-center justify-center text-2xl">
                    <i class="fa-solid fa-box-open"></i>
                </div>
                <h2 class="text-base sm:text-lg font-extrabold text-[#002f34]">
                    Anda belum memasang iklan
                </h2>
                <p class="mt-1 text-xs sm:text-sm text-[#5c7a7d] max-w-md mx-auto">
                    Mulai jual barang bekas atau tawarkan jasa Anda secara gratis kepada ribuan calon pembeli.
                </p>
                <a href="pasang-iklan.php" class="mt-5 inline-flex items-center gap-2 px-6 py-3 rounded bg-[#002f34] hover:bg-[#00454d] text-white text-sm font-bold transition">
                    <i class="fa-solid fa-plus"></i>
                    <span>Pasang Iklan Pertama</span>
                </a>
            </div>
        <?php else: ?>
            <div class="space-y-4">
                <?php foreach ($myAds as$ad): ?>
                    <?php
                        $coverPath = !empty($ad['cover_image']) ? $ad['cover_image'] : '';$hasImg    = ($coverPath !== '' && is_file(__DIR__ . '/' .$coverPath));
                    ?>
                    <div class="bg-white border border-gray-200 hover:border-[#002f34]/60 rounded-lg overflow-hidden shadow-2xs flex flex-col sm:flex-row transition">
                        
                        <!-- Thumbnail Iklan -->
                        <a href="detail.php?id=<?= (int) $ad['id'] ?>" class="relative w-full sm:w-52 aspect-[16/10] sm:aspect-auto sm:h-40 bg-gray-100 shrink-0 overflow-hidden block">
                            <?php if ($hasImg): ?>
                                <img 
                                    src="<?= htmlspecialchars($coverPath, ENT_QUOTES, 'UTF-8') ?>" 
                                    alt="<?= htmlspecialchars($ad['title'], ENT_QUOTES, 'UTF-8') ?>" 
                                    class="w-full h-full object-cover hover:scale-105 transition duration-300"
                                >
                            <?php else: ?>
                                <div class="w-full h-full flex flex-col items-center justify-center text-gray-400">
                                    <i class="fa-regular fa-image text-2xl mb-1"></i>
                                    <span class="text-[11px]">Tanpa Foto</span>
                                </div>
                            <?php endif; ?>

                            <span class="absolute bottom-2 right-2 bg-black/75 text-white text-[10px] font-bold px-2 py-0.5 rounded">
                                <i class="fa-regular fa-images mr-1"></i><?= (int) $ad['total_images'] ?>
                            </span>
                        </a>

                        <!-- Informasi & Tombol Aksi -->
                        <div class="p-4 sm:p-5 flex-1 flex flex-col justify-between border-t sm:border-t-0 sm:border-l-4 border-l-[#ffce32]">
                            <div>
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <span class="inline-block bg-[#ebf1ff] text-[#002f34] text-[11px] font-extrabold uppercase px-2.5 py-0.5 rounded">
                                        <?= htmlspecialchars($ad['category_name'], ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                    <span class="text-xs text-[#5c7a7d]">
                                        <i class="fa-regular fa-calendar mr-1"></i><?= formatTanggalSingkat($ad['created_at']) ?>
                                    </span>
                                </div>

                                <a href="detail.php?id=<?= (int) $ad['id'] ?>" class="mt-2 block text-base sm:text-lg font-bold text-[#002f34] hover:underline line-clamp-1">
                                    <?= htmlspecialchars($ad['title'], ENT_QUOTES, 'UTF-8') ?>
                                </a>

                                <p class="mt-1 text-lg sm:text-xl font-extrabold text-[#002f34]">
                                    <?= formatRupiah($ad['price']) ?>
                                </p>
                            </div>

                            <div class="mt-4 pt-3 border-t border-gray-100 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                                <span class="text-xs text-[#5c7a7d] truncate">
                                    <i class="fa-solid fa-location-dot mr-1 text-[#002f34]"></i><?= htmlspecialchars($ad['location'], ENT_QUOTES, 'UTF-8') ?>
                                </span>

                                <div class="flex items-center gap-2 shrink-0">
                                    <!-- Lihat Detail -->
                                    <a href="detail.php?id=<?= (int) $ad['id'] ?>" class="px-3 py-1.5 rounded border border-gray-300 hover:border-[#002f34] text-xs font-bold text-[#002f34] transition">
                                        <i class="fa-regular fa-eye mr-1"></i>Lihat
                                    </a>

                                    <!-- Edit Iklan -->
                                    <a href="edit-iklan.php?id=<?= (int) $ad['id'] ?>" class="px-3 py-1.5 rounded bg-[#002f34] hover:bg-[#00454d] text-white text-xs font-bold transition">
                                        <i class="fa-regular fa-pen-to-square mr-1"></i>Edit
                                    </a>

                                    <!-- Hapus Iklan -->
                                    <form action="" method="POST" onsubmit="return confirm('Yakin ingin menghapus iklan ini secara permanen?');" class="inline">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="action" value="delete_ad">
                                        <input type="hidden" name="ad_id" value="<?= (int) $ad['id'] ?>">
                                        <button type="submit" class="px-3 py-1.5 rounded border border-rose-600 text-rose-600 hover:bg-rose-600 hover:text-white text-xs font-bold transition cursor-pointer">
                                            <i class="fa-regular fa-trash-can mr-1"></i>Hapus
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>

                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </main>

    <!-- FOOTER -->
    <footer class="bg-[#002f34] text-white text-xs py-4 mt-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex flex-col sm:flex-row items-center justify-between gap-2">
            <p>Iklan Baris Gratis di Indonesia</p>
            <p>&copy; <?= date('Y') ?> <strong>KF-OLX</strong>. Hak Cipta Dilindungi.</p>
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