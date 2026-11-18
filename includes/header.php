<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KF-OLX - Jual Beli Online Mudah &amp; Cepat</title>
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Font Awesome 6 (Untuk icon pada tabel categories) -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Custom CSS Terpisah -->
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="min-h-screen flex flex-col">

    <!-- Main Sticky Navbar -->
    <header class="sticky top-0 z-50 bg-[#f7f8f9] border-b border-gray-200 shadow-xs">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between gap-3 py-3">
                
                <!-- Brand Logo KF-OLX -->
                <a href="index.php" class="flex items-center shrink-0 select-none">
                    <span class="text-2xl sm:text-3xl font-extrabold tracking-tighter text-[#002f34]">
                        KF<span class="text-[#23e5db]">-</span>OLX
                    </span>
                </a>

                <!-- Form Pencarian: Location (ads.location) & Keyword (ads.title) -->
                <form action="index.php" method="GET" class="hidden md:flex flex-1 items-center gap-3 max-w-4xl mx-2">
                    
                    <!-- Filter Lokasi (kolom: ads.location) -->
                    <div class="olx-input-box flex items-center rounded px-3 h-12 w-64 shrink-0">
                        <i class="fa-solid fa-location-dot text-[#002f34] mr-2.5"></i>
                        <select name="location" class="w-full bg-transparent text-sm font-medium text-[#002f34] focus:outline-none cursor-pointer">
                            <option value="">Semua Indonesia</option>
                            <option value="Malang">Malang, Jawa Timur</option>
                            <option value="Surabaya">Surabaya, Jawa Timur</option>
                            <option value="Jakarta Selatan">Jakarta Selatan</option>
                            <option value="Bandung">Bandung, Jawa Barat</option>
                            <option value="Yogyakarta">DI Yogyakarta</option>
                        </select>
                    </div>

                    <!-- Search Bar Kata Kunci (kolom: ads.title / ads.description) -->
                    <div class="olx-input-box flex flex-1 items-center rounded h-12 overflow-hidden">
                        <input 
                            type="text" 
                            name="q" 
                            placeholder="Temukan Mobil, Handphone, Properti, dan lainnya..." 
                            class="w-full px-4 text-sm text-[#002f34] placeholder-gray-500 focus:outline-none"
                        >
                        <button type="submit" class="bg-[#002f34] hover:bg-[#00454d] text-white h-full px-5 flex items-center justify-center transition cursor-pointer" aria-label="Cari Iklan">
                            <i class="fa-solid fa-magnifying-glass text-lg"></i>
                        </button>
                    </div>
                </form>

                <!-- Aksi User (tabel: users) & Tombol Pasang Iklan (tabel: ads) -->
                <div class="flex items-center gap-4 shrink-0">
                    <!-- Link Login / Register (users) -->
                    <a href="login.php" class="text-sm sm:text-base font-bold text-[#002f34] underline decoration-2 underline-offset-4 hover:no-underline">
                        Login / Daftar
                    </a>

                    <!-- Tombol + JUAL (arah ke halaman tambah ads) -->
                    <a href="pasang-iklan.php" class="btn-jual-olx">
                        <i class="fa-solid fa-plus text-base"></i>
                        <span>JUAL</span>
                    </a>
                </div>
            </div>

            <!-- Search Bar Versi Mobile -->
            <form action="index.php" method="GET" class="md:hidden pb-3">
                <div class="olx-input-box flex items-center rounded h-11 overflow-hidden">
                    <i class="fa-solid fa-magnifying-glass text-gray-500 pl-3.5"></i>
                    <input 
                        type="text" 
                        name="q" 
                        placeholder="Cari di KF-OLX..." 
                        class="w-full px-3 text-sm text-[#002f34] focus:outline-none"
                    >
                    <button type="submit" class="bg-[#002f34] text-white h-full px-4 text-sm font-semibold">
                        Cari
                    </button>
                </div>
            </form>
        </div>

        <!-- Sub-Navbar Kategori Cepat (mengacu pada tabel: categories) -->
        <nav class="bg-white border-t border-gray-200">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex items-center gap-6 py-2.5 overflow-x-auto no-scrollbar text-sm whitespace-nowrap">
                    <a href="index.php" class="font-bold text-[#002f34] flex items-center gap-1.5 hover:text-[#007a87]">
                        <span>SEMUA KATEGORI</span>
                        <i class="fa-solid fa-chevron-down text-xs"></i>
                    </a>
                    <span class="text-gray-300">|</span>
                    <a href="index.php?category_id=1" class="text-[#002f34] hover:text-[#007a87] transition">Mobil Bekas</a>
                    <a href="index.php?category_id=2" class="text-[#002f34] hover:text-[#007a87] transition">Motor Bekas</a>
                    <a href="index.php?category_id=3" class="text-[#002f34] hover:text-[#007a87] transition">Properti</a>
                    <a href="index.php?category_id=4" class="text-[#002f34] hover:text-[#007a87] transition">Handphone &amp; Gadget</a>
                    <a href="index.php?category_id=5" class="text-[#002f34] hover:text-[#007a87] transition">Elektronik &amp; Komputer</a>
                    <a href="index.php?category_id=6" class="text-[#002f34] hover:text-[#007a87] transition">Perabotan Rumah</a>
                    <a href="index.php?category_id=7" class="text-[#002f34] hover:text-[#007a87] transition">Hobi &amp; Olahraga</a>
                    <a href="index.php?category_id=8" class="text-[#002f34] hover:text-[#007a87] transition">Lowongan Kerja</a>
                </div>
            </div>
        </nav>
    </header>