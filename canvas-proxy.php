<?php
declare(strict_types=1);

// Set CANVAS_TOOL_ORIGIN in the PHP runtime to the exact origin serving this tool,
// for example https://calendar-tools.example.edu. Requests without that Origin
// (or with a different one) are rejected.
$expectedOrigin = getenv('CANVAS_TOOL_ORIGIN') ?: '';
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($expectedOrigin === '' || !hash_equals($expectedOrigin, $origin)) {
    respond(403, ['error' => 'This request did not come from the configured tool origin.']);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(405, ['error' => 'Only POST requests are allowed.']);
}

$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!preg_match('/^Bearer\s+([^\s]+)$/i', $authorization, $tokenMatch) || strlen($tokenMatch[1]) > 4096) {
    respond(401, ['error' => 'A valid bearer token is required.']);
}
$rawUrl = $_GET['url'] ?? '';
if (!is_string($rawUrl) || strlen($rawUrl) > 4096 || filter_var($rawUrl, FILTER_VALIDATE_URL) === false) {
    respond(400, ['error' => 'A valid Canvas API URL is required.']);
}
$target = parse_url($rawUrl);
$allowedCanvasHosts = ['canvas.ox.ac.uk', 'universityofoxford.beta.instructure.com', 'universityofoxford.test.instructure.com', 'oxeval.instructure.com'];
if (!is_array($target) || ($target['scheme'] ?? '') !== 'https' || !in_array(strtolower($target['host'] ?? ''), $allowedCanvasHosts, true)
    || isset($target['port']) || isset($target['user']) || isset($target['pass']) || isset($target['fragment'])
    || (($target['path'] ?? '') !== '/api/v1/calendar_events' && !preg_match('#^/api/v1/courses/[1-9]\d*$#', $target['path'] ?? ''))) {
    respond(400, ['error' => 'This Canvas instance or endpoint is not in the configured allowlist.']);
}

$isCourseDetailsRequest = preg_match('#^/api/v1/courses/[1-9]\d*$#', $target['path']) === 1;
$query = [];
if ($isCourseDetailsRequest) {
    if (isset($target['query']) && $target['query'] !== '') respond(400, ['error' => 'Course detail requests must not include query parameters.']);
} else {
    parse_str($target['query'] ?? '', $query);
    $start = $query['start_date'] ?? '';
    $end = $query['end_date'] ?? '';
    $contextCodes = $query['context_codes'] ?? [];
    $excludes = $query['excludes'] ?? [];
    $page = $query['page'] ?? '1';
    $allowedKeys = ['start_date', 'end_date', 'context_codes', 'per_page', 'excludes', 'page'];
    foreach (array_keys($query) as $key) {
        if (!in_array($key, $allowedKeys, true)) respond(400, ['error' => 'Unsupported calendar query parameter.']);
    }
    if (!validDate($start) || !validDate($end) || $end < $start) respond(400, ['error' => 'A valid start_date and end_date are required.']);
    if (!is_array($contextCodes) || count($contextCodes) !== 1 || !preg_match('/^course_[1-9]\d*$/', (string)$contextCodes[0])) {
        respond(400, ['error' => 'A single positive course context code is required.']);
    }
    if (($query['per_page'] ?? '') !== '100' || !is_array($excludes) || $excludes !== ['child_events'] || !is_string($page) || !preg_match('/^[1-9]\d*$/', $page)) {
        respond(400, ['error' => 'Invalid calendar request options.']);
    }
}

$forwardedHeaders = [];
foreach (getallheaders() as $name => $value) {
    if (strtolower($name) === 'authorization') $forwardedHeaders[] = 'Authorization: ' . $authorization;
}
$forwardedHeaders[] = 'Accept: application/json';
$curl = curl_init($rawUrl);
curl_setopt_array($curl, [
    CURLOPT_HTTPGET => true,
    CURLOPT_HTTPHEADER => $forwardedHeaders,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
        $length = strlen($line);
        $parts = explode(':', $line, 2);
        if (count($parts) === 2) {
            $name = strtolower(trim($parts[0]));
            if (in_array($name, ['link', 'retry-after', 'content-type'], true)) $responseHeaders[$name] = trim($parts[1]);
        }
        return $length;
    },
]);
$responseHeaders = [];
$body = curl_exec($curl);
$curlError = curl_errno($curl);
$status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
curl_close($curl);
if ($body === false || $curlError !== 0) respond(502, ['error' => 'Could not reach Canvas. Check your connection and try again.']);

http_response_code($status ?: 502);
header('Cache-Control: no-store');
header('Content-Type: ' . ($responseHeaders['content-type'] ?? 'application/json; charset=utf-8'));
foreach (['link', 'retry-after'] as $name) {
    if (isset($responseHeaders[$name])) header($name . ': ' . $responseHeaders[$name]);
}
echo $body;

function validDate($value): bool {
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return false;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
    return $date !== false && $date->format('Y-m-d') === $value;
}

function respond(int $status, array $payload): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}
