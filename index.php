<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>PeerChat</title>
</head>
<body>
    <h1>PeerChat - Sichere Nachrichten</h1>

    <?php
    // Konfiguration
    $ftp_host = "timetec001.no-ip.info";
    $ftp_port = 42156;
    $ftp_user = "Markus";
    $ftp_pass = "xyz";
    $ftp_path = "/";
    $priv_key_file = "private.pem";

    function downloadRemoteFile($conn_id, $remoteFile, $mode = FTP_BINARY) {
        $tmpFile = tempnam(sys_get_temp_dir(), 'ftp_');
        if ($tmpFile === false) {
            return false;
        }

        $downloaded = ftp_get($conn_id, $tmpFile, $remoteFile, $mode, 0);
        if (!$downloaded) {
            @unlink($tmpFile);
            return false;
        }

        $content = file_get_contents($tmpFile);
        @unlink($tmpFile);
        return $content;
    }

    function decryptMessage($encryptedData, $privateKey, &$error = null) {
        $error = null;
        $parts = explode("::", $encryptedData, 3);
        if (count($parts) !== 3) {
            $error = 'Ungültiges Nachrichtenformat';
            return false;
        }

        $iv = base64_decode($parts[0], true);
        $encryptedAesKey = base64_decode($parts[1], true);
        $encryptedMessage = $parts[2];

        if ($iv === false || $encryptedAesKey === false) {
            $error = 'Base64-Dekodierung fehlgeschlagen';
            return false;
        }

        $aesKey = '';
        if (!openssl_private_decrypt($encryptedAesKey, $aesKey, $privateKey)) {
            $error = openssl_error_string() ?: 'RSA-Entschlüsselung fehlgeschlagen';
            return false;
        }

        // Der AES-Ciphertext ist hier Base64-kodiert, weil upload.php openssl_encrypt ohne RAW-Option verwendet.
        $decryptedMessage = openssl_decrypt($encryptedMessage, 'aes-256-cbc', $aesKey, 0, $iv);
        if ($decryptedMessage === false) {
            $error = openssl_error_string() ?: 'AES-Entschlüsselung fehlgeschlagen';
        }

        return $decryptedMessage;
    }

    $conn_id = ftp_ssl_connect($ftp_host, $ftp_port);
    $login_result = ftp_login($conn_id, $ftp_user, $ftp_pass);
    ftp_pasv($conn_id, true);

    if ($conn_id && $login_result) {
        $privateKey = downloadRemoteFile($conn_id, $ftp_path . $priv_key_file, FTP_ASCII);
        if ($privateKey === false) {
            echo "<p>Private Key konnte nicht geladen werden.</p>";
        } else {
            $remoteFiles = ftp_nlist($conn_id, $ftp_path);
            if ($remoteFiles === false) {
                echo "<p>Dateiliste konnte nicht geladen werden.</p>";
            } else {
                $messages = [];
                $foundFiles = 0;
                $remoteFiles = array_filter($remoteFiles, fn($file) => $file !== '.' && $file !== '..');

                foreach ($remoteFiles as $remoteFile) {
                    $fileName = basename($remoteFile);
                    if (stripos($fileName, 'msg_') === 0 && str_ends_with($fileName, '.dat')) {
                        $foundFiles++;
                        $encryptedData = downloadRemoteFile($conn_id, $remoteFile, FTP_BINARY);
                        if ($encryptedData === false) {
                            echo "<p>Fehler beim Laden von $fileName.</p>";
                            continue;
                        }

                        $decryptError = null;
                        $decrypted = decryptMessage($encryptedData, $privateKey, $decryptError);
                        if ($decrypted !== false) {
                            $messages[] = $decrypted;
                        } else {
                            echo "<p>Entschlüsselung fehlgeschlagen für $fileName: " . htmlspecialchars($decryptError) . "</p>";
                        }
                    }
                }

                if ($foundFiles === 0) {
                    echo "<p>Keine msg_*.dat Dateien gefunden.</p>";
                }

                if (!empty($messages)) {
                    echo "<h2>Entschlüsselte Nachrichten:</h2>";
                    echo "<ul>";
                    foreach ($messages as $msg) {
                        echo "<li>" . htmlspecialchars($msg) . "</li>";
                    }
                    echo "</ul>";
                } elseif ($foundFiles > 0) {
                    echo "<p>Die Dateien wurden geladen, aber die Entschlüsselung ist fehlgeschlagen.</p>";
                }
            }
        }

        ftp_close($conn_id);
    } else {
        echo "<p>FTPS Verbindung fehlgeschlagen.</p>";
    }
    ?>

    <h2>Neue Nachricht senden</h2>
    <form action="upload.php" method="post">
        <textarea name="message" rows="4" cols="50" placeholder="Geben Sie Ihre Nachricht ein..."></textarea><br><br>
        <input type="submit" value="Senden">
    </form>
</body>
</html>
