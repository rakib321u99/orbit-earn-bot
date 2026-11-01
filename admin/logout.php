<?php
// Orbit Earn Bot v1 — admin logout.
declare(strict_types=1);
session_start();
unset($_SESSION['admin_id']);
header('Location: index.php');
exit;
