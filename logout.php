<?php
require_once 'config.php';

unset(
    $_SESSION['user_id'],
    $_SESSION['user_name'],
    $_SESSION['user_email'],
    $_SESSION['user_whatsapp']
);

session_regenerate_id(true);
$_SESSION['csrf_token']    = bin2hex(random_bytes(32));
$_SESSION['flash_success'] = 'Anda telah berhasil keluar dari akun.';

header('Location: index.php');
exit;