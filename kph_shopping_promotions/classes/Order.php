<?php
// ============================================================
// KameraPH | Shopping (commerce) | Order
// ============================================================
// Checkout, orders, cancellation, COD payment and refunds.
//
// All multi-step operations run in one InnoDB transaction with row locks:
//   - per-user lock (user row) serialises one user's checkouts,
//   - cart_items locked ascending ProductID before validation,
//   - product rows locked ascending ProductID before stock changes,
//   - order row locked FOR UPDATE for status/cancel/refund.
//
// Prices always come from the DB (never the client). Checkout is
// idempotent per (UserID, CheckoutKey) with a request hash.
// ============================================================

require_once __DIR__ . '/../../kph_user_account_identity/config/Database.php';
require_once __DIR__ . '/../../kph_user_account_identity/classes/Validator.php';
require_once __DIR__ . '/../../kph_user_account_identity/classes/Exceptions.php';
require_once __DIR__ . '/Product.php';

class Order
{
    private PDO $db;
    private Product $products;

    // OrderStatus transition map (see plan §5.1).
    private const TRANSITIONS = [
        'Pending'        => ['Confirmed', 'Cancelled'],
        'Confirmed'      => ['Processing', 'Cancelled'],
        'Processing'     => ['Shipped', 'Cancelled'],
        'Shipped'        => ['OutForDelivery', 'Delivered'],
        'OutForDelivery' => ['Delivered'],
        'Delivered'      => ['Refunded'],
        'Cancelled'      => [],
        'Refunded'       => [],
    ];

    // Statuses an admin may set through the generic status endpoint.
    private const ADMIN_SETTABLE = ['Confirmed', 'Processing', 'Shipped', 'OutForDelivery', 'Delivered'];
    private const SELLER_SETTABLE = ['Confirmed', 'Processing', 'Shipped', 'OutForDelivery', 'Delivered'];

    public function __construct()
    {
        $this->db = Database::getConnection();
        $this->products = new Product();
    }

    // ---- public API --------------------------------------------------------

    // Authoritative totals for the current cart / requested items (no writes).
    public function preview(int $userId, array $data): array
    {
        $items = $this->parseItems($data['Items'] ?? null);
        $shippingMethod = Validator::oneOf($data['ShippingMethod'] ?? 'Standard', ['Standard', 'Pickup'], 'ShippingMethod');
        $cfg = $this->shippingConfig();

        $rows = $this->loadProductsForItems($items);
        $groups = $this->groupBySeller($items, $rows);

        $out = [];
        $subtotal = 0.0;
        $shipping = 0.0;
        foreach ($groups as $g) {
            $fee = $this->shippingFee($shippingMethod, $g['subtotal'], $cfg);
            $subtotal += $g['subtotal'];
            $shipping += $fee;
            $out[] = [
                'SellerID'    => $g['SellerID'],
                'Subtotal'    => $g['subtotal'],
                'ShippingFee' => $fee,
                'Total'       => round($g['subtotal'] + $fee, 2),
                'Items'       => $g['items'],
            ];
        }

        return [
            'ShippingMethod' => $shippingMethod,
            'Groups'         => $out,
            'Subtotal'       => round($subtotal, 2),
            'ShippingFee'    => round($shipping, 2),
            'DiscountAmount' => 0.0,
            'Total'          => round($subtotal + $shipping, 2),
        ];
    }

