<?php
/**
 * VAPE CO. — application configuration.
 *
 * Every tunable number the store uses lives here so there is exactly one
 * place to change it. The client-side constants in app.js are removed in
 * Phase 4 — from then on these values are the only ones that count.
 *
 * NOTE: real credentials must never be committed to a public repo.
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

/* ---------- Database (XAMPP defaults) ---------- */
define('DB_HOST', 'localhost');
define('DB_NAME', 'vape_shop');
define('DB_USER', 'root');
define('DB_PASS', '');

/* ---------- Store settings ---------- */
define('APP_NAME', 'VAPE CO.');
define('APP_TAGLINE', 'Premium Vape Shop');

/*
 * Prices are VAT-inclusive. This rate is only used to disclose the VAT already
 * contained in a price; it is never added to the cart or order total.
 */
define('VAT_RATE', 0.12);

/* Flat courier fee, waived above the free-shipping threshold. */
define('FLAT_SHIPPING', 150.00);
define('FREE_SHIPPING_ABOVE', 2000.00);

/* Age gate: minimum age to register, log in, or buy. */
define('MIN_AGE', 21);

/* Terms of agreement version stored with each acceptance. */
define('TERMS_VERSION', '2026-11-16');

/*
 * Open staff registration is for initial setup only.
 * Set this to false after the first admin account exists.
 */
define('ALLOW_OPEN_ADMIN_REGISTRATION', true);

/* Maximum quantity of one line in the cart. */
define('MAX_QTY', 10);

/* Brute-force protection: failed sign-ins before lockout, and lockout length. */
define('LOGIN_MAX_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_MINUTES', 15);

/* Admin session idle timeout, in seconds. */
define('ADMIN_IDLE_TIMEOUT', 1800);

/* ---------- Runtime ---------- */
/* Philippine time. MySQL is put on the same offset in config/database.php. */
date_default_timezone_set('Asia/Manila');
