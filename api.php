<?php
require_once 'config.php';
require_once 'vendor/autoload.php';

\Midtrans\Config::$serverKey = 'SB-Mid-server-BiPEZ8YxMZheywHq49sAQthl';
\Midtrans\Config::$isProduction = false;
\Midtrans\Config::$isSanitized = true;
\Midtrans\Config::$is3ds = true;

header('Content-Type: application/json');

$request_method = $_SERVER["REQUEST_METHOD"];

try {
    switch ($request_method) {
        case 'GET':
            get_products();
            break;
        case 'POST':
            $data = json_decode(file_get_contents('php://input'), true);
            if (isset($data['action'])) {
                if ($data['action'] === 'apply_voucher') {
                    apply_voucher($data);
                } elseif ($data['action'] === 'create_transaction') {
                    create_transaction($data);
                } elseif ($data['action'] === 'apply_voucher') {
                    apply_voucher($data);
                }
            } else {
                header("HTTP/1.0 400 Bad Request");
                echo json_encode(["error" => "Missing action in request"]);
            }
            break;
        default:
            header("HTTP/1.0 405 Method Not Allowed");
            echo json_encode(["error" => "Invalid request method"]);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["error" => $e->getMessage()]);
}
function get_products() {
    global $db;
    try {
        $query = "SELECT * FROM products";
        $statement = $db->prepare($query);
        $statement->execute();
        $products = $statement->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($products);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => $e->getMessage()]);
    }
}

function apply_voucher($data) {
    global $db;

    if (!isset($data['voucher_code'])) {
        header("HTTP/1.0 400 Bad Request");
        echo json_encode(["error" => "Missing voucher code"]);
        return;
    }

    $voucher_code = $data['voucher_code'];

    try {
        $stmt = $db->prepare("SELECT id, discount_amount, is_used FROM vouchers WHERE code = ?");
        $stmt->execute([$voucher_code]);
        $voucher = $stmt->fetch();

        if ($voucher && $voucher['is_used'] == 0) {
            $discount = intval($voucher['discount_amount']);

            // Get all products
            $stmt = $db->prepare("SELECT id, price FROM products");
            $stmt->execute();
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $discounted_prices = [];
            foreach ($products as $product) {
                $discounted_price = max(0, $product['price'] - $discount);
                $discounted_prices[] = [
                    'id' => $product['id'],
                    'discounted_price' => $discounted_price
                ];
            }

            // Mark voucher as used
            $stmt = $db->prepare("UPDATE vouchers SET is_used = 1, used_at = NOW() WHERE id = ?");
            $stmt->execute([$voucher['id']]);

            echo json_encode([
                'success' => true,
                'discount' => $discount,
                'discounted_prices' => $discounted_prices,
                'message' => "Voucher berhasil diterapkan ke semua produk!"
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => $voucher ? "Kode voucher sudah digunakan!" : "Kode voucher tidak valid!"
            ]);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["error" => $e->getMessage()]);
    }
}

function create_transaction($data) {
    global $db;

    if (!isset($data['product_id']) || !isset($data['product_name']) || !isset($data['product_price'])) {
        header("HTTP/1.0 400 Bad Request");
        echo json_encode(["error" => "Missing required fields"]);
        return;
    }

    $product_id = $data['product_id'];
    $product_name = $data['product_name'];
    $product_price = intval($data['product_price']);
    $discount = isset($data['discount']) ? intval($data['discount']) : 0;
    $total_price = $product_price - $discount;

    if ($total_price < 0) {
        $total_price = 0;
    }

    $order_id = time();

    $transaction_details = array(
        'order_id' => $order_id,
        'gross_amount' => $total_price,
    );

    $item_details = array(
        array(
            'id' => $product_id,
            'price' => $product_price,
            'quantity' => 1,
            'name' => $product_name
        )
    );

    $customer_details = array(
        'first_name' => "Pembeli",
        'last_name' => "Satu",
        'email' => "pembeli@example.com",
        'phone' => "081234567890",
    );

    $transaction = array(
        'transaction_details' => $transaction_details,
        'item_details' => $item_details,
        'customer_details' => $customer_details,
        'enabled_payments' => array('other_qris'),
    );

    try {
        $snap_token = \Midtrans\Snap::getSnapToken($transaction);
        $snap_url = \Midtrans\Snap::createTransaction($transaction)->redirect_url;

        $stmt = $db->prepare("INSERT INTO transaksi (order_id, product_id, product_name, price, tanggal, status) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$order_id, $product_id, $product_name, $total_price, date("Y-m-d"), 'success']);

        echo json_encode([
            'success' => true,
            'snap_token' => $snap_token,
            'snap_url' => $snap_url
        ]);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}

// Endpoint untuk menerima notifikasi dari Midtrans
// Endpoint untuk menerima notifikasi dari Midtrans
function midtrans_notification() {
    global $db;

    // Ambil notifikasi dari Midtrans
    $notif = new \Midtrans\Notification();

    $order_id = $notif->order_id;
    $transaction_status = $notif->transaction_status;

    try {
        // Perbarui status transaksi di database berdasarkan notifikasi Midtrans
        $stmt = $db->prepare("UPDATE transaksi SET status = ? WHERE order_id = ?");
        $stmt->execute([$transaction_status, $order_id]);

        echo json_encode(["success" => true]);
    } catch (Exception $e) {
        echo json_encode(["error" => $e->getMessage()]);
    }
}
