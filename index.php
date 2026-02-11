<?php
// ===============================
// ✅ SINGLE index.php (Webhook)
// ✅ Selling Bot (COINS + Coupons)
// ✅ Supabase Postgres via PDO
// ✅ Deposit Methods: Amazon Gift Card + UPI
// ✅ UPI flow uses Admin-uploaded QR (file_id stored in DB)
// ✅ Admin Orders List (pending deposits)
// ✅ Admin can Accept/Decline deposits (Amazon + UPI)
// ✅ Admin Panel: Update UPI QR
//
// IMPORTANT NOTE (DB):
// - Uses users.diamonds column as "coins" (no SQL change needed)
// - If your orders.gift_amount is INT, we DO NOT store text there.
//   Gift card code + UPI payer name stored inside orders.method string.
// ===============================

// ------------------- CONFIG -------------------
$BOT_TOKEN = getenv("BOT_TOKEN");
$ADMIN_IDS = array_filter(array_map('trim', explode(',', getenv("ADMIN_IDS") ?: "")));
$DB_URL = getenv("DATABASE_URL");

if (!$BOT_TOKEN) die("BOT_TOKEN missing");
if (!$DB_URL) die("DATABASE_URL missing");
if (count($ADMIN_IDS) == 0) die("ADMIN_IDS missing");

date_default_timezone_set("Asia/Kolkata");

// ------------------- DB CONNECT -------------------
function pg_pdo_from_url($url) {
    $parts = parse_url($url);
    $user = $parts["user"] ?? "";
    $pass = $parts["pass"] ?? "";
    $host = $parts["host"] ?? "";
    $port = $parts["port"] ?? 5432;
    $db   = ltrim($parts["path"] ?? "", "/");
    $dsn = "pgsql:host=$host;port=$port;dbname=$db;sslmode=require";
    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
}
$pdo = pg_pdo_from_url($DB_URL);

// ------------------- TELEGRAM HELPERS -------------------
function tg($method, $data = []) {
    global $BOT_TOKEN;
    $url = "https://api.telegram.org/bot{$BOT_TOKEN}/{$method}";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ["Content-Type: application/json"],
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_TIMEOUT => 25
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return $res ? json_decode($res, true) : null;
}

function sendMessage($chat_id, $text, $reply_markup = null, $parse_mode = "HTML") {
    $payload = [
        "chat_id"=>$chat_id,
        "text"=>$text,
        "parse_mode"=>$parse_mode,
        "disable_web_page_preview"=>true
    ];
    if ($reply_markup) $payload["reply_markup"] = $reply_markup;
    return tg("sendMessage", $payload);
}

function sendPhotoMsg($chat_id, $file_id, $caption, $reply_markup = null, $parse_mode = "HTML") {
    $payload = [
        "chat_id"=>$chat_id,
        "photo"=>$file_id,
        "caption"=>$caption,
        "parse_mode"=>$parse_mode
    ];
    if ($reply_markup) $payload["reply_markup"] = $reply_markup;
    return tg("sendPhoto", $payload);
}

function editMessage($chat_id, $message_id, $text, $reply_markup = null, $parse_mode="HTML") {
    $payload = [
        "chat_id"=>$chat_id,
        "message_id"=>$message_id,
        "text"=>$text,
        "parse_mode"=>$parse_mode,
        "disable_web_page_preview"=>true
    ];
    if ($reply_markup) $payload["reply_markup"] = $reply_markup;
    return tg("editMessageText", $payload);
}

function answerCallback($callback_id, $text = "", $showAlert=false) {
    return tg("answerCallbackQuery", [
        "callback_query_id"=>$callback_id,
        "text"=>$text,
        "show_alert"=>$showAlert
    ]);
}

function isAdmin($user_id) {
    global $ADMIN_IDS;
    return in_array(strval($user_id), $ADMIN_IDS, true);
}

