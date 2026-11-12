<?php
/**
 * Shared <head>. Variables: $title, $theme ('client'|'admin').
 * No inline scripts or styles (strict Content-Security-Policy).
 */
$themeColor = ($theme ?? 'client') === 'client' ? '#0f766e' : '#1d4ed8';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="<?= e($themeColor) ?>">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="base-path" content="<?= e(base_path()) ?>">
<title><?= e($title ?? 'Douffer PhotoSys') ?> · Douffer PhotoSys</title>
<link rel="icon" href="<?= e(asset('assets/img/icon.svg')) ?>" type="image/svg+xml">
<link rel="apple-touch-icon" href="<?= e(asset('assets/img/icon.svg')) ?>">
<link rel="manifest" href="<?= e(url('/manifest.json')) ?>">
<link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
<script src="<?= e(asset('assets/js/app.js')) ?>" defer></script>
<?php foreach (($scripts ?? []) as $s): ?>
<script src="<?= e(asset('assets/js/' . $s)) ?>" defer></script>
<?php endforeach; ?>
</head>
