<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
function respond(int $status, array $data): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
$source = @file_get_contents(__DIR__ . '/config/config.js');
if (!is_string($source) || !preg_match('/\A\s*window\.SITE_CONFIG\s*=\s*(\{.*\})\s*;?\s*\z/s', $source, $match)) {
    respond(503, ['success' => false, 'error' => 'The contact service is not configured yet.']);
}
$config = json_decode($match[1], true);
if (!is_array($config) || !isset($config['form']) || !is_array($config['form'])) {
    respond(503, ['success' => false, 'error' => 'The contact service is not configured yet.']);
}
$form = $config['form'];
$messages = $form['messages'] ?? [];
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respond(405, ['success' => false, 'error' => $messages['method']]);
}
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 20000) {
    respond(413, ['success' => false, 'error' => $messages['tooLarge']]);
}
$recipient = $config['email'] ?? '';
if (empty($form['enabled']) || !is_string($recipient) || !filter_var($recipient, FILTER_VALIDATE_EMAIL) || str_ends_with(strtolower($recipient), '.example') || preg_match('/[\r\n]/', $recipient)) {
    respond(503, ['success' => false, 'error' => $form['unavailableMessage']]);
}
function field(string $key): string {
    $value = $_POST[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}
$name = field('name');
$email = field('email');
$message = field('message');
$service = field('service');
if (field('website') !== '') {
    respond(422, ['success' => false, 'error' => $messages['blocked']]);
}
if (strlen($name) < 2 || strlen($name) > 100 || preg_match('/[\r\n]/', $name) || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 200 || preg_match('/[\r\n]/', $email) || strlen($message) < 10 || strlen($message) > 5000 || strlen($service) > 100 || field('consent') !== 'on') {
    respond(422, ['success' => false, 'error' => $messages['invalid']]);
}
$subject = $form['subject'] ?? '';
if (!is_string($subject) || $subject === '' || strlen($subject) > 200 || preg_match('/[\r\n]/', $subject)) {
    respond(503, ['success' => false, 'error' => $form['unavailableMessage']]);
}
$path = sys_get_temp_dir() . '/forma-' . hash('sha256', __DIR__ . ($_SERVER['REMOTE_ADDR'] ?? 'unknown')) . '.lock';
$handle = @fopen($path, 'c+');
if (!$handle || !flock($handle, LOCK_EX)) {
    respond(503, ['success' => false, 'error' => $messages['busy']]);
}
$last = (int)stream_get_contents($handle);
if (time() - $last < 60) {
    flock($handle, LOCK_UN);
    fclose($handle);
    respond(429, ['success' => false, 'error' => $messages['rateLimit']]);
}
ftruncate($handle, 0);
rewind($handle);
fwrite($handle, (string)time());
flock($handle, LOCK_UN);
fclose($handle);
$body = "New website enquiry\n\nName: $name\nEmail: $email\nService: $service\n\n$message\n\nPrivacy acknowledgement: yes\n";
$headers = ['From' => $recipient, 'Reply-To' => $email, 'MIME-Version' => '1.0', 'Content-Type' => 'text/plain; charset=UTF-8'];
$accepted = function_exists('mail') && @mail($recipient, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
if (!$accepted) {
    respond(502, ['success' => false, 'error' => $messages['mailFailed']]);
}
respond(200, ['success' => true, 'message' => $form['successMessage']]);
