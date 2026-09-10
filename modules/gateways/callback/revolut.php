<?php
require_once __DIR__ . '/../../../init.php';
header('Location: ' . rtrim($CONFIG['SystemURL'] ?? '/', '/') . '/clientarea.php');
exit;
