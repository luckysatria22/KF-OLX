<?php
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

$host = 'localhost';
$port = '5432';
$db   = 'kf-olx';
$user = 'postgres';
$pass = 'postgres';

try {
    $dsn = "pgsql:host=$host;port=$port;dbname=$db";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
} catch (PDOException $e) {
    error_log('Database Connection Error: ' . $e->getMessage());
    die('Terjadi gangguan koneksi ke server database. Silakan coba beberapa saat lagi.');
}

// Ubah format nomor HP/WA (08xxx / +628xxx) menjadi format standar 628xxx
function normalizeWhatsApp(string $phone): string
{
    $digits = preg_replace('/[^0-9]/', '', $phone);
    if ($digits === '') {
        return '';
    }
    if (str_starts_with($digits, '0')) {
        $digits = '62' . substr($digits, 1);
    } elseif (str_starts_with($digits, '8')) {
        $digits = '62' . $digits;
    }
    return $digits;
}

// Format nomor 628xxx agar rapi saat ditampilkan ke layar (+62 812-3456-7890)
function formatWhatsAppDisplay(?string $wa): string
{
    if (!$wa) {
        return '-';
    }
    if (str_starts_with($wa, '62')) {
        $rest = substr($wa, 2);
        return '+62 ' . implode('-', str_split($rest, 4));
    }
    return $wa;
}