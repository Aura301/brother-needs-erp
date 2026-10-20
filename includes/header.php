<?php
$page_title = $page_title ?? 'Brother Needs';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1">
<title><?= h($page_title) ?><?= empty($no_title_suffix) ? ' - Brother Needs' : '' ?></title>
<link rel="stylesheet" href="assets/css/style.css?v=safari4">
<script>
  (function () {
    var saved = localStorage.getItem('bn-theme');
    var theme = saved || (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
    if (theme === 'dark') document.documentElement.setAttribute('data-theme', 'dark');
  })();
</script>
</head>
<body>

<div class="layout">
  <?php include __DIR__ . '/sidebar.php'; ?>

  <div class="sidebar-overlay" id="sidebar-overlay" onclick="toggleSidebar(false)"></div>

  <main class="content">
    <div class="mobile-topbar">
      <img src="assets/img/logo.png" alt="Brother Needs">
      <button type="button" onclick="toggleSidebar(true)" aria-label="Buka menu">☰</button>
    </div>