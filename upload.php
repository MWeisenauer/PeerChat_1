<?php
// Konfiguration
$ftp_host = "timetec001.no-ip.info";
$ftp_port = 42156;
$ftp_user = "Markus";
$ftp_pass = "xyz";
$ftp_path = "/";
$pub_key_file = "public.pem";
$priv_key_file = "private.pem";



if ($_SERVER["REQUEST_METHOD"] == "POST" && !empty($_POST['message'])) {
    $textToEncrypt = $_POST['message'];

    // 1. FTPS Verbindung aufbauen
    $conn_id = ftp_ssl_connect($ftp_host, $ftp_port);
    $login_result = ftp_login($conn_id, $ftp_user, $ftp_pass);
    ftp_pasv($conn_id, true);

    if (!$conn_id || !$login_result) {
        die("FTP Verbindung fehlgeschlagen.");
    }

    // 2. Public Key vom Server laden
    $temp_key_stream = fopen('php://temp', 'r+');
    if (ftp_fget($conn_id, $temp_key_stream, $ftp_path . $pub_key_file, FTP_ASCII)) {
        rewind($temp_key_stream);
        $publicKey = stream_get_contents($temp_key_stream);
    } else {
        die("Public Key konnte nicht gefunden werden.");
    }

    // --- HYBRIDE VERSCHLÜSSELUNG START ---

    // A) Generiere einen zufälligen AES-Schlüssel (256-bit) und einen IV
    $aesKey = openssl_random_pseudo_bytes(32);
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));

    // B) Verschlüssele die Nachricht mit AES-256-CBC
    $encryptedMessage = openssl_encrypt($textToEncrypt, 'aes-256-cbc', $aesKey, 0, $iv);

    // C) Verschlüssele den AES-Schlüssel mit dem RSA Public Key
    $encryptedAesKey = "";
    if (!openssl_public_encrypt($aesKey, $encryptedAesKey, $publicKey)) {
        die("RSA-Verschlüsselung fehlgeschlagen.");
    }

    // D) Paket schnüren: IV + verschlüsselter Key + verschlüsselte Nachricht
    // Wir trennen die Teile mit einem Trenner oder speichern sie strukturiert
    $finalData = base64_encode($iv) . "::" . base64_encode($encryptedAesKey) . "::" . $encryptedMessage;

    // --- HYBRIDE VERSCHLÜSSELUNG ENDE ---

    // 4. Upload der Datei
    $filename = "msg_" . date("Y-m-d_H-i-s") . ".dat";
    $temp_file = fopen('php://temp', 'r+');
    fwrite($temp_file, $finalData);
    rewind($temp_file);

    if (ftp_fput($conn_id, $ftp_path . $filename, $temp_file, FTP_BINARY)) {
        echo "<h3>Übertragung erfolgreich!</h3>";
        echo "Die verschlüsselte Nachricht (Länge: " . strlen($textToEncrypt) . " Zeichen) wurde hochgeladen.";
    } else {
        echo "Fehler beim Upload.";
    }

    fclose($temp_file);
    fclose($temp_key_stream);
    ftp_close($conn_id);
}
?>
