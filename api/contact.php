<?php
/*
 * API nhận form liên hệ và gửi email qua Resend — bản PHP chạy trên Hostinger.
 * (Bản Node tương đương: server/contact.js, dùng khi chạy trên VPS + nginx.)
 *
 * Website gọi POST /api/contact (file .htaccess chuyển về file này).
 *
 * Cấu hình KHÔNG để trong git. Đặt file miraway-secrets.php ở thư mục NGOÀI public_html,
 * tức là cùng cấp với public_html (ví dụ domains/mirawaygroup.vn/miraway-secrets.php):
 *
 *   <?php return [
 *     'RESEND_API_KEY' => 're_xxx',
 *     'MAIL_TO'        => 'hien@miratech.vn',            // nhiều email: ngăn cách bằng dấu phẩy
 *     'MAIL_FROM'      => 'Miraway Group Website <website@domain-da-verify-tren-resend>',
 *   ];
 *
 * Biến môi trường cùng tên (nếu có) được ưu tiên hơn file.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function reply(int $code, array $body): void
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------- cấu hình ----------
function load_config(): array
{
    $cfg = [];
    foreach ([dirname(__DIR__, 2) . '/miraway-secrets.php', dirname(__DIR__) . '/miraway-secrets.php'] as $file) {
        if (is_file($file)) {
            $data = include $file;
            if (is_array($data)) { $cfg = $data; break; }
        }
    }
    foreach (['RESEND_API_KEY', 'MAIL_TO', 'MAIL_FROM'] as $k) {
        $env = getenv($k);
        if ($env !== false && $env !== '') $cfg[$k] = $env;
    }
    return $cfg + [
        'RESEND_API_KEY' => '',
        'MAIL_TO' => 'hien@miratech.vn',
        'MAIL_FROM' => 'Miraway Group Website <onboarding@resend.dev>',
    ];
}

// ---------- giới hạn 5 lần / 10 phút / IP ----------
function rate_limited(string $ip): bool
{
    $limit = 5;
    $window = 600;
    $file = sys_get_temp_dir() . '/miraway-contact-rl.json';
    $fh = @fopen($file, 'c+');
    if (!$fh) return false; // không ghi được file tạm thì bỏ qua giới hạn, không chặn khách
    flock($fh, LOCK_EX);
    $raw = stream_get_contents($fh);
    $all = $raw ? (json_decode($raw, true) ?: []) : [];
    $now = time();
    foreach ($all as $k => $list) {
        $all[$k] = array_values(array_filter($list, function ($t) use ($now, $window) { return $now - $t < $window; }));
        if (!$all[$k]) unset($all[$k]);
    }
    $key = hash('sha256', $ip);
    $all[$key][] = $now;
    $hit = count($all[$key]) > $limit;
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($all));
    flock($fh, LOCK_UN);
    fclose($fh);
    return $hit;
}

function clean($v, int $max): string
{
    if (!is_string($v)) return '';
    $v = trim($v);
    return function_exists('mb_substr') ? mb_substr($v, 0, $max, 'UTF-8') : substr($v, 0, $max);
}

function esc(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

// ---------- xử lý request ----------
$path = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '', '/');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && substr($path, -7) === '/health') reply(200, ['ok' => true]);
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') reply(405, ['ok' => false, 'error' => 'method_not_allowed']);

$raw = file_get_contents('php://input', false, null, 0, 20001);
if ($raw === false || strlen($raw) > 20000) reply(413, ['ok' => false, 'error' => 'too_large']);
$b = json_decode($raw, true);
if (!is_array($b)) reply(400, ['ok' => false, 'error' => 'invalid_json']);
if (!empty($b['website'])) reply(200, ['ok' => true]); // honeypot: bot điền ô ẩn

$d = [
    'name'    => clean($b['name'] ?? '', 100),
    'company' => clean($b['company'] ?? '', 150),
    'email'   => clean($b['email'] ?? '', 150),
    'phone'   => clean($b['phone'] ?? '', 20),
    'topic'   => clean($b['topic'] ?? '', 120),
    'message' => clean($b['message'] ?? '', 1000),
    'page'    => clean($b['page'] ?? '', 300),
];
$len = function (string $s): int { return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s); };
if ($len($d['name']) < 2) reply(422, ['ok' => false, 'error' => 'invalid_name']);
if (!preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/u', $d['email'])) reply(422, ['ok' => false, 'error' => 'invalid_email']);
if (!preg_match('/^\+?\d{9,15}$/', preg_replace('/[\s.\-()]/', '', $d['phone']))) reply(422, ['ok' => false, 'error' => 'invalid_phone']);
if ($len($d['message']) < 10) reply(422, ['ok' => false, 'error' => 'invalid_message']);
if (($b['consent'] ?? null) !== true) reply(422, ['ok' => false, 'error' => 'invalid_consent']);

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if (rate_limited((string)$ip)) reply(429, ['ok' => false, 'error' => 'rate_limited']);

$cfg = load_config();
if ($cfg['RESEND_API_KEY'] === '') {
    error_log('[contact] Thiếu RESEND_API_KEY (miraway-secrets.php)');
    reply(500, ['ok' => false, 'error' => 'not_configured']);
}

// ---------- nội dung email ----------
$rows = [
    ['Họ và tên', $d['name']],
    ['Công ty / Tổ chức', $d['company'] !== '' ? $d['company'] : '—'],
    ['Email', $d['email']],
    ['Số điện thoại', $d['phone']],
    ['Giải pháp quan tâm', $d['topic'] !== '' ? $d['topic'] : '—'],
];
$time = (new DateTime('now', new DateTimeZone('Asia/Ho_Chi_Minh')))->format('H:i d/m/Y');
$trs = '';
foreach ($rows as [$k, $v]) {
    $trs .= '<tr><td style="border-bottom:1px solid #E3E8EF;color:#55657E;width:170px">' . $k . '</td>'
          . '<td style="border-bottom:1px solid #E3E8EF"><b>' . esc($v) . '</b></td></tr>';
}
$html = '<div style="font-family:Arial,sans-serif;font-size:15px;color:#0B1B33;max-width:600px">'
      . '<h2 style="color:#0052B8;margin:0 0 16px">Yêu cầu tư vấn mới từ website</h2>'
      . '<table cellpadding="8" style="border-collapse:collapse;width:100%">' . $trs . '</table>'
      . '<h3 style="margin:20px 0 8px">Nội dung</h3>'
      . '<div style="white-space:pre-wrap;background:#F5F7FB;border-radius:8px;padding:14px">' . esc($d['message']) . '</div>'
      . '<p style="color:#8090A8;font-size:13px;margin-top:20px">Gửi lúc ' . esc($time)
      . ($d['page'] !== '' ? ' từ ' . esc($d['page']) : '') . '</p></div>';
$text = implode("\n", array_map(function ($r) { return $r[0] . ': ' . $r[1]; }, $rows))
      . "\n\nNội dung:\n" . $d['message'] . "\n\nGửi lúc " . $time;

$payload = [
    'from'     => $cfg['MAIL_FROM'],
    'to'       => array_values(array_filter(array_map('trim', explode(',', $cfg['MAIL_TO'])))),
    'reply_to' => $d['email'],
    'subject'  => '[Website] Yêu cầu tư vấn từ ' . $d['name'] . ($d['company'] !== '' ? ' – ' . $d['company'] : ''),
    'html'     => $html,
    'text'     => $text,
];

// ---------- gửi qua Resend ----------
$ch = curl_init('https://api.resend.com/emails');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $cfg['RESEND_API_KEY'], 'Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
]);
$resp = curl_exec($ch);
$status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);

if ($resp === false || $status < 200 || $status >= 300) {
    error_log('[contact] Resend ' . $status . ': ' . ($err !== '' ? $err : (string)$resp));
    reply(502, ['ok' => false, 'error' => 'send_failed']);
}
reply(200, ['ok' => true]);
