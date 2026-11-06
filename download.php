<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

require_login();
$st = db()->prepare('SELECT * FROM documents WHERE id = ?');
$st->execute([(int)($_GET['id'] ?? 0)]);
$d = $st->fetch();
$path = $d ? UPLOAD_DIR . '/' . $d['stored_name'] : '';
if (!$d || !is_file($path)) {
    http_response_code(404);
    exit('File non trovato.');
}

$name = $d['original_name'];
$ascii = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
header('Content-Type: application/octet-stream');
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . filesize($path));
header("Content-Disposition: attachment; filename=\"$ascii\"; filename*=UTF-8''" . rawurlencode($name));
readfile($path);
