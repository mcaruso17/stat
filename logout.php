<?php
declare(strict_types=1);
require __DIR__ . '/inc/bootstrap.php';

$_SESSION = [];
session_destroy();
// Il browser resta "conosciuto": si torna alla scelta del profilo, non al quiz.
header('Location: ' . (empty($_COOKIE['cs_known']) ? 'gate.php' : 'auth.php'));
