<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= e($title) ?> · <?= e($appName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(asset_url('assets/app.css')) ?>">
</head>
<body>
<?php if ($appShell && $user): ?>
<div class="app-layout">
    <aside class="sidebar">
        <a href="?page=dashboard" class="brand"><span class="brand-mark">⌁</span><span><?= e($appName) ?><small>coding assessment</small></span></a>
        <div class="sidebar-user"><div class="avatar"><?= e(strtoupper(substr($user['name'], 0, 1))) ?></div><div><strong><?= e($user['name']) ?></strong><small><?= e(role_label($user['role'])) ?><?= !empty($user['class_name']) ? ' · ' . e($user['class_name']) : '' ?></small></div></div>
        <nav>
            <a class="nav-link <?= $activePage === 'dashboard' ? 'active' : '' ?>" href="?page=dashboard"><span>⌂</span> Dashboard</a>
            <?php if ($user['role'] === 'siswa'): ?>
                <a class="nav-link <?= $activePage === 'exam' ? 'active' : '' ?>" href="?page=dashboard"><span>◫</span> Ujian saya</a>
            <?php else: ?>
                <a class="nav-link <?= in_array($activePage, ['exam_manage','question']) ? 'active' : '' ?>" href="?page=exam_manage"><span>▣</span> Bank ujian</a>
                <a class="nav-link <?= $activePage === 'import' ? 'active' : '' ?>" href="?page=import"><span>⇧</span> Import siswa</a>
                <a class="nav-link <?= $activePage === 'kartu_akun' ? 'active' : '' ?>" href="?page=kartu_akun"><span>▤</span> Kartu akun</a>
                <?php if ($user['role'] === 'super_admin'): ?><a class="nav-link <?= $activePage === 'users' ? 'active' : '' ?>" href="?page=users"><span>♙</span> Pengguna</a><?php endif; ?>
            <?php endif; ?>
        </nav>
        <a href="?page=logout" class="nav-link logout"><span>↪</span> Keluar</a>
    </aside>
    <main class="main-content">
        <header class="topbar"><div class="mobile-brand"><span class="brand-mark">⌁</span><?= e($appName) ?></div><div class="topbar-right"><span class="status-dot"></span> Sistem online <span class="topbar-divider"></span><span><?= e(date('d M Y')) ?></span></div></header>
        <div class="page-content">
<?php else: ?>
<main>
<?php endif; ?>
<?php foreach ($flashes as $flash): ?><div class="alert alert-<?= e($flash['type']) ?>"><span><?= $flash['type'] === 'success' ? '✓' : '!' ?></span><?= e($flash['message']) ?></div><?php endforeach; ?>
