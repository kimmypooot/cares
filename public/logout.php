<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';

if (is_logged_in()) {
    do_logout(Database::getConnection());
}
redirect('login.php');
