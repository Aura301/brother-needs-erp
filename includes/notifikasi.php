<?php
/**
 * Kirim notifikasi email pakai fungsi mail() bawaan PHP (gratis, gak perlu library
 * tambahan). Kebanyakan hosting (termasuk Hostinger) udah support ini out-of-the-box.
 * Kalau dites di localhost/XAMPP tanpa setting SMTP, ini kemungkinan gak akan terkirim
 * beneran (XAMPP gak ada mail server aktif secara default) - itu normal, baru jalan
 * beneran pas udah di-hosting.
 */
function send_notification_email($to, $subject, $body) {
    if (empty($to)) return false;
    $headers = "From: Brother Needs <noreply@" . ($_SERVER['HTTP_HOST'] ?? 'brotherneeds.local') . ">\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    return @mail($to, $subject, $body, $headers);
}

/**
 * Kirim notifikasi HP instan lewat ntfy.sh (gratis, gak perlu daftar akun).
 * User tinggal install app "ntfy" di HP, subscribe ke topic yang sama kayak di sini.
 */
function send_ntfy_notification($topic, $title, $message) {
    if (empty($topic)) return false;
    $url = 'https://ntfy.sh/' . rawurlencode($topic);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $message);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Title: ' . $title,
            'Priority: high',
            'Tags: bell',
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_exec($ch);
        $ok = curl_errno($ch) === 0;
        curl_close($ch);
        return $ok;
    }

    // fallback kalau cURL gak aktif di server
    $opts = [
        'http' => [
            'method' => 'POST',
            'header' => "Title: $title\r\nPriority: high\r\nTags: bell\r\nContent-Type: text/plain\r\n",
            'content' => $message,
            'timeout' => 5,
        ],
    ];
    return @file_get_contents($url, false, stream_context_create($opts)) !== false;
}

/** Kirim ke email & ntfy sekaligus, dipanggil tiap ada pendaftaran baru */
function notify_pendaftaran_baru($pdo, $namaUsaha, $namaPic, $whatsapp) {
    $company = get_company($pdo);
    $judul = 'Pendaftaran Baru: ' . $namaUsaha;
    $pesan = "Ada pendaftaran mitra/SPPG baru!\n\nUsaha: $namaUsaha\nPIC: $namaPic\nWhatsApp: $whatsapp\n\nCek & tindak lanjuti di menu Pendaftaran.";

    if (!empty($company['notif_email'])) {
        send_notification_email($company['notif_email'], $judul, $pesan);
    }
    if (!empty($company['ntfy_topic'])) {
        send_ntfy_notification($company['ntfy_topic'], $judul, $pesan);
    }
}