<?php
/**
 * Nimmt den Lieferantrag der Website entgegen und schickt ihn per E-Mail
 * mit der Excel-Tabelle im Anhang.
 *
 * Einrichten:
 *   1. Diese Datei neben index.html auf den Webspace legen (PHP muss laufen).
 *   2. In index.html im Skriptteil SEND_ENDPOINT setzen:
 *        const SEND_ENDPOINT = "/send-lieferantrag.php";
 *   3. Testen. Landet nichts im Postfach, prüfen ob der Hoster mail() erlaubt —
 *      sonst SMTP-Versand des Hosters oder PHPMailer verwenden.
 */

declare(strict_types=1);

const EMPFAENGER = 'chois.du.de@gmail.com';
const ABSENDER   = 'noreply@choisshipping.com';   // Domain des Webspace, sonst filtern Mailserver
const MAX_BYTES  = 2 * 1024 * 1024;               // Anhang höchstens 2 MB

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Nur POST']);
    exit;
}

$raw  = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'Kein gültiges JSON']);
    exit;
}

$text     = (string)($data['text'] ?? '');
$subject  = (string)($data['subject'] ?? 'Lieferantrag');
$fileName = (string)($data['fileName'] ?? 'Lieferantrag.xlsx');
$base64   = (string)($data['xlsxBase64'] ?? '');

// Dateinamen auf einen harmlosen Satz Zeichen begrenzen
$fileName = preg_replace('/[^A-Za-z0-9._-]/', '_', $fileName) ?: 'Lieferantrag.xlsx';
if (!str_ends_with(strtolower($fileName), '.xlsx')) {
    $fileName .= '.xlsx';
}

// Betreff darf keine Zeilenumbrüche enthalten (Header-Injection)
$subject = trim(preg_replace('/[\r\n]+/', ' ', $subject) ?? '');
if ($subject === '') {
    $subject = 'Lieferantrag';
}

if ($text === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Text fehlt']);
    exit;
}

// Anhang ist optional: das Kontaktformular schickt nur Text.
$binary = null;
if ($base64 !== '') {
    $binary = base64_decode($base64, true);
    if ($binary === false || strlen($binary) > MAX_BYTES || substr($binary, 0, 2) !== 'PK') {
        http_response_code(400);
        echo json_encode(['error' => 'Anhang ungültig']);
        exit;
    }
}

$boundary = 'cs-' . bin2hex(random_bytes(12));

$headers = implode("\r\n", [
    'From: choisshipping <' . ABSENDER . '>',
    'Reply-To: ' . ABSENDER,
    'MIME-Version: 1.0',
    'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
]);

$body  = "--$boundary\r\n";
$body .= "Content-Type: text/plain; charset=UTF-8\r\n";
$body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
$body .= $text . "\r\n\r\n";

if ($binary !== null) {
    $body .= "--$boundary\r\n";
    $body .= "Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet; name=\"$fileName\"\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n";
    $body .= "Content-Disposition: attachment; filename=\"$fileName\"\r\n\r\n";
    $body .= chunk_split(base64_encode($binary), 76, "\r\n");
}
$body .= "--$boundary--";

$encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
$sent = mail(EMPFAENGER, $encodedSubject, $body, $headers);

if (!$sent) {
    http_response_code(500);
    echo json_encode(['error' => 'Versand fehlgeschlagen']);
    exit;
}

echo json_encode(['ok' => true]);
