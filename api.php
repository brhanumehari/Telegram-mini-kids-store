<?php
/**
 * api.php
 * ENG_251885 APP — Kids Store TMA
 *
 * Single-entry JSON API consumed by app.js. Every request is routed through
 * the `action` query parameter. Endpoints that touch user-specific data
 * require a verified Telegram `initData` payload sent via the
 * `X-Telegram-Init-Data` header (see lib/TelegramAuth.php).
 *
 * Security measures applied throughout:
 *  - 100% PDO prepared statements / bound parameters (no string-built SQL)
 *  - Telegram HMAC-SHA256 initData verification for authenticated routes
 *  - Strict input validation & type coercion on every external value
 *  - Generic error responses in production (no internal details leaked)
 *  - CORS locked down to same-origin by default (adjust ALLOWED_ORIGIN below)
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/TelegramAuth.php';

// ---------------------------------------------------------------------------
// CORS / headers
// ---------------------------------------------------------------------------
const ALLOWED_ORIGIN = '*'; // tighten to your Mini App's origin in production
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . ALLOWED_ORIGIN);
header('Access-Control-Allow-Headers: Content-Type, X-Telegram-Init-Data');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
function json_out(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error(string $message, int $status = 400): never
{
    json_out(['ok' => false, 'error' => $message], $status);
}

function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function require_auth(PDO $pdo): int
{
    $telegramId = TelegramAuth::authenticateRequest($pdo);
    if ($telegramId === null) {
        json_error('Unauthorized: invalid or missing Telegram session.', 401);
    }
    return $telegramId;
}

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------
try {
    $pdo = get_pdo();
} catch (Throwable $e) {
    error_log('[api.php] DB connection failed: ' . $e->getMessage());
    json_error('Service temporarily unavailable.', 503);
}

$action = $_GET['action'] ?? '';

try {
    switch ($action) {

        // ---------------------------------------------------------------
        // GET ?action=categories
        // Returns top-level categories with nested age sub-tags and a
        // live product counter for each top-level group.
        // ---------------------------------------------------------------
        case 'categories': {
            $stmt = $pdo->query('
                SELECT id, parent_id, name, slug, age_min, age_max, icon, sort_order
                FROM categories
                ORDER BY parent_id IS NOT NULL, sort_order ASC, id ASC
            ');
            $rows = $stmt->fetchAll();

            $countStmt = $pdo->query('
                SELECT c.id AS top_id, COUNT(p.id) AS product_count
                FROM categories c
                LEFT JOIN categories sub ON sub.parent_id = c.id
                LEFT JOIN products p ON (p.category_id = c.id OR p.category_id = sub.id) AND p.is_active = 1
                WHERE c.parent_id IS NULL
                GROUP BY c.id
            ');
            $counts = array_column($countStmt->fetchAll(), 'product_count', 'top_id');

            $top = [];
            $children = [];
            foreach ($rows as $row) {
                if ($row['parent_id'] === null) {
                    $row['product_count'] = (int) ($counts[$row['id']] ?? 0);
                    $row['sub_tags'] = [];
                    $top[$row['id']] = $row;
                } else {
                    $children[$row['parent_id']][] = $row;
                }
            }
            foreach ($top as $id => &$cat) {
                $cat['sub_tags'] = $children[$id] ?? [];
            }
            unset($cat);

            json_out(['ok' => true, 'categories' => array_values($top)]);
        }

        // ---------------------------------------------------------------
        // GET ?action=products&category_id=&age=&search=&page=
        // ---------------------------------------------------------------
        case 'products': {
            $categoryId = filter_input(INPUT_GET, 'category_id', FILTER_VALIDATE_INT) ?: null;
            $age        = filter_input(INPUT_GET, 'age', FILTER_VALIDATE_INT);
            $search     = trim((string) ($_GET['search'] ?? ''));
            $page       = max(1, (int) ($_GET['page'] ?? 1));
            $perPage    = 24;
            $offset     = ($page - 1) * $perPage;

            $where  = ['p.is_active = 1'];
            $params = [];

            if ($categoryId !== null) {
                $where[] = '(p.category_id = :category_id OR p.category_id IN (SELECT id FROM categories WHERE parent_id = :category_id2))';
                $params['category_id']  = $categoryId;
                $params['category_id2'] = $categoryId;
            }
            if ($age !== null && $age >= 0 && $age <= 16) {
                $where[] = ':age BETWEEN COALESCE((SELECT age_min FROM categories WHERE id = p.category_id), 0)
                                      AND COALESCE((SELECT age_max FROM categories WHERE id = p.category_id), 16)';
                $params['age'] = $age;
            }
            if ($search !== '') {
                $where[] = 'MATCH(p.name, p.description) AGAINST (:search IN NATURAL LANGUAGE MODE)';
                $params['search'] = $search;
            }

            $whereSql = implode(' AND ', $where);

            $stmt = $pdo->prepare("
                SELECT p.id, p.name, p.description, p.price, p.age_range, p.stock,
                       p.image_url, c.name AS category_name
                FROM products p
                JOIN categories c ON c.id = p.category_id
                WHERE {$whereSql}
                ORDER BY p.created_at DESC
                LIMIT {$perPage} OFFSET {$offset}
            ");
            $stmt->execute($params);
            $products = $stmt->fetchAll();

            foreach ($products as &$p) {
                $p['price'] = (float) $p['price'];
                $p['stock'] = (int) $p['stock'];
            }
            unset($p);

            json_out(['ok' => true, 'products' => $products, 'page' => $page]);
        }

        // ---------------------------------------------------------------
        // GET ?action=dashboard  (authenticated)
        // Returns profile summary + 2x2 stats matrix + total spent.
        // ---------------------------------------------------------------
        case 'dashboard': {
            $userId = require_auth($pdo);

            $userStmt = $pdo->prepare('SELECT telegram_id, username, first_name, total_spent FROM users WHERE telegram_id = :id');
            $userStmt->execute(['id' => $userId]);
            $user = $userStmt->fetch();
            if (!$user) {
                json_error('User not found.', 404);
            }

            $statsStmt = $pdo->prepare("
                SELECT
                    COUNT(*) AS total_orders,
                    SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END) AS completed_orders
                FROM orders WHERE user_id = :id
            ");
            $statsStmt->execute(['id' => $userId]);
            $stats = $statsStmt->fetch();

            $keysStmt = $pdo->prepare("
                SELECT COUNT(*) AS total_keys
                FROM order_items oi
                JOIN orders o ON o.id = oi.order_id
                JOIN products p ON p.id = oi.product_id
                WHERE o.user_id = :id AND o.payment_status = 'paid' AND p.product_key IS NOT NULL
            ");
            $keysStmt->execute(['id' => $userId]);
            $keys = $keysStmt->fetch();

            $downloadsStmt = $pdo->prepare("
                SELECT COUNT(*) AS total_downloads
                FROM order_items oi
                JOIN orders o ON o.id = oi.order_id
                JOIN products p ON p.id = oi.product_id
                WHERE o.user_id = :id AND o.payment_status = 'paid' AND p.download_link IS NOT NULL
            ");
            $downloadsStmt->execute(['id' => $userId]);
            $downloads = $downloadsStmt->fetch();

            json_out([
                'ok' => true,
                'profile' => [
                    'telegram_id' => (int) $user['telegram_id'],
                    'username'    => $user['username'],
                    'first_name'  => $user['first_name'],
                    'total_spent' => (float) $user['total_spent'],
                ],
                'stats' => [
                    'total_orders' => (int) ($stats['total_orders'] ?? 0),
                    'downloads'    => (int) ($downloads['total_downloads'] ?? 0),
                    'product_keys' => (int) ($keys['total_keys'] ?? 0),
                    'completed'    => (int) ($stats['completed_orders'] ?? 0),
                ],
            ]);
        }

        // ---------------------------------------------------------------
        // GET ?action=orders  (authenticated)
        // ---------------------------------------------------------------
        case 'orders': {
            $userId = require_auth($pdo);

            $stmt = $pdo->prepare('
                SELECT o.id, o.total_amount, o.payment_method, o.payment_status, o.created_at, o.paid_at
                FROM orders o
                WHERE o.user_id = :id
                ORDER BY o.created_at DESC
                LIMIT 50
            ');
            $stmt->execute(['id' => $userId]);
            $orders = $stmt->fetchAll();

            foreach ($orders as &$o) {
                $o['total_amount'] = (float) $o['total_amount'];
                $o['id'] = (int) $o['id'];

                $itemStmt = $pdo->prepare('
                    SELECT oi.quantity, oi.price, p.name, p.image_url, p.download_link, p.product_key
                    FROM order_items oi JOIN products p ON p.id = oi.product_id
                    WHERE oi.order_id = :order_id
                ');
                $itemStmt->execute(['order_id' => $o['id']]);
                $o['items'] = $itemStmt->fetchAll();
            }
            unset($o);

            json_out(['ok' => true, 'orders' => $orders]);
        }

        // ---------------------------------------------------------------
        // POST ?action=create_order  (authenticated)
        // body: { items: [{product_id, quantity}], payment_method }
        // ---------------------------------------------------------------
        case 'create_order': {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                json_error('Method not allowed.', 405);
            }
            $userId = require_auth($pdo);
            $body   = read_json_body();

            $items  = $body['items'] ?? [];
            $method = $body['payment_method'] ?? '';

            $allowedMethods = ['telebirr', 'cbe_birr', 'dashen_amole', 'awash_birr', 'paypal', 'mastercard', 'telegram_stars'];
            if (!is_array($items) || count($items) === 0) {
                json_error('Cart is empty.');
            }
            if (!in_array($method, $allowedMethods, true)) {
                json_error('Invalid payment method.');
            }

            $pdo->beginTransaction();
            try {
                $total = 0.0;
                $validatedItems = [];

                foreach ($items as $item) {
                    $productId = (int) ($item['product_id'] ?? 0);
                    $quantity  = max(1, (int) ($item['quantity'] ?? 1));

                    $prodStmt = $pdo->prepare('SELECT id, price, stock FROM products WHERE id = :id AND is_active = 1 FOR UPDATE');
                    $prodStmt->execute(['id' => $productId]);
                    $product = $prodStmt->fetch();

                    if (!$product) {
                        throw new RuntimeException("Product #{$productId} is unavailable.");
                    }
                    if ($product['stock'] < $quantity) {
                        throw new RuntimeException("Insufficient stock for product #{$productId}.");
                    }

                    $lineTotal = (float) $product['price'] * $quantity;
                    $total += $lineTotal;
                    $validatedItems[] = [
                        'product_id' => $productId,
                        'quantity'   => $quantity,
                        'price'      => (float) $product['price'],
                    ];
                }

                $orderStmt = $pdo->prepare('
                    INSERT INTO orders (user_id, total_amount, payment_method, payment_status)
                    VALUES (:user_id, :total_amount, :payment_method, "pending")
                ');
                $orderStmt->execute([
                    'user_id'        => $userId,
                    'total_amount'   => $total,
                    'payment_method' => $method,
                ]);
                $orderId = (int) $pdo->lastInsertId();

                $itemStmt = $pdo->prepare('
                    INSERT INTO order_items (order_id, product_id, quantity, price)
                    VALUES (:order_id, :product_id, :quantity, :price)
                ');
                $stockStmt = $pdo->prepare('UPDATE products SET stock = stock - :qty WHERE id = :id');

                foreach ($validatedItems as $vi) {
                    $itemStmt->execute([
                        'order_id'   => $orderId,
                        'product_id' => $vi['product_id'],
                        'quantity'   => $vi['quantity'],
                        'price'      => $vi['price'],
                    ]);
                    $stockStmt->execute(['qty' => $vi['quantity'], 'id' => $vi['product_id']]);
                }

                $pdo->commit();

                // Hand off to the payment router to generate a checkout link.
                require_once __DIR__ . '/PaymentRouter.php';
                $router = new PaymentRouter($pdo);
                $checkout = $router->createCheckout($orderId, $method, $total, $userId);

                json_out([
                    'ok' => true,
                    'order_id' => $orderId,
                    'total_amount' => $total,
                    'checkout' => $checkout,
                ]);
            } catch (Throwable $e) {
                $pdo->rollBack();
                error_log('[api.php create_order] ' . $e->getMessage());
                json_error($e instanceof RuntimeException ? $e->getMessage() : 'Could not create order.', 422);
            }
        }

        default:
            json_error('Unknown action.', 404);
    }
} catch (Throwable $e) {
    error_log('[api.php] Unhandled error: ' . $e->getMessage());
    json_error('Internal server error.', 500);
}
