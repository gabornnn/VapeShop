<?php
/**
 * VAPE CO. — database connection.
 *
 * One PDO connection for the whole request, created lazily.
 *
 * Every query in the app uses a PREPARED STATEMENT:
 *
 *     $stmt = db()->prepare('SELECT * FROM users WHERE email = ?');
 *     $stmt->execute([$email]);
 *     $user = $stmt->fetch();
 *
 * The SQL and the values are sent to MySQL separately, so a value can never
 * be read as SQL. That is what stops SQL injection.
 */

require_once __DIR__ . '/config.php';

/**
 * Return the shared PDO connection.
 *
 * @throws RuntimeException if the database cannot be reached.
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME);

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            /* Throw exceptions on error instead of printing a warning. */
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            /* Rows come back as ['column' => value]. */
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            /* Use real prepared statements, not emulated ones. */
            PDO::ATTR_EMULATE_PREPARES   => false,
            /* Put MySQL on Philippine time so TIMESTAMP columns line up with PHP. */
            PDO::MYSQL_ATTR_INIT_COMMAND  => "SET time_zone = '+08:00'",
        ]);
    } catch (PDOException $e) {
        error_log('Database connection failed: ' . $e->getMessage());
        db_fail();
    }

    return $pdo;
}

/**
 * Called when the database is unreachable.
 *
 * Never shows the driver message or a file path to the visitor — that would
 * leak the server layout. The real reason goes to the PHP error log.
 */
function db_fail(): void
{
    $uri  = $_SERVER['REQUEST_URI'] ?? '';
    $isApi = strpos($uri, '/api/') !== false;

    if (!headers_sent()) {
        http_response_code(503);
        header('Retry-After: 60');
    }

    if ($isApi) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'    => false,
            'error' => [
                'code'    => 'SERVER_ERROR',
                'message' => 'The store is temporarily unavailable. Please try again shortly.',
            ],
        ]);
        exit;
    }

    header('Content-Type: text/html; charset=utf-8');
    $name = htmlspecialchars(APP_NAME, ENT_QUOTES, 'UTF-8');
    echo <<<HTML
    <!DOCTYPE html>
    <html lang="en">
    <head><meta charset="utf-8"><title>Store unavailable</title>
    <style>
      body{margin:0;min-height:100vh;display:grid;place-items:center;background:#07090c;color:#e8edf2;
           font-family:"Inter",system-ui,sans-serif;}
      .box{max-width:32rem;padding:2rem;text-align:center;}
      h1{font-family:"Space Grotesk",system-ui,sans-serif;font-size:1.4rem;margin:0 0 .75rem;}
      p{color:#8b95a3;line-height:1.6;margin:0 0 1.5rem;}
      code{color:#c6ff00;font-family:ui-monospace,Consolas,monospace;}
      a{display:inline-block;padding:.75rem 1.5rem;border:1px solid #00f0ff;color:#00f0ff;
        text-decoration:none;font-size:.85rem;letter-spacing:.1em;text-transform:uppercase;}
    </style></head>
    <body><div class="box">
      <h1>Store temporarily unavailable</h1>
      <p>We could not reach the database. Please make sure MySQL is running in the XAMPP
         Control Panel, then reload this page.<br><br><code>ERROR: DB_UNREACHABLE</code></p>
      <a href="">Try again</a>
    </div></body>
    </html>
    HTML;
    exit;
}