    // Creates one order per seller under a single CheckoutKey.
    public function create(int $userId, array $data): array
    {
        $items = $this->parseItems($data['Items'] ?? null);
        $shippingMethod = Validator::oneOf($data['ShippingMethod'] ?? 'Standard', ['Standard', 'Pickup'], 'ShippingMethod');
        $cfg = $this->shippingConfig();

        $checkoutKey = isset($data['CheckoutKey']) ? trim((string)$data['CheckoutKey']) : '';
        if ($checkoutKey === '') {
            $checkoutKey = bin2hex(random_bytes(16));
        }
        if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $checkoutKey)) {
            throw new InvalidArgumentException("CheckoutKey is invalid.");
        }

        $address = $this->resolveAddress($userId, $shippingMethod, $data, $cfg);
        $requestHash = $this->requestHash($items, $shippingMethod, $address);

        $attempt = 0;
        while (true) {
            $attempt++;
            try {
                return $this->createAttempt($userId, $checkoutKey, $requestHash, $items, $shippingMethod, $address, $cfg);
            } catch (PDOException $e) {
                $deadlock = ((string)$e->getCode() === '40001');
                if ($deadlock && $attempt < 3) {
                    $existing = $this->existingOrders($userId, $checkoutKey);
                    if ($existing !== null) {
                        return $existing;
                    }
                    continue;
                }
                throw $e;
            }
        }
    }

    public function list(int $userId, array $filters = []): array
    {
        $where = ['UserID = ?'];
        $params = [$userId];
        if (!empty($filters['status'])) {
            $where[] = 'OrderStatus = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['checkout'])) {
            $where[] = 'CheckoutKey = ?';
            $params[] = $filters['checkout'];
        }
        $sql = "SELECT * FROM `orders` WHERE " . implode(' AND ', $where) . " ORDER BY CreatedAt DESC, OrderID DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function get(int $orderId, ?int $userId, bool $isAdmin): array
    {
        $order = $this->findOrder($orderId);
        if ($order === null) {
            throw new OutOfBoundsException("Order not found.");
        }
        if (!$isAdmin && (int)$order['UserID'] !== (int)$userId) {
            throw new ForbiddenException("You cannot view this order.");
        }
        $order['Items'] = $this->orderItems($orderId);
        $order['Timeline'] = $this->orderTimeline($orderId);
        return $order;
    }

    // Buyer cancels Pending/Confirmed; admin cancels Pending/Confirmed/Processing.
    public function cancel(int $orderId, int $actorId, bool $isAdmin): array
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("SELECT * FROM `orders` WHERE OrderID = ? FOR UPDATE");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch();
            if (!$order) {
                throw new OutOfBoundsException("Order not found.");
            }
            if (!$isAdmin && (int)$order['UserID'] !== $actorId) {
                throw new ForbiddenException("You cannot cancel this order.");
            }
            $status = $order['OrderStatus'];
            if ($status === 'Cancelled') {
                $this->db->commit();
                return $this->get($orderId, $actorId, $isAdmin);
            }
            $allowed = $isAdmin ? ['Pending', 'Confirmed', 'Processing'] : ['Pending', 'Confirmed'];
            if (!in_array($status, $allowed, true)) {
                throw new InvalidArgumentException("This order cannot be cancelled in its current status.");
            }

            $this->restoreStock((int)$orderId, $order);

            $stmt = $this->db->prepare(
                "UPDATE `orders` SET OrderStatus = 'Cancelled', CancelledAt = NOW(), UpdatedAt = NOW() WHERE OrderID = ?"
            );
            $stmt->execute([$orderId]);
            $this->addStatusHistory((int)$orderId, $status, 'Cancelled', $isAdmin ? 'Cancelled by admin' : 'Cancelled by buyer', $actorId);
            if ($isAdmin) {
                $this->audit($actorId, 'order_status', 'order', (int)$orderId, 'Cancelled order #' . $order['OrderNumber']);
            }

            $this->db->commit();
            return $this->get($orderId, $actorId, $isAdmin);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    // Buyer-only: Shipped/OutForDelivery -> Delivered. Never changes payment.
    public function confirmReceipt(int $orderId, int $userId): array
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("SELECT * FROM `orders` WHERE OrderID = ? FOR UPDATE");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch();
            if (!$order) {
                throw new OutOfBoundsException("Order not found.");
            }
            if ((int)$order['UserID'] !== $userId) {
                throw new ForbiddenException("You cannot confirm this order.");
            }
            $status = $order['OrderStatus'];
            if (!in_array($status, ['Shipped', 'OutForDelivery'], true)) {
                throw new InvalidArgumentException("Only shipped orders can be confirmed as received.");
            }

            $stmt = $this->db->prepare(
                "UPDATE `orders` SET OrderStatus = 'Delivered', UpdatedAt = NOW() WHERE OrderID = ?"
            );
            $stmt->execute([$orderId]);
            $this->addStatusHistory((int)$orderId, $status, 'Delivered', 'Confirmed received by buyer', $userId);

            $this->db->commit();
            return $this->get($orderId, $userId, false);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    // Owning approved seller or admin: Delivered + Unpaid -> Paid.
    public function confirmCodPayment(int $orderId, int $actorId, bool $isAdmin): array
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("SELECT * FROM `orders` WHERE OrderID = ? FOR UPDATE");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch();
            if (!$order) {
                throw new OutOfBoundsException("Order not found.");
            }
            if ($isAdmin) {
                // admin may confirm any order
            } else {
                $this->requireSellerOwnsAndApproved($order, $actorId);
            }

            if ($order['OrderStatus'] !== 'Delivered') {
                throw new InvalidArgumentException("COD can only be confirmed after delivery.");
            }
            if ($order['PaymentStatus'] !== 'Unpaid') {
                throw new InvalidArgumentException("This order is already marked as paid.");
            }

            $stmt = $this->db->prepare(
                "UPDATE `orders` SET PaymentStatus = 'Paid', UpdatedAt = NOW() WHERE OrderID = ?"
            );
            $stmt->execute([$orderId]);
            $this->addPaymentHistory((int)$orderId, $order['PaymentStatus'], 'Paid', $actorId, null, 'COD cash collected');
            if ($isAdmin) {
                $this->audit($actorId, 'order_status', 'order', (int)$orderId, 'Confirmed COD payment for order #' . $order['OrderNumber']);
            }

            $this->db->commit();
            return $this->get($orderId, $actorId, true);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    // Validated status transition (seller own / admin).
    // Seller/admin Delivered requires CodCollected.
    public function updateStatus(int $orderId, int $actorId, bool $isAdmin, array $data): array
    {
        $toStatus = Validator::oneOf($data['OrderStatus'] ?? $data['Status'] ?? null, ['Confirmed', 'Processing', 'Shipped', 'OutForDelivery', 'Delivered'], 'OrderStatus');
        $allowed = $isAdmin ? self::ADMIN_SETTABLE : self::SELLER_SETTABLE;
        if (!in_array($toStatus, $allowed, true)) {
            throw new ForbiddenException("You cannot set this order status.");
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("SELECT * FROM `orders` WHERE OrderID = ? FOR UPDATE");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch();
            if (!$order) {
                throw new OutOfBoundsException("Order not found.");
            }
            if ($isAdmin) {
                // allowed
            } else {
                $this->requireSellerOwnsAndApproved($order, $actorId);
            }

            $from = $order['OrderStatus'];
            if (!in_array($toStatus, self::TRANSITIONS[$from] ?? [], true)) {
                throw new InvalidArgumentException("Cannot change order status from $from to $toStatus.");
            }

            $codCollected = null;
            $newPayment = $order['PaymentStatus'];
            if ($toStatus === 'Delivered') {
                if (!array_key_exists('CodCollected', $data) || $data['CodCollected'] === null) {
                    throw new InvalidArgumentException("CodCollected is required when marking an order Delivered.");
                }
                $flag = $data['CodCollected'];
                if (!is_bool($flag) && $flag !== 0 && $flag !== 1 && $flag !== '0' && $flag !== '1' && $flag !== 'true' && $flag !== 'false') {
                    throw new InvalidArgumentException("CodCollected must be true or false.");
                }
                $codCollected = ($flag === true || $flag === 1 || $flag === '1' || $flag === 'true');
                $newPayment = $codCollected ? 'Paid' : $order['PaymentStatus'];
            }

            $stmt = $this->db->prepare(
                "UPDATE `orders` SET OrderStatus = ?, PaymentStatus = ?, UpdatedAt = NOW() WHERE OrderID = ?"
            );
            $stmt->execute([$toStatus, $newPayment, $orderId]);
            $this->addStatusHistory((int)$orderId, $from, $toStatus, $isAdmin ? 'Updated by admin' : 'Updated by seller', $actorId);

            if ($toStatus === 'Delivered') {
                $this->addPaymentHistory((int)$orderId, $order['PaymentStatus'], $newPayment, $actorId, null, $codCollected ? 'COD collected' : 'COD not collected');
            }
            if ($isAdmin) {
                $this->audit($actorId, 'order_status', 'order', (int)$orderId, "Status $from -> $toStatus for order #" . $order['OrderNumber']);
            }

            $this->db->commit();
            return $this->get($orderId, $actorId, true);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    // Admin-only manual refund: Delivered + Paid + reference -> Refunded.
    public function refund(int $orderId, int $adminId, array $data): array
    {
        $reference = Validator::text($data['Reference'] ?? null, 'Reference', 255);
        $note = Validator::text($data['Note'] ?? null, 'Note', 255, false);

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("SELECT * FROM `orders` WHERE OrderID = ? FOR UPDATE");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch();
            if (!$order) {
                throw new OutOfBoundsException("Order not found.");
            }
            if ($order['OrderStatus'] !== 'Delivered') {
                throw new InvalidArgumentException("Only delivered orders can be refunded.");
            }
            if ($order['PaymentStatus'] !== 'Paid') {
                throw new InvalidArgumentException("Only paid orders can be refunded.");
            }
            if ($order['OrderStatus'] === 'Refunded') {
                throw new InvalidArgumentException("This order was already refunded.");
            }

            $stmt = $this->db->prepare(
                "UPDATE `orders` SET OrderStatus = 'Refunded', PaymentStatus = 'Refunded', UpdatedAt = NOW() WHERE OrderID = ?"
            );
            $stmt->execute([$orderId]);
            $this->addStatusHistory((int)$orderId, 'Delivered', 'Refunded', 'Refunded by admin: ' . $reference, $adminId);
            $this->addPaymentHistory((int)$orderId, 'Paid', 'Refunded', $adminId, $reference, $note);
            $this->audit($adminId, 'refund', 'order', (int)$orderId, 'Refunded order #' . $order['OrderNumber'] . ' ref=' . $reference);

            $this->db->commit();
            return $this->get($orderId, $adminId, true);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    // ---- checkout internals ------------------------------------------------

    private function createAttempt(int $userId, string $checkoutKey, string $requestHash, array $items, string $shippingMethod, array $address, array $cfg): array
    {
        $this->db->beginTransaction();
        try {
            // 1. Serialise this user's checkouts.
            $stmt = $this->db->prepare("SELECT UserID FROM `user` WHERE UserID = ? FOR UPDATE");
            $stmt->execute([$userId]);
            if ($stmt->fetchColumn() === false) {
                throw new OutOfBoundsException("User not found.");
            }

            // 2. Idempotency check (after the user lock).
            $existing = $this->existingOrders($userId, $checkoutKey);
            if ($existing !== null) {
                $stmt = $this->db->prepare("SELECT RequestHash FROM `checkout_session` WHERE UserID = ? AND CheckoutKey = ?");
                $stmt->execute([$userId, $checkoutKey]);
                $row = $stmt->fetch();
                if ($row && hash_equals($row['RequestHash'], $requestHash)) {
                    $this->db->commit();
                    return $existing;
                }
                $this->db->rollBack();
                throw new ConflictException("This checkout key was already used with a different request.");
            }

            // 3. Lock the selected cart rows (ascending ProductID).
            $cartId = $this->getOrCreateCartId($userId);
            $ids = [];
            foreach ($items as $it) {
                $ids[] = $it['ProductID'];
            }
            $ids = array_values(array_unique($ids));
            sort($ids, SORT_NUMERIC);
            $cartQty = [];
            if (!empty($ids)) {
                $in = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $this->db->prepare(
                    "SELECT ProductID, Quantity FROM `cart_items` WHERE CartID = ? AND ProductID IN ($in) ORDER BY ProductID ASC FOR UPDATE"
                );
                $stmt->execute(array_merge([$cartId], $ids));
                foreach ($stmt->fetchAll() as $r) {
                    $cartQty[(int)$r['ProductID']] = (int)$r['Quantity'];
                }
            }
            foreach ($items as $it) {
                if (!isset($cartQty[$it['ProductID']]) || $cartQty[$it['ProductID']] < $it['Quantity']) {
                    throw new InvalidArgumentException("Not enough of product #" . $it['ProductID'] . " in your cart.");
                }
            }

            // 4. Lock product rows (ascending ProductID) and validate.
            $rows = [];
            if (!empty($ids)) {
                $in = implode(',', array_fill(0, count($ids), '?'));
                $stmt = $this->db->prepare("SELECT * FROM `product` WHERE ProductID IN ($in) ORDER BY ProductID ASC FOR UPDATE");
                $stmt->execute($ids);
                foreach ($stmt->fetchAll() as $r) {
                    $rows[(int)$r['ProductID']] = $r;
                }
            }
            foreach ($ids as $pid) {
                if (!isset($rows[$pid])) {
                    throw new InvalidArgumentException("Product #$pid is no longer available.");
                }
                $p = $rows[$pid];
                $need = $this->qtyFor($items, $pid);
                if ($p['Status'] !== 'Active' || (int)$p['IsApproved'] !== 1) {
                    throw new InvalidArgumentException($p['ProductName'] . " is not available for purchase.");
                }
                if ($p['SellerID'] !== null && !$this->products->sellerApproved((int)$p['SellerID'])) {
                    throw new InvalidArgumentException("The seller of " . $p['ProductName'] . " is not active.");
                }
                if ((int)$p['Stock'] < $need) {
                    throw new InvalidArgumentException("Not enough stock for " . $p['ProductName'] . ".");
                }
            }

            // 5. Group by seller.
            $groups = $this->groupBySeller($items, $rows);
            foreach ($groups as &$g) {
                $g['shippingFee'] = $this->shippingFee($shippingMethod, $g['subtotal'], $cfg);
                $g['total'] = round($g['subtotal'] + $g['shippingFee'], 2);
            }
            unset($g);

            // 6. Checkout session row.
            $stmt = $this->db->prepare("INSERT INTO `checkout_session` (UserID, CheckoutKey, RequestHash) VALUES (?, ?, ?)");
            $stmt->execute([$userId, $checkoutKey, $requestHash]);

            // 7. Orders + items + history + stock.
            $created = [];
            foreach ($groups as $g) {
                $orderId = $this->insertOrder($userId, $checkoutKey, $g, $shippingMethod, $address);

                foreach ($g['items'] as $line) {
                    $stmt = $this->db->prepare(
                        "INSERT INTO `order_items` (OrderID, ProductID, ProductName, UnitPrice, ImageURL, Quantity, Subtotal, SellerID)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                    );
                    $stmt->execute([
                        $orderId, $line['ProductID'], $line['ProductName'], $line['UnitPrice'],
                        $line['ImageURL'], $line['Quantity'], $line['Subtotal'], $g['SellerID']
                    ]);

                    $stmt = $this->db->prepare(
                        "UPDATE `product`
                         SET Stock = Stock - ?,
                             Status = CASE WHEN Status IN ('Active','OutOfStock')
                                 THEN (IF(Stock - ? > 0, 'Active', 'OutOfStock')) ELSE Status END,
                             UpdatedAt = NOW()
                         WHERE ProductID = ? AND Stock >= ?"
                    );
                    $stmt->execute([$line['Quantity'], $line['Quantity'], $line['ProductID'], $line['Quantity']]);
                    if ($stmt->rowCount() !== 1) {
                        throw new RuntimeException("Stock changed for " . $line['ProductName'] . ".");
                    }
                }

                $this->addStatusHistory($orderId, null, 'Pending', 'Order placed', $userId);
                $created[] = [
                    'OrderID'       => $orderId,
                    'OrderNumber'   => $this->findOrder($orderId)['OrderNumber'],
                    'SellerID'      => $g['SellerID'],
                    'Subtotal'      => $g['subtotal'],
                    'ShippingFee'   => $g['shippingFee'],
                    'Total'         => $g['total'],
                    'OrderStatus'   => 'Pending',
                    'PaymentStatus' => 'Unpaid',
                ];
            }

// 8. Partial cart update: subtract, delete the row at zero.
            //    cart_items has CHECK (Quantity 1..99), so a row must be
            //    removed rather than written with Quantity = 0.
            foreach ($items as $it) {
                $stmt = $this->db->prepare(
                    "DELETE FROM `cart_items`
                      WHERE CartID = ? AND ProductID = ? AND Quantity = ?"
                );
                $stmt->execute([$cartId, $it['ProductID'], $it['Quantity']]);

                $stmt = $this->db->prepare(
                    "UPDATE `cart_items` SET Quantity = Quantity - ?, UpdatedAt = NOW()
                      WHERE CartID = ? AND ProductID = ? AND Quantity > ?"
                );
                $stmt->execute([$it['Quantity'], $cartId, $it['ProductID'], $it['Quantity']]);
            }

            $this->db->commit();
            return ['CheckoutKey' => $checkoutKey, 'Orders' => $created];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    private function insertOrder(int $userId, string $checkoutKey, array $g, string $shippingMethod, array $address): int
    {
        $columns = [
            'OrderNumber', 'CheckoutKey', 'UserID', 'SellerID', 'AddressID',
            'RecipientName', 'Phone', 'HouseUnit', 'Street', 'Barangay', 'City', 'Province', 'PostalCode',
            'ShippingMethod', 'ShippingFee', 'DiscountAmount', 'Subtotal', 'Total',
            'OrderStatus', 'PaymentMethod', 'PaymentStatus'
        ];
        $placeholders = implode(',', array_fill(0, count($columns), '?'));
        $quotedColumns = '`' . implode('`,`', $columns) . '`';

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $values = [
                $this->generateOrderNumber(), $checkoutKey, $userId, $g['SellerID'], $address['AddressID'],
                $address['RecipientName'], $address['Phone'], $address['HouseUnit'], $address['Street'],
                $address['Barangay'], $address['City'], $address['Province'], $address['PostalCode'],
                $shippingMethod, $g['shippingFee'], 0.0, $g['subtotal'], $g['total'],
                'Pending', 'COD', 'Unpaid'
            ];
            try {
                $stmt = $this->db->prepare("INSERT INTO `orders` ($quotedColumns) VALUES ($placeholders)");
                $stmt->execute($values);
                return (int)$this->db->lastInsertId();
            } catch (PDOException $e) {
                $msg = $e->getMessage();
                $isOrderNumberCollision = ((string)$e->getCode() === '23000')
                    && (stripos($msg, 'uq_orders_OrderNumber') !== false || stripos($msg, 'OrderNumber') !== false);
                if (!$isOrderNumberCollision) {
                    throw $e;
                }
                if ($attempt === 3) {
                    throw new RuntimeException("Could not allocate a unique order number.");
                }
            }
        }
        throw new RuntimeException("Could not allocate a unique order number.");
    }

    private function existingOrders(int $userId, string $checkoutKey): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT OrderID, OrderNumber, SellerID, Subtotal, ShippingFee, Total, OrderStatus, PaymentStatus
             FROM `orders` WHERE UserID = ? AND CheckoutKey = ? ORDER BY OrderID ASC"
        );
        $stmt->execute([$userId, $checkoutKey]);
        $rows = $stmt->fetchAll();
        if (!$rows) {
            return null;
        }
        return ['CheckoutKey' => $checkoutKey, 'Orders' => $rows];
    }

    private function getOrCreateCartId(int $userId): int
    {
        $stmt = $this->db->prepare("SELECT CartID FROM `carts` WHERE UserID = ?");
        $stmt->execute([$userId]);
        $cartId = $stmt->fetchColumn();
        if ($cartId !== false) {
            return (int)$cartId;
        }
        $stmt = $this->db->prepare("INSERT INTO `carts` (UserID) VALUES (?)");
        $stmt->execute([$userId]);
        return (int)$this->db->lastInsertId();
    }

    // ---- helpers -----------------------------------------------------------

    private function parseItems($items): array
    {
        if (!is_array($items) || count($items) === 0) {
            throw new InvalidArgumentException("At least one item is required.");
        }
        $out = [];
        $seen = [];
        foreach ($items as $it) {
            if (!is_array($it)) {
                throw new InvalidArgumentException("Each item must be an object.");
            }
            $pid = (int)($it['ProductID'] ?? 0);
            if ($pid <= 0) {
                throw new InvalidArgumentException("ProductID must be a positive integer.");
            }
            if (isset($seen[$pid])) {
                throw new InvalidArgumentException("Duplicate ProductID in the checkout items.");
            }
            $seen[$pid] = true;

            $qty = $it['Quantity'] ?? 1;
            if (!is_numeric($qty) || (int)$qty != $qty) {
                throw new InvalidArgumentException("Quantity must be a whole number.");
            }
            $qty = (int)$qty;
            if ($qty < 1 || $qty > 99) {
                throw new InvalidArgumentException("Quantity must be between 1 and 99.");
            }
            $out[] = ['ProductID' => $pid, 'Quantity' => $qty];
        }
        return $out;
    }

    private function qtyFor(array $items, int $pid): int
    {
        foreach ($items as $it) {
            if ($it['ProductID'] === $pid) {
                return $it['Quantity'];
            }
        }
        return 0;
    }

    private function loadProductsForItems(array $items): array
    {
        $ids = [];
        foreach ($items as $it) {
            $ids[] = $it['ProductID'];
        }
        $ids = array_values(array_unique($ids));
        if (!$ids) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare("SELECT * FROM `product` WHERE ProductID IN ($in)");
        $stmt->execute($ids);
        $rows = [];
        foreach ($stmt->fetchAll() as $r) {
            $rows[(int)$r['ProductID']] = $r;
        }
        foreach ($ids as $pid) {
            if (!isset($rows[$pid])) {
                throw new InvalidArgumentException("Product #$pid is not available.");
            }
            $p = $rows[$pid];
            if ($p['Status'] !== 'Active' || (int)$p['IsApproved'] !== 1) {
                throw new InvalidArgumentException($p['ProductName'] . " is not available for purchase.");
            }
            if ($p['SellerID'] !== null && !$this->products->sellerApproved((int)$p['SellerID'])) {
                throw new InvalidArgumentException("The seller of " . $p['ProductName'] . " is not active.");
            }
            if ((int)$p['Stock'] < $this->qtyFor($items, $pid)) {
                throw new InvalidArgumentException("Not enough stock for " . $p['ProductName'] . ".");
            }
        }
        return $rows;
    }

    private function groupBySeller(array $items, array $rows): array
    {
        $groups = [];
        foreach ($items as $it) {
            $p = $rows[$it['ProductID']];
            $sellerId = $p['SellerID'] !== null ? (int)$p['SellerID'] : null;
            $key = $sellerId === null ? 'platform' : (string)$sellerId;
            if (!isset($groups[$key])) {
                $groups[$key] = ['SellerID' => $sellerId, 'items' => [], 'subtotal' => 0.0];
            }
            $lineSub = round((float)$p['BasePrice'] * $it['Quantity'], 2);
            $groups[$key]['items'][] = [
                'ProductID'   => $it['ProductID'],
                'ProductName' => $p['ProductName'],
                'UnitPrice'   => (float)$p['BasePrice'],
                'ImageURL'    => $p['ImageURL'],
                'Quantity'    => $it['Quantity'],
                'Subtotal'    => $lineSub,
            ];
            $groups[$key]['subtotal'] = round($groups[$key]['subtotal'] + $lineSub, 2);
        }
        return $groups;
    }

    private function shippingConfig(): array
    {
        $c = Database::getConfig();
        $s = $c['shipping'] ?? [];
        return [
            'flat_fee'                => (float)($s['flat_fee'] ?? 150.0),
            'free_threshold'          => (float)($s['free_threshold'] ?? 2000.0),
            'pickup_fee'              => (float)($s['pickup_fee'] ?? 0.0),
            'pickup_requires_address' => (bool)($s['pickup_requires_address'] ?? false),
        ];
    }

    private function shippingFee(string $method, float $subtotal, array $cfg): float
    {
        if ($method === 'Pickup') {
            return round($cfg['pickup_fee'], 2);
        }
        return $subtotal >= $cfg['free_threshold'] ? 0.0 : round($cfg['flat_fee'], 2);
    }

    private function resolveAddress(int $userId, string $shippingMethod, array $data, array $cfg): array
    {
        $empty = [
            'AddressID' => null, 'RecipientName' => null, 'Phone' => null, 'HouseUnit' => null,
            'Street' => null, 'Barangay' => null, 'City' => null, 'Province' => null, 'PostalCode' => null
        ];

        if ($shippingMethod === 'Pickup' && !$cfg['pickup_requires_address']) {
            return $empty;
        }

        $addressId = isset($data['AddressID']) ? (int)$data['AddressID'] : 0;
        if ($addressId > 0) {
            $stmt = $this->db->prepare("SELECT * FROM `user_address` WHERE AddressID = ? AND UserID = ?");
            $stmt->execute([$addressId, $userId]);
            $row = $stmt->fetch();
            if (!$row) {
                throw new InvalidArgumentException("The selected address does not belong to you.");
            }
            return $this->addressSnapshot($row, $addressId);
        }

        // Accept a new address supplied inline.
        $first = Validator::name($data['RecipientFirstName'] ?? null, 'Recipient first name');
        $last = Validator::name($data['RecipientLastName'] ?? null, 'Recipient last name');
        $phone = Validator::phone($data['RecipientPhone'] ?? $data['Phone'] ?? null, 'Recipient phone');
        $house = Validator::text($data['HouseUnit'] ?? null, 'House / unit', 100);
        $street = Validator::text($data['Street'] ?? null, 'Street', 255);
        $barangay = Validator::text($data['Barangay'] ?? null, 'Barangay', 100);
        $city = Validator::text($data['City'] ?? null, 'City', 100);
        $province = Validator::text($data['Province'] ?? null, 'Province', 100);
        $postal = Validator::text($data['PostalCode'] ?? null, 'Postal code', 20);

        return [
            'AddressID'      => null,
            'RecipientName'  => $first . ' ' . $last,
            'Phone'          => $phone,
            'HouseUnit'      => $house,
            'Street'         => $street,
            'Barangay'       => $barangay,
            'City'           => $city,
            'Province'       => $province,
            'PostalCode'     => $postal,
        ];
    }

    private function addressSnapshot(array $row, int $addressId): array
    {
        return [
            'AddressID'     => $addressId,
            'RecipientName' => trim($row['RecipientFirstName'] . ' ' . $row['RecipientLastName']),
            'Phone'         => $row['PhoneNumber'],
            'HouseUnit'     => $row['HouseUnitNumber'],
            'Street'        => $row['Street'],
            'Barangay'      => $row['Barangay'],
            'City'          => $row['City'],
            'Province'      => $row['Province'],
            'PostalCode'    => $row['PostalCode'],
        ];
    }

    private function requestHash(array $items, string $shippingMethod, array $address): string
    {
        $parts = [];
        foreach ($items as $it) {
            $parts[] = $it['ProductID'] . ':' . $it['Quantity'];
        }
        sort($parts, SORT_STRING);
        return hash('sha256', implode('|', $parts) . '|' . $shippingMethod . '|' . json_encode($address));
    }

    private function generateOrderNumber(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $suffix = '';
        for ($i = 0; $i < 8; $i++) {
            $suffix .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return 'KPH-' . date('Ymd') . '-' . $suffix;
    }

    private function findOrder(int $orderId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM `orders` WHERE OrderID = ?");
        $stmt->execute([$orderId]);
        return $stmt->fetch() ?: null;
    }

    private function orderItems(int $orderId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM `order_items` WHERE OrderID = ? ORDER BY OrderItemID ASC");
        $stmt->execute([$orderId]);
        return $stmt->fetchAll();
    }

    private function orderTimeline(int $orderId): array
    {
        $out = [];
        $stmt = $this->db->prepare(
            "SELECT FromStatus, ToStatus, Note, ChangedByUserID, CreatedAt FROM `order_status_history` WHERE OrderID = ? ORDER BY CreatedAt ASC, HistoryID ASC"
        );
        $stmt->execute([$orderId]);
        foreach ($stmt->fetchAll() as $r) {
            $out[] = ['Type' => 'status'] + $r;
        }
        $stmt = $this->db->prepare(
            "SELECT FromPaymentStatus, ToPaymentStatus, ConfirmedByUserID, CollectionMethod, Reference, Note, CreatedAt FROM `order_payment_history` WHERE OrderID = ? ORDER BY CreatedAt ASC, PaymentHistoryID ASC"
        );
        $stmt->execute([$orderId]);
        foreach ($stmt->fetchAll() as $r) {
            $out[] = ['Type' => 'payment'] + $r;
        }
        usort($out, function ($a, $b) {
            return strcmp((string)$a['CreatedAt'], (string)$b['CreatedAt']);
        });
        return $out;
    }

    private function addStatusHistory(int $orderId, ?string $from, string $to, ?string $note, ?int $changedBy): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO `order_status_history` (OrderID, FromStatus, ToStatus, Note, ChangedByUserID) VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->execute([$orderId, $from, $to, $note, $changedBy]);
    }

    private function addPaymentHistory(int $orderId, ?string $from, string $to, ?int $confirmedBy, ?string $reference, ?string $note): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO `order_payment_history` (OrderID, FromPaymentStatus, ToPaymentStatus, ConfirmedByUserID, CollectionMethod, Reference, Note)
             VALUES (?, ?, ?, ?, 'COD', ?, ?)"
        );
        $stmt->execute([$orderId, $from, $to, $confirmedBy, $reference, $note]);
    }

    private function restoreStock(int $orderId, array $order): void
    {
        $stmt = $this->db->prepare("SELECT ProductID, Quantity FROM `order_items` WHERE OrderID = ? ORDER BY ProductID ASC");
        $stmt->execute([$orderId]);
        $items = $stmt->fetchAll();
        foreach ($items as $line) {
            if ($line['ProductID'] === null) {
                continue;
            }
            $stmt = $this->db->prepare("SELECT Stock, Status FROM `product` WHERE ProductID = ? FOR UPDATE");
            $stmt->execute([(int)$line['ProductID']]);
            $p = $stmt->fetch();
            if (!$p) {
                continue;
            }
            $newStock = (int)$p['Stock'] + (int)$line['Quantity'];
            $newStatus = $p['Status'];
            if (in_array($p['Status'], ['Active', 'OutOfStock'], true)) {
                $newStatus = $newStock > 0 ? 'Active' : 'OutOfStock';
            }
            $stmt = $this->db->prepare("UPDATE `product` SET Stock = ?, Status = ?, UpdatedAt = NOW() WHERE ProductID = ?");
            $stmt->execute([$newStock, $newStatus, (int)$line['ProductID']]);
        }
    }

    private function requireSellerOwnsAndApproved(array $order, int $actorId): void
    {
        if ($order['SellerID'] === null || (int)$order['SellerID'] !== $actorId) {
            throw new ForbiddenException("You can only manage your own orders.");
        }
        // Recheck + lock the seller application inside the transaction.
        $stmt = $this->db->prepare("SELECT Status FROM `seller_application` WHERE UserID = ? ORDER BY ApplicationID DESC LIMIT 1 FOR UPDATE");
        $stmt->execute([$actorId]);
        $status = $stmt->fetchColumn();
        if ($status === false || $status === null) {
            throw new ForbiddenException("Seller privileges required.");
        }
        if ($status === 'Suspended') {
            throw new ForbiddenException("Seller account suspended.");
        }
        if ($status !== 'Approved') {
            throw new ForbiddenException("Seller privileges required.");
        }
    }

    private function audit(?int $adminId, string $action, string $entityType, ?int $entityId, ?string $details): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO `admin_audit_log` (AdminID, ActionType, EntityType, EntityID, Details, IPAddress)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([$adminId, $action, $entityType, $entityId, $details, $_SERVER['REMOTE_ADDR'] ?? null]);
    }
}