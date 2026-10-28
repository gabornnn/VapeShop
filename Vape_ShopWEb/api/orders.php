<?php
/**
 * VAPE CO. — customer orders API.
 * Vape_ShopWEb / api / orders.php
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/api_helper.php';
require_once __DIR__ . '/../includes/functions.php';

require_api_login();

$user = current_user();
$userId = (int) $user['id'];
$pdo = db();

$method = api_method();

if ($method === 'GET') {
    $orderId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    
    if ($orderId > 0) {
        $stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$orderId, $userId]);
        $order = $stmt->fetch();
        
        if (!$order) {
            api_fail('NOT_FOUND', 'Order not found.', 404);
        }
        
        $istmt = $pdo->prepare('SELECT * FROM order_items WHERE order_id = ? ORDER BY id');
        $istmt->execute([$orderId]);
        $items = $istmt->fetchAll();
        
        $hstmt = $pdo->prepare('SELECT * FROM order_status_history WHERE order_id = ? ORDER BY changed_at ASC, id ASC');
        $hstmt->execute([$orderId]);
        $history = $hstmt->fetchAll();
        
        api_ok([
            'order' => $order,
            'items' => $items,
            'history' => $history
        ]);
    } else {
        $stmt = $pdo->prepare('SELECT o.*, (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) as item_count FROM orders o WHERE o.user_id = ? ORDER BY o.created_at DESC');
        $stmt->execute([$userId]);
        $orders = $stmt->fetchAll();
        api_ok(['orders' => $orders]);
    }
} else {
    api_fail('METHOD_NOT_ALLOWED', 'This endpoint only accepts GET requests.', 405);
}