function esc($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// ------------------- BOT SETTINGS TABLE (QR storage) -------------------
function init_settings_table() {
    global $pdo;
    $pdo->prepare("CREATE TABLE IF NOT EXISTS bot_settings (
        skey TEXT PRIMARY KEY,
        svalue TEXT
    )")->execute();
}
function set_setting($key, $val) {
    global $pdo;
    init_settings_table();
    $stmt = $pdo->prepare("
      INSERT INTO bot_settings(skey, svalue) VALUES(:k,:v)
      ON CONFLICT(skey) DO UPDATE SET svalue=EXCLUDED.svalue
    ");
    $stmt->execute([":k"=>$key, ":v"=>$val]);
}
function get_setting($key) {
    global $pdo;
    init_settings_table();
    $stmt = $pdo->prepare("SELECT svalue FROM bot_settings WHERE skey=:k");
    $stmt->execute([":k"=>$key]);
    $v = $stmt->fetchColumn();
    return $v !== false ? $v : null;
}

// ------------------- USER STATE (DB) -------------------
function init_states_table() {
    global $pdo;
    $pdo->prepare("CREATE TABLE IF NOT EXISTS user_states (
        user_id BIGINT PRIMARY KEY,
        state TEXT,
        data JSONB,
        updated_at TIMESTAMPTZ DEFAULT NOW()
    )")->execute();
}
function set_state($user_id, $state, $data = []) {
    global $pdo;
    init_states_table();
    $stmt = $pdo->prepare("
      INSERT INTO user_states(user_id, state, data, updated_at)
      VALUES(:uid, :st, :dt::jsonb, NOW())
      ON CONFLICT (user_id) DO UPDATE SET state=:st, data=:dt::jsonb, updated_at=NOW()
    ");
    $stmt->execute([
        ":uid"=>$user_id,
        ":st"=>$state,
        ":dt"=>json_encode($data)
    ]);
}
function get_state($user_id) {
    global $pdo;
    init_states_table();
    $stmt = $pdo->prepare("SELECT state, data FROM user_states WHERE user_id=:uid");
    $stmt->execute([":uid"=>$user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return ["state"=>null, "data"=>[]];
    return ["state"=>$row["state"], "data"=>$row["data"] ? json_decode($row["data"], true) : []];
}
function clear_state($user_id) { set_state($user_id, null, []); }

// ------------------- DB BUSINESS -------------------
// NOTE: uses users.diamonds column as "coins"
function ensure_user($user_id, $username) {
    global $pdo;
    $stmt = $pdo->prepare("
      INSERT INTO users(user_id, username, diamonds)
      VALUES(:uid, :un, 0)
      ON CONFLICT (user_id) DO UPDATE SET username=EXCLUDED.username
    ");
    $stmt->execute([":uid"=>$user_id, ":un"=>$username]);
}
function get_user_coins($user_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT diamonds FROM users WHERE user_id=:uid");
    $stmt->execute([":uid"=>$user_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? intval($row["diamonds"]) : 0;
}
function add_user_coins($user_id, $amount) {
    global $pdo;
    $stmt = $pdo->prepare("UPDATE users SET diamonds = diamonds + :a WHERE user_id=:uid");
    $stmt->execute([":a"=>$amount, ":uid"=>$user_id]);
}

function get_price($ctype) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT price FROM coupon_prices WHERE ctype=:c");
    $stmt->execute([":c"=>$ctype]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? intval($row["price"]) : 0;
}
function set_price($ctype, $price) {
    global $pdo;
    $stmt = $pdo->prepare("
      INSERT INTO coupon_prices(ctype, price)
      VALUES(:c,:p)
      ON CONFLICT(ctype) DO UPDATE SET price=EXCLUDED.price
    ");
    $stmt->execute([":c"=>$ctype, ":p"=>$price]);
}

function stock_count($ctype) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT COUNT(*) AS c FROM coupon_codes WHERE ctype=:c AND is_used=false");
    $stmt->execute([":c"=>$ctype]);
    return intval($stmt->fetch(PDO::FETCH_ASSOC)["c"] ?? 0);
}
function add_coupons($ctype, $codes) {
    global $pdo;
    $ins = $pdo->prepare("INSERT INTO coupon_codes(ctype, code, is_used) VALUES(:c, :code, false) ON CONFLICT (code) DO NOTHING");
    $added = 0;
    foreach ($codes as $code) {
        $code = trim($code);
        if ($code === "") continue;
        $ins->execute([":c"=>$ctype, ":code"=>$code]);
        $added += $ins->rowCount() ? 1 : 0;
    }
    return $added;
}
function remove_coupons($ctype, $qty) {
    global $pdo;
    $stmt = $pdo->prepare("
      DELETE FROM coupon_codes
      WHERE id IN (
        SELECT id FROM coupon_codes
        WHERE ctype=:c AND is_used=false
        ORDER BY id ASC
        LIMIT :q
      )
    ");
    $stmt->bindValue(":c", $ctype, PDO::PARAM_INT);
    $stmt->bindValue(":q", $qty, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->rowCount();
}
function take_coupons($ctype, $qty, $user_id) {
    global $pdo;
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
          SELECT id, code FROM coupon_codes
          WHERE ctype=:c AND is_used=false
          ORDER BY id ASC
          LIMIT :q
          FOR UPDATE
        ");
        $stmt->bindValue(":c", $ctype, PDO::PARAM_INT);
        $stmt->bindValue(":q", $qty, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($rows) < $qty) {
            $pdo->rollBack();
            return null;
        }

        $ids = array_map(fn($r)=>$r["id"], $rows);
        $in = implode(",", array_map("intval", $ids));

        $upd = $pdo->prepare("
          UPDATE coupon_codes
          SET is_used=true, used_by=:u, used_at=NOW()
          WHERE id IN ($in)
        ");
        $upd->execute([":u"=>$user_id]);

        $pdo->commit();
        return array_map(fn($r)=>$r["code"], $rows);
    } catch(Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function create_order($user_id, $otype, $status, $fields=[]) {
    global $pdo;
    $cols = ["user_id","otype","status"];
    $vals = [":uid",":ot",":st"];
    $params = [":uid"=>$user_id, ":ot"=>$otype, ":st"=>$status];

    $allowed = ["method","coins_requested","gift_amount","photo_file_id","ctype","qty","total_cost","codes_text"];
    foreach ($allowed as $k) {
        if (array_key_exists($k, $fields)) {
            $cols[] = $k;
            $vals[] = ":" . $k;
            $params[":" . $k] = $fields[$k];
        }
    }
    $sql = "INSERT INTO orders(" . implode(",",$cols) . ") VALUES(" . implode(",",$vals) . ") RETURNING id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return intval($stmt->fetchColumn());
}
function update_order($order_id, $fields=[]) {
    global $pdo;
    $sets = [];
    $params = [":id"=>$order_id];
    foreach ($fields as $k=>$v) {
        $sets[] = "$k=:$k";
        $params[":$k"] = $v;
    }
    if (!$sets) return;
    $sql = "UPDATE orders SET " . implode(",",$sets) . " WHERE id=:id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}
function get_order($order_id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE id=:id");
    $stmt->execute([":id"=>$order_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}
function list_user_orders($user_id, $limit=15) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE user_id=:u ORDER BY id DESC LIMIT :l");
    $stmt->bindValue(":u", $user_id, PDO::PARAM_INT);
    $stmt->bindValue(":l", $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
function list_pending_deposits($limit = 25) {
    global $pdo;
    $stmt = $pdo->prepare("
      SELECT o.*, u.username
      FROM orders o
      LEFT JOIN users u ON u.user_id = o.user_id
      WHERE o.otype='DEPOSIT' AND o.status='AWAITING_ADMIN'
      ORDER BY o.id DESC
      LIMIT :l
    ");
    $stmt->bindValue(":l", $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ------------------- UI -------------------
function newBtn($t){ return ["text"=>$t]; }

function main_menu($is_admin=false) {
    $rows = [
        [newBtn("➕ Add Coins"), newBtn("🛒 Buy Coupon")],
        [newBtn("📦 My Orders"), newBtn("💰 Balance")]
    ];
    if ($is_admin) $rows[] = [newBtn("🛠 Admin Panel")];
    return ["keyboard"=>$rows, "resize_keyboard"=>true];
}

function admin_menu() {
    $rows = [
        [newBtn("📦 Stock"), newBtn("💰 Change Prices")],
        [newBtn("📋 Orders List"), newBtn("🧾 Update UPI QR")],
        [newBtn("🎁 Get Free Code"), newBtn("➕ Add Coupon")],
        [newBtn("➖ Remove Coupon")],
        [newBtn("⬅️ Back")]
    ];
    return ["keyboard"=>$rows, "resize_keyboard"=>true];
}

// ------------------- READ UPDATE -------------------
$update = json_decode(file_get_contents("php://input"), true);
if (!$update) { echo "OK"; exit; }

$message = $update["message"] ?? null;
$callback = $update["callback_query"] ?? null;

// ===============================
// MESSAGE HANDLER
// ===============================
if ($message) {
    $chat_id = $message["chat"]["id"];
    $user_id = $message["from"]["id"];
    $username = $message["from"]["username"] ?? ($message["from"]["first_name"] ?? "user");
    $text = $message["text"] ?? null;
    $photo = $message["photo"] ?? null;

    ensure_user($user_id, $username);
    $is_admin = isAdmin($user_id);

    $st = get_state($user_id);
    $state = $st["state"];
    $data  = $st["data"];

    if ($text === "/start") {
        clear_state($user_id);
        sendMessage($chat_id, "✅ Welcome!\nChoose an option:", main_menu($is_admin));
        exit;
    }

    if ($text === "⬅️ Back") {
        clear_state($user_id);
        sendMessage($chat_id, "✅ Back to menu.", main_menu($is_admin));
        exit;
    }

    if ($text === "🛠 Admin Panel") {
        if (!$is_admin) { sendMessage($chat_id, "❌ Admin only."); exit; }
        clear_state($user_id);
        sendMessage($chat_id, "🛠 Admin Panel:", admin_menu());
        exit;
    }

    if ($text === "💰 Balance") {
        $bal = get_user_coins($user_id);
        sendMessage($chat_id, "💰 Your Balance: <b>{$bal}</b> Coins 🪙", main_menu($is_admin));
        exit;
    }

    if ($text === "📦 My Orders") {
        $orders = list_user_orders($user_id, 15);
        if (!$orders) {
            sendMessage($chat_id, "📦 No orders found.", main_menu($is_admin));
            exit;
        }
        $out = "📦 <b>Your Orders</b>\n━━━━━━━━━━━━━━━\n";
        foreach ($orders as $o) {
            $t = date("d M Y, h:i A", strtotime($o["created_at"]));
            if ($o["otype"] === "DEPOSIT") {
                $out .= "🧾 #{$o["id"]} | DEPOSIT | {$o["status"]}\n";
                $out .= "💳 {$o["method"]} | 🪙 {$o["coins_requested"]} | 🕒 {$t}\n";
            } else {
                $out .= "🧾 #{$o["id"]} | COUPON {$o["ctype"]} x{$o["qty"]} | {$o["status"]}\n";
                $out .= "🪙 Cost: {$o["total_cost"]} | 🕒 {$t}\n";
            }
            $out .= "━━━━━━━━━━━━━━━\n";
        }
        sendMessage($chat_id, $out, main_menu($is_admin));
        exit;
    }

    // ---------------- Add Coins (payment methods) ----------------
    if ($text === "➕ Add Coins") {
        clear_state($user_id);

        $msg = "💳 <b>Select Payment Method:</b>\n\n".
               "⚠️ <b>Under Maintenance:</b>\n".
               "🛠️ PhonePe Gift Card\n\n".
               "Please use other methods for deposit.";

        $rm = [
            "inline_keyboard" => [
                [
                    ["text"=>"🎁 Amazon Gift Card", "callback_data"=>"pay:amazon"],
                    ["text"=>"🏦 UPI", "callback_data"=>"pay:upi"]
                ]
            ]
        ];
        sendMessage($chat_id, $msg, $rm);
        exit;
    }

    // ===== Amazon flow =====
    if ($state === "AWAIT_AMAZON_COINS" && $text !== null) {
        if (!preg_match('/^\d+$/', $text)) { sendMessage($chat_id, "❌ Send a valid number (minimum 30)."); exit; }
        $coins = intval($text);
        if ($coins < 30) { sendMessage($chat_id, "❌ Minimum is 30 coins. Send again:"); exit; }

        $order_id = create_order($user_id, "DEPOSIT", "PENDING", [
            "method" => "AMAZON",
            "coins_requested" => $coins
        ]);

        $time = date("d M Y, h:i A");
        $summary = "📝 <b>Order Summary:</b>\n━━━━━━━━━━━━━━━\n".
                   "💹 Rate: 1 Rs = 1 Coin 🪙\n".
                   "💵 Amount: <b>{$coins}</b>\n".
                   "🪙 Coins to Receive: <b>{$coins}</b> 🪙\n".
                   "💳 Method: <b>Amazon Gift Card</b>\n".
                   "📅 Time: <b>{$time}</b>\n".
                   "━━━━━━━━━━━━━━━\n\nClick below to proceed.";

        $rm = ["inline_keyboard" => [
            [["text"=>"✅ Submit Gift Card", "callback_data"=>"deposit_submit:$order_id"]]
        ]];

        clear_state($user_id);
        sendMessage($chat_id, $summary, $rm);
        exit;
    }

    if ($state === "AWAIT_GIFT_CODE" && $text !== null) {
        $gift_code = trim($text);
        if ($gift_code === "") {
            sendMessage($chat_id, "❌ Please enter your Amazon Gift Card :");
            exit;
        }

        $order_id = intval($data["order_id"] ?? 0);
        if ($order_id <= 0) { clear_state($user_id); sendMessage($chat_id, "❌ Order missing. Start again."); exit; }

        update_order($order_id, ["method"=>"AMAZON | CODE: ".$gift_code, "status"=>"PENDING"]);
        set_state($user_id, "AWAIT_AMAZON_PHOTO", ["order_id"=>$order_id]);
        sendMessage($chat_id, "📸 Now upload a screenshot of the gift card:");
        exit;
    }

    if ($state === "AWAIT_AMAZON_PHOTO" && $photo) {
        $order_id = intval($data["order_id"] ?? 0);
        if ($order_id <= 0) { clear_state($user_id); sendMessage($chat_id, "❌ Order missing. Start again."); exit; }

        $file_id = end($photo)["file_id"];
        update_order($order_id, ["photo_file_id"=>$file_id, "status"=>"AWAITING_ADMIN"]);
        clear_state($user_id);

        sendMessage($chat_id, "✅ Admin is checking your payment.\n⏳ Please wait for approval.");

        $o = get_order($order_id);
        $time = date("d M Y, h:i A", strtotime($o["created_at"]));
        $codeText = "";
        if (!empty($o["method"]) && strpos($o["method"], "CODE:") !== false) {
            $codeText = trim(substr($o["method"], strpos($o["method"], "CODE:") + 5));
        }

        $adminText = "🆕 <b>Deposit Request (Amazon)</b>\n".
                     "🧾 Order: <b>#{$order_id}</b>\n".
                     "👤 User: @{$username} (<code>{$user_id}</code>)\n".
                     "🪙 Coins: <b>{$o["coins_requested"]}</b>\n".
                     "🎁 Gift Card: <b>".esc($codeText)."</b>\n".
                     "⏰ Time: <b>{$time}</b>\n";

        $adminRm = ["inline_keyboard" => [[
            ["text"=>"✅ Accept", "callback_data"=>"admin_dep_ok:$order_id"],
            ["text"=>"❌ Decline", "callback_data"=>"admin_dep_no:$order_id"]
        ]]];

        foreach ($GLOBALS["ADMIN_IDS"] as $aid) {
            sendPhotoMsg(intval($aid), $file_id, $adminText, $adminRm);
        }
        exit;
    }

    // ===== UPI flow =====
    if ($state === "AWAIT_UPI_COINS" && $text !== null) {
        if (!preg_match('/^\d+$/', $text)) { sendMessage($chat_id, "❌ Send a valid number (minimum 30)."); exit; }
        $coins = intval($text);
        if ($coins < 30) { sendMessage($chat_id, "❌ Minimum is 30 coins. Send again:"); exit; }

        $qr_file_id = get_setting("upi_qr_file_id");
        if (!$qr_file_id) {
            clear_state($user_id);
            sendMessage($chat_id, "❌ UPI QR is not set yet. Please contact admin.");
            exit;
        }

        $order_id = create_order($user_id, "DEPOSIT", "PENDING", [
            "method" => "UPI",
            "coins_requested" => $coins
        ]);

        clear_state($user_id);

        $caption = "💳<b>Payement Request</b>\n\n".
                   "🎫Order- <b>#{$order_id}</b>\n".
                   "💰Amount- <b>{$coins}</b>\n\n".
                   "✅ After payment, click \"I Have Paid\" below";

        $rm = ["inline_keyboard" => [
            [["text"=>"✅ I Have Paid", "callback_data"=>"upi_paid:$order_id"]]
        ]];

        sendPhotoMsg($chat_id, $qr_file_id, $caption, $rm);
        exit;
    }

    if ($state === "AWAIT_UPI_PAYER_NAME" && $text !== null) {
        $payer = trim($text);
        if ($payer === "") {
            sendMessage($chat_id, "❌ Please send payer name:");
            exit;
        }
        $order_id = intval($data["order_id"] ?? 0);
        if ($order_id <= 0) { clear_state($user_id); sendMessage($chat_id, "❌ Order missing. Start again."); exit; }

        // store payer name inside method
        $o = get_order($order_id);
        if (!$o) { clear_state($user_id); sendMessage($chat_id, "❌ Order not found."); exit; }

        update_order($order_id, ["method"=>"UPI | NAME: ".$payer, "status"=>"PENDING"]);
        set_state($user_id, "AWAIT_UPI_SS", ["order_id"=>$order_id]);

        sendMessage($chat_id, "📸 Now upload a screenshot of payment:");
        exit;
    }

    if ($state === "AWAIT_UPI_SS" && $photo) {
        $order_id = intval($data["order_id"] ?? 0);
        if ($order_id <= 0) { clear_state($user_id); sendMessage($chat_id, "❌ Order missing. Start again."); exit; }

        $file_id = end($photo)["file_id"];
        update_order($order_id, ["photo_file_id"=>$file_id, "status"=>"AWAITING_ADMIN"]);
        clear_state($user_id);

        sendMessage($chat_id, "✅ Admin is reviewing your payment.\n⏳ Please wait for approval.");

        $o = get_order($order_id);
        $time = date("d M Y, h:i A", strtotime($o["created_at"]));

        $payerName = "";
        if (!empty($o["method"]) && strpos($o["method"], "NAME:") !== false) {
            $payerName = trim(substr($o["method"], strpos($o["method"], "NAME:") + 5));
        }

        $adminText = "🆕 <b>Deposit Request (UPI)</b>\n".
                     "🧾 Order: <b>#{$order_id}</b>\n".
                     "👤 User: @{$username} (<code>{$user_id}</code>)\n".
                     "🪙 Coins: <b>{$o["coins_requested"]}</b>\n".
                     "👤 Payer: <b>".esc($payerName)."</b>\n".
                     "⏰ Time: <b>{$time}</b>\n";

        $adminRm = ["inline_keyboard" => [[
            ["text"=>"✅ Accept", "callback_data"=>"admin_dep_ok:$order_id"],
            ["text"=>"❌ Decline", "callback_data"=>"admin_dep_no:$order_id"]
        ]]];

        foreach ($GLOBALS["ADMIN_IDS"] as $aid) {
            sendPhotoMsg(intval($aid), $file_id, $adminText, $adminRm);
        }
        exit;
    }

    // ================= ADMIN =================
    if ($text === "🧾 Update UPI QR") {
        if (!$is_admin) { sendMessage($chat_id, "❌ Admin only."); exit; }
        set_state($user_id, "ADMIN_AWAIT_UPI_QR", []);
        sendMessage($chat_id, "📸 Send the new UPI QR image now:");
        exit;
    }

    if ($state === "ADMIN_AWAIT_UPI_QR" && $photo) {
        if (!$is_admin) { clear_state($user_id); exit; }
        $file_id = end($photo)["file_id"];
        set_setting("upi_qr_file_id", $file_id);
        clear_state($user_id);
        sendMessage($chat_id, "✅ UPI QR updated successfully.", admin_menu());
        exit;
    }

    if ($text === "📦 Stock") {
        if (!$is_admin) { sendMessage($chat_id, "❌ Admin only."); exit; }
        $types = [500,1000,2000,4000];
        $out = "📦 <b>Stock</b>\n━━━━━━━━━━━━━━━\n";
        foreach($types as $c){ $out .= "• {$c}: <b>".stock_count($c)."</b>\n"; }
        sendMessage($chat_id, $out, admin_menu());
        exit;
    }

    if ($text === "📋 Orders List") {
        if (!$is_admin) { sendMessage($chat_id, "❌ Admin only."); exit; }
        $pending = list_pending_deposits(25);
        if (!$pending) { sendMessage($chat_id, "✅ No pending deposits right now.", admin_menu()); exit; }

        sendMessage($chat_id, "📋 <b>Pending Deposits:</b>\nShowing latest pending requests:", admin_menu());

        foreach ($pending as $o) {
            $oid = intval($o["id"]);
            $uid = intval($o["user_id"]);
            $un  = $o["username"] ?: "user";
            $coins = intval($o["coins_requested"]);
            $time = $o["created_at"] ? date("d M Y, h:i A", strtotime($o["created_at"])) : date("d M Y, h:i A");

            $extra = "";
            if (!empty($o["method"])) {
                if (strpos($o["method"], "AMAZON") === 0 && strpos($o["method"], "CODE:") !== false) {
                    $codeText = trim(substr($o["method"], strpos($o["method"], "CODE:") + 5));
                    $extra = "🎁 Gift Card: <b>".esc($codeText)."</b>\n";
                } elseif (strpos($o["method"], "UPI") === 0 && strpos($o["method"], "NAME:") !== false) {
                    $payer = trim(substr($o["method"], strpos($o["method"], "NAME:") + 5));
                    $extra = "👤 Payer: <b>".esc($payer)."</b>\n";
                }
            }

            $txt = "🆕 <b>Deposit Request</b>\n"
                 . "🧾 Order: <b>#{$oid}</b>\n"
                 . "👤 User: @{$un} (<code>{$uid}</code>)\n"
                 . "🪙 Coins: <b>{$coins}</b>\n"
                 . $extra
                 . "⏰ Time: <b>{$time}</b>\n";

            $rm = ["inline_keyboard" => [[
                ["text"=>"✅ Accept", "callback_data"=>"admin_dep_ok:$oid"],
                ["text"=>"❌ Decline", "callback_data"=>"admin_dep_no:$oid"]
            ]]];

            if (!empty($o["photo_file_id"])) {
                sendPhotoMsg($chat_id, $o["photo_file_id"], $txt, $rm);
            } else {
                sendMessage($chat_id, $txt, $rm);
            }
        }
        exit;
    }

    if ($text === "💰 Change Prices") {
        if (!$is_admin) { sendMessage($chat_id, "❌ Admin only."); exit; }
        clear_state($user_id);
        $rm = ["inline_keyboard"=>[
            [["text"=>"500", "callback_data"=>"admin_price:500"], ["text"=>"1K", "callback_data"=>"admin_price:1000"]],
            [["text"=>"2K", "callback_data"=>"admin_price:2000"], ["text"=>"4K", "callback_data"=>"admin_price:4000"]],
        ]];
        sendMessage($chat_id, "Select type to change price:", $rm);
        exit;
    }

    if ($text === "🎁 Get Free Code") {
        if (!$is_admin) { sendMessage($chat_id, "❌ Admin only."); exit; }
        clear_state($user_id);
        $rm = ["inline_keyboard"=>[
            [["text"=>"500", "callback_data"=>"admin_free:500"], ["text"=>"1K", "callback_data"=>"admin_free:1000"]],
            [["text"=>"2K", "callback_data"=>"admin_free:2000"], ["text"=>"4K", "callback_data"=>"admin_free:4000"]],
        ]];
        sendMessage($chat_id, "Select coupon type to get FREE code:", $rm);
        exit;
    }

    if ($text === "➕ Add Coupon") {
        if (!$is_admin) { sendMessage($chat_id, "❌ Admin only."); exit; }
        clear_state($user_id);
        $rm = ["inline_keyboard"=>[
            [["text"=>"500", "callback_data"=>"admin_add:500"], ["text"=>"1K", "callback_data"=>"admin_add:1000"]],
            [["text"=>"2K", "callback_data"=>"admin_add:2000"], ["text"=>"4K", "callback_data"=>"admin_add:4000"]],
        ]];
        sendMessage($chat_id, "Select type to add coupons:", $rm);
        exit;
    }

    if ($text === "➖ Remove Coupon") {
        if (!$is_admin) { sendMessage($chat_id, "❌ Admin only."); exit; }
        clear_state($user_id);
        $rm = ["inline_keyboard"=>[
            [["text"=>"500", "callback_data"=>"admin_rem:500"], ["text"=>"1K", "callback_data"=>"admin_rem:1000"]],
            [["text"=>"2K", "callback_data"=>"admin_rem:2000"], ["text"=>"4K", "callback_data"=>"admin_rem:4000"]],
        ]];
        sendMessage($chat_id, "Select type to remove coupons:", $rm);
        exit;
    }

    if ($state === "ADMIN_AWAIT_PRICE" && $text !== null) {
        if (!$is_admin) { clear_state($user_id); exit; }
        if (!preg_match('/^\d+$/', $text)) { sendMessage($chat_id, "❌ Send a valid price number."); exit; }
        $price = intval($text);
        $ctype = intval($data["ctype"] ?? 0);
        set_price($ctype, $price);
        clear_state($user_id);
        sendMessage($chat_id, "✅ Price updated for {$ctype} => {$price} coins.", admin_menu());
        exit;
    }

    if ($state === "ADMIN_AWAIT_ADD_CODES" && $text !== null) {
        if (!$is_admin) { clear_state($user_id); exit; }
        $ctype = intval($data["ctype"] ?? 0);
        $lines = preg_split("/\r\n|\n|\r/", trim($text));
        $added = add_coupons($ctype, $lines);
        clear_state($user_id);
        sendMessage($chat_id, "✅ Added <b>{$added}</b> coupons to {$ctype}.", admin_menu());
        exit;
    }

    if ($state === "ADMIN_AWAIT_REMOVE_QTY" && $text !== null) {
        if (!$is_admin) { clear_state($user_id); exit; }
        if (!preg_match('/^\d+$/', $text)) { sendMessage($chat_id, "❌ Send a valid number."); exit; }
        $qty = intval($text);
        $ctype = intval($data["ctype"] ?? 0);
        $removed = remove_coupons($ctype, $qty);
        clear_state($user_id);
        sendMessage($chat_id, "✅ Removed <b>{$removed}</b> from {$ctype}.", admin_menu());
        exit;
    }

    // If photo sent unexpectedly
    if ($photo && !in_array($state, ["AWAIT_AMAZON_PHOTO","AWAIT_UPI_SS","ADMIN_AWAIT_UPI_QR"], true)) {
        sendMessage($chat_id, "❌ Photo not expected now. Use menu buttons.", main_menu($is_admin));
        exit;
    }

    sendMessage($chat_id, "❓ Use the menu buttons.", main_menu($is_admin));
    exit;
}

// ===============================
// CALLBACK HANDLER
// ===============================
if ($callback) {
    $cb_id = $callback["id"];
    $user_id = $callback["from"]["id"];
    $username = $callback["from"]["username"] ?? ($callback["from"]["first_name"] ?? "user");
    $chat_id = $callback["message"]["chat"]["id"];
    $msg_id = $callback["message"]["message_id"];
    $data = $callback["data"] ?? "";

    ensure_user($user_id, $username);
    $is_admin = isAdmin($user_id);

    // Payment method selection
    if ($data === "pay:amazon") {
        answerCallback($cb_id, "Amazon selected");
        set_state($user_id, "AWAIT_AMAZON_COINS", []);
        sendMessage($chat_id, "Enter the number of coins to add (Method: Amazon):\n\n✅ Minimum: 30");
        exit;
    }

    if ($data === "pay:upi") {
        answerCallback($cb_id, "UPI selected");
        set_state($user_id, "AWAIT_UPI_COINS", []);
        sendMessage($chat_id, "How much coins you need? (Minimum: 30)");
        exit;
    }

    // Amazon submit -> ask gift card code (text)
    if (preg_match('/^deposit_submit:(\d+)$/', $data, $m)) {
        $order_id = intval($m[1]);
        answerCallback($cb_id, "Proceeding...");
        set_state($user_id, "AWAIT_GIFT_CODE", ["order_id"=>$order_id]);
        sendMessage($chat_id, "Enter your Amazon Gift Card :");
        exit;
    }

    // UPI "I Have Paid"
    if (preg_match('/^upi_paid:(\d+)$/', $data, $m)) {
        $order_id = intval($m[1]);
        $o = get_order($order_id);
        if (!$o || intval($o["user_id"]) !== intval($user_id)) {
            answerCallback($cb_id, "Invalid order", true);
            exit;
        }
        if ($o["status"] !== "PENDING") {
            answerCallback($cb_id, "Already submitted", true);
            exit;
        }
        answerCallback($cb_id, "OK");
        set_state($user_id, "AWAIT_UPI_PAYER_NAME", ["order_id"=>$order_id]);
        sendMessage($chat_id, "Send the payer name (person who paid):");
        exit;
    }

    // Buy coupon type
    if (preg_match('/^buy:(500|1000|2000|4000)$/', $data, $m)) {
        $ctype = intval($m[1]);
        answerCallback($cb_id, "Selected $ctype");
        set_state($user_id, "AWAIT_BUY_QTY", ["ctype"=>$ctype]);
        sendMessage($chat_id, "How many {$ctype} coupons do you want to buy?\nPlease send the quantity:");
        exit;
    }

    // Admin accept deposit (works for Amazon + UPI)
    if (preg_match('/^admin_dep_ok:(\d+)$/', $data, $m)) {
        if (!$is_admin) { answerCallback($cb_id, "Admin only", true); exit; }
        $order_id = intval($m[1]);
        $o = get_order($order_id);
        if (!$o) { answerCallback($cb_id, "Order not found", true); exit; }
        if ($o["status"] !== "AWAITING_ADMIN") { answerCallback($cb_id, "Already processed", true); exit; }

        update_order($order_id, ["status"=>"APPROVED"]);
        add_user_coins(intval($o["user_id"]), intval($o["coins_requested"]));

        answerCallback($cb_id, "Accepted ✅");
        editMessage($chat_id, $msg_id, "✅ Accepted deposit order #{$order_id}");
        sendMessage(intval($o["user_id"]), "✅ Your deposit has been <b>approved</b>!\n🪙 Added: <b>{$o["coins_requested"]}</b> Coins 🪙");
        exit;
    }

    // Admin decline deposit
    if (preg_match('/^admin_dep_no:(\d+)$/', $data, $m)) {
        if (!$is_admin) { answerCallback($cb_id, "Admin only", true); exit; }
        $order_id = intval($m[1]);
        $o = get_order($order_id);
        if (!$o) { answerCallback($cb_id, "Order not found", true); exit; }
        if ($o["status"] !== "AWAITING_ADMIN") { answerCallback($cb_id, "Already processed", true); exit; }

        update_order($order_id, ["status"=>"DECLINED"]);
        answerCallback($cb_id, "Declined ❌");
        editMessage($chat_id, $msg_id, "❌ Declined deposit order #{$order_id}");
        sendMessage(intval($o["user_id"]), "❌ Your deposit has been <b>declined</b>.");
        exit;
    }

    // Admin choose type for price
    if (preg_match('/^admin_price:(500|1000|2000|4000)$/', $data, $m)) {
        if (!$is_admin) { answerCallback($cb_id, "Admin only", true); exit; }
        $ctype = intval($m[1]);
        answerCallback($cb_id, "Type $ctype");
        set_state($user_id, "ADMIN_AWAIT_PRICE", ["ctype"=>$ctype]);
        sendMessage($chat_id, "Send new price (coins) for {$ctype}:");
        exit;
    }

    // Admin get free code
    if (preg_match('/^admin_free:(500|1000|2000|4000)$/', $data, $m)) {
        if (!$is_admin) { answerCallback($cb_id, "Admin only", true); exit; }
        $ctype = intval($m[1]);
        $codes = take_coupons($ctype, 1, $user_id);
        if (!$codes) { answerCallback($cb_id, "No stock!", true); sendMessage($chat_id, "❌ No stock for {$ctype}."); exit; }
        answerCallback($cb_id, "Here is your code ✅");
        sendMessage($chat_id, "🎁 FREE CODE ({$ctype}):\n<code>{$codes[0]}</code>");
        exit;
    }

    // Admin add coupon type
    if (preg_match('/^admin_add:(500|1000|2000|4000)$/', $data, $m)) {
        if (!$is_admin) { answerCallback($cb_id, "Admin only", true); exit; }
        $ctype = intval($m[1]);
        answerCallback($cb_id, "Send codes");
        set_state($user_id, "ADMIN_AWAIT_ADD_CODES", ["ctype"=>$ctype]);
        sendMessage($chat_id, "Send coupons for {$ctype} (one per line):");
        exit;
    }

    // Admin remove coupon type
    if (preg_match('/^admin_rem:(500|1000|2000|4000)$/', $data, $m)) {
        if (!$is_admin) { answerCallback($cb_id, "Admin only", true); exit; }
        $ctype = intval($m[1]);
        answerCallback($cb_id, "Remove qty");
        set_state($user_id, "ADMIN_AWAIT_REMOVE_QTY", ["ctype"=>$ctype]);
        $avail = stock_count($ctype);
        sendMessage($chat_id, "Available stock for {$ctype}: <b>{$avail}</b>\nHow many do you want to remove?");
        exit;
    }

    answerCallback($cb_id, "Unknown action");
    exit;
}

echo "OK";
