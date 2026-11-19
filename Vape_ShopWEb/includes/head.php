<?php
/**
 * VAPE CO. — shared document head.
 *
 * Emits the <head>, opens <body>, and renders the SVG icon sprite that every
 * page draws from with <use href="#icon-name">.
 *
 * A page sets the optional variables BEFORE including this file:
 *
 *     $pageTitle = 'Sign in — ' . APP_NAME;
 *     $bodyClass = 'page-auth';
 *     require_once __DIR__ . '/includes/head.php';
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

$pageTitle       = $pageTitle       ?? (APP_NAME . ' — ' . APP_TAGLINE);
$bodyClass       = $bodyClass       ?? '';
$metaDescription = $metaDescription ?? (APP_NAME . ' — premium vape shop. Adults 21+ only.');
$headExtra       = $headExtra       ?? '';

$__csrf  = csrf_token();
$__user  = current_user_public();
$__api   = api_url('');          // e.g. "/Cristobal_webprog/Vape_ShopWEb/api/"
$__base  = asset_base();         // e.g. "" or "../"
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="<?= e($metaDescription) ?>">
  <title><?= e($pageTitle) ?></title>

  <!-- CSRF token for the JavaScript API. Read it as:
       document.querySelector('meta[name="csrf-token"]').content -->
  <meta name="csrf-token" content="<?= e($__csrf) ?>">

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600&display=swap">
  <link rel="stylesheet" href="<?= e($__base) ?>style.css">
  <?= $headExtra ?>

  <script>
    /* Bootstrap for the client. Who we are, the CSRF token, and where the API lives. */
    window.__APP__ = {
      user: <?= json_encode($__user, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
      csrf: <?= json_encode($__csrf) ?>,
      apiBase: <?= json_encode($__api) ?>,
      appName: <?= json_encode(APP_NAME) ?>,
      guestTokenHash: <?= json_encode(guest_token()) ?>
    };
  </script>
</head>
<body class="<?= e($bodyClass) ?>">

<!-- ============================================================
     SVG sprite — one <symbol> per icon, referenced with <use>.
     ============================================================ -->
<svg xmlns="http://www.w3.org/2000/svg" style="display:none" aria-hidden="true">
  <!-- Product placeholder art (used when a product has no image URL) -->
  <symbol id="ph-art" viewBox="0 0 120 120">
    <rect width="120" height="120" fill="#eef1ec"/>
    <circle cx="60" cy="52" r="26" fill="none" stroke="#2f6f4e" stroke-width="1.5" opacity=".35"/>
    <circle cx="60" cy="52" r="17" fill="none" stroke="#2f6f4e" stroke-width="1.5" opacity=".55"/>
    <path d="M52 76h16l-3 22a5 5 0 0 1-5 4h-0a5 5 0 0 1-5-4z" fill="none" stroke="#8a9a8f" stroke-width="1.5" opacity=".8"/>
    <path d="M55 68h10" stroke="#8a9a8f" stroke-width="1.5" opacity=".8"/>
    <path d="M60 30v-8M60 82v6M38 52h-8M90 52h-8" stroke="#8a9a8f" stroke-width="1" opacity=".55"/>
  </symbol>
  <!-- UI icons -->
  <symbol id="icon-cart" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
    <path d="M3 4h2.4l2.1 11.2a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.55L20.5 8H6"/>
    <circle cx="10" cy="20" r="1.4"/><circle cx="17.5" cy="20" r="1.4"/>
  </symbol>
  <symbol id="icon-plus" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
    <path d="M12 5v14M5 12h14"/>
  </symbol>
  <symbol id="icon-minus" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
    <path d="M5 12h14"/>
  </symbol>
  <symbol id="icon-trash" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
    <path d="M4 7h16M10 7V5h4v2M6 7l1 13h10l1-13M10 11v5M14 11v5"/>
  </symbol>
  <symbol id="icon-close" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
    <path d="M6 6l12 12M18 6L6 18"/>
  </symbol>
  <symbol id="icon-search" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round">
    <circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5"/>
  </symbol>
  <symbol id="icon-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
    <path d="M4.5 12.5l5 5L19.5 6.5"/>
  </symbol>
  <symbol id="icon-box" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
    <path d="M3.5 8L12 3.5 20.5 8v8L12 20.5 3.5 16zM3.5 8L12 12.5 20.5 8M12 12.5V20.5"/>
  </symbol>
  <symbol id="icon-arrow-left" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    <path d="M19 12H5M11 6l-6 6 6 6"/>
  </symbol>
  <symbol id="icon-card" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round">
    <rect x="2.5" y="5" width="19" height="14" rx="2"/><path d="M2.5 9.5h19M6 15h4"/>
  </symbol>
  <symbol id="icon-wallet" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round">
    <path d="M3 7a2 2 0 0 1 2-2h12v4"/><path d="M3 7v10a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V9H5a2 2 0 0 1-2-2z"/><circle cx="17" cy="14" r="1.3" fill="currentColor" stroke="none"/>
  </symbol>
  <symbol id="icon-cash" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round">
    <rect x="2.5" y="6" width="19" height="12" rx="2"/><circle cx="12" cy="12" r="2.6"/><path d="M6 12h.01M18 12h.01"/>
  </symbol>
  <!-- Category marks (card micro-labels + chips) -->
  <symbol id="cat-disposables" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round">
    <rect x="5.5" y="1.5" width="5" height="10" rx="1.5"/><path d="M8 13.5v1"/>
  </symbol>
  <symbol id="cat-pods" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round">
    <rect x="3" y="4" width="4.5" height="9" rx="1.2"/><rect x="9" y="6" width="4" height="7" rx="1.2"/>
  </symbol>
  <symbol id="cat-batteries" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round">
    <rect x="1.5" y="5" width="11" height="6" rx="1.4"/><path d="M14.5 7v2"/>
  </symbol>
  <symbol id="cat-e-juice" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round">
    <path d="M8 1.8s3.8 4.2 3.8 6.7a3.8 3.8 0 1 1-7.6 0C4.2 6 8 1.8 8 1.8z"/>
  </symbol>
  <symbol id="cat-cartridges" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round">
    <rect x="5" y="2.5" width="6" height="11" rx="3"/><path d="M8 2.5V1"/>
  </symbol>
  <symbol id="cat-kits" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round">
    <path d="M2 5l6-2.5L14 5v6l-6 2.5L2 11z"/><path d="M2 5l6 2.5L14 5M8 7.5v6"/>
  </symbol>
</svg>
