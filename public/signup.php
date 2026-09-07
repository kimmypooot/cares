<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';

// Public Viewer self-registration has been retired in favor of Partner
// Agency Registration (see login.php). Administrators can still create
// Viewer accounts directly via users.php.
redirect('login.php');
