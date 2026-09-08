<?php
/**
 * Shared page header — includes/header.php
 * Expects an optional $pageTitle variable to be set before include.
 *
 * v2 change: all CSS/JS assets are now served locally from public/assets
 * (built via Tailwind CLI, vendored via npm) instead of external CDNs.
 * See README.md "Frontend build" for the npm build commands.
 */
$pageTitle = $pageTitle ?? 'Dashboard';
$user = current_user();
$flash = flash_get();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> · CARE</title>
<link rel="icon" type="image/png" href="assets/images/csc-logo.png">
<link rel="stylesheet" href="assets/css/app.build.css">
<link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
<script defer src="assets/vendor/qrcode-generator/qrcode.js"></script>
<script defer src="assets/vendor/jsqr/jsQR.js"></script>
<script defer src="assets/vendor/alpine/alpine.min.js"></script>
<script src="assets/vendor/chart/chart.min.js"></script>
</head>
<body class="bg-slate-50 text-slate-800 antialiased" x-data="{ sidebarOpen: false }">

<?php if ($flash): ?>
<div id="flash-toast"
     class="fixed top-4 right-4 z-[100] max-w-sm rounded-lg shadow-lg px-4 py-3 text-sm font-medium flex items-start gap-2 print:hidden
     <?= $flash['type'] === 'success' ? 'bg-green-600 text-white' : 'bg-red-600 text-white' ?>">
  <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check' : 'fa-triangle-exclamation' ?> mt-0.5"></i>
  <span><?= e($flash['message']) ?></span>
</div>
<script>
  setTimeout(() => { const t = document.getElementById('flash-toast'); if (t) t.remove(); }, 4000);
</script>
<?php endif; ?>

<div class="flex min-h-screen">
