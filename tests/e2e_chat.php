<?php
/**
 * Douffer PhotoSys: Phase 5 End-to-End HTTP & DB Test Suite (Screen C6, Screen A19, Scenario T11).
 *
 * Usage:
 *   C:\Xampp\php\php.exe tests/e2e_chat.php [http://localhost:8000]
 */
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\DB;

$baseUrl = rtrim($argv[1] ?? 'http://localhost:8000', '/');
$passed = 0;
$failed = 0;

function check(string $label, bool $cond, string $detail = ''): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "PASS $label\n";
    } else {
        $failed++;
        echo "FAIL $label" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

/**
 * Lightweight HTTP client with isolated cookie jar.
 */
final class TestHttpClient
{
    private string $baseUrl;
    private string $cookieFile;

    public function __construct(string $baseUrl, string $name)
    {
        $this->baseUrl = $baseUrl;
        $this->cookieFile = sys_get_temp_dir() . '/dps_e2e_chat_' . $name . '_' . getmypid() . '.cookie';
        if (is_file($this->cookieFile)) {
            @unlink($this->cookieFile);
        }
    }

    public function __destruct()
    {
        if (is_file($this->cookieFile)) {
            @unlink($this->cookieFile);
        }
    }

    public function request(string $method, string $path, array $post = [], array $headers = []): array
    {
        $url = $this->baseUrl . $path;
        $ch = curl_init($url);
        $respHeaders = [];

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR      => $this->cookieFile,
            CURLOPT_COOKIEFILE     => $this->cookieFile,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$respHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $respHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);

        if ($headers) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        if ($method === 'POST') {
            $hasCurlFile = false;
            foreach ($post as $v) {
                if ($v instanceof \CURLFile) {
                    $hasCurlFile = true;
                    break;
                }
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $hasCurlFile ? $post : http_build_query($post));
        }

        $body = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $loc = $respHeaders['location'] ?? '';
        if ($loc !== '') {
            $loc = preg_replace('#^(https?://[^/]+)?(/douffer_photosys)?#', '', $loc);
        }

        return [
            'status'   => $status,
            'body'     => $body,
            'headers'  => $respHeaders,
            'location' => $loc,
        ];
    }

    public function csrfFrom(string $html): string
    {
        if (preg_match('/name="_csrf"\s+value="([^"]+)"/', $html, $m)) {
            return $m[1];
        }
        if (preg_match('/meta\s+name="csrf-token"\s+content="([^"]+)"/', $html, $m)) {
            return $m[1];
        }
        return '';
    }
}

echo "Setting up fresh seed database for Phase 5 E2E chat testing...\n";
passthru('"' . PHP_BINARY . '" ' . escapeshellarg( BASE_DIR . '/database/install.php') . ' --fresh > NUL');

$clientA = new TestHttpClient($baseUrl, 'clientA');
$clientB = new TestHttpClient($baseUrl, 'clientB');
$admin   = new TestHttpClient($baseUrl, 'admin');
$crew    = new TestHttpClient($baseUrl, 'crew');

// -------------------------------------------------------------------------
// 1. Pre-booking inquiry chat, honeypot & XSS escaping (Screen C6)
// -------------------------------------------------------------------------
echo "\n--- Section 1: Pre-booking Inquiry Chat, Honeypot & XSS Escaping (C6) ---\n";

$rChat = $clientA->request('GET', '/chat');
check('1.1 GET /chat renders C6 inquiry start screen (200 OK)', $rChat['status'] === 200 && str_contains($rChat['body'], 'Screen C6'));
$csrfA = $clientA->csrfFrom($rChat['body']);
check('1.1 C6 inquiry form includes CSRF token and honeypot input', $csrfA !== '' && str_contains($rChat['body'], 'name="website"'));

// Honeypot spam probe
$convCountBefore = (int) DB::value('SELECT COUNT(*) FROM conversations');
$rSpam = $clientA->request('POST', '/chat/start', [
    '_csrf'           => $csrfA,
    'website'         => 'http://spam-bot.example',
    'guest_name'      => 'Bot Spammer',
    'guest_mobile'    => '09171234567',
    'body'            => 'Buy cheap followers!',
    'privacy_consent' => '1',
]);
$convCountAfter = (int) DB::value('SELECT COUNT(*) FROM conversations');
check('1.2 Honeypot field silently blocks spam bot from creating a conversation', $rSpam['status'] === 303 && $convCountAfter === $convCountBefore);

// Legitimate inquiry with <script> payload to test XSS escaping (T11)
$xssMessage = "<script>alert('xss')</script> Hi po! Is Dec 28 open for a birthday shoot?";
$rStart = $clientA->request('POST', '/chat/start', [
    '_csrf'           => $csrfA,
    'website'         => '',
    'guest_name'      => 'Patricia Lim (Sample)',
    'guest_mobile'    => '0917 888 9900',
    'body'            => $xssMessage,
    'privacy_consent' => '1',
]);
check('1.3 Valid pre-booking inquiry redirects to /chat/t/{token}', $rStart['status'] === 303 && str_starts_with($rStart['location'], '/chat/t/'));

$tokenPath = $rStart['location'];
$rawToken = substr($tokenPath, strlen('/chat/t/'));
check('1.3 Generated inquiry token is 64 hex chars', strlen($rawToken) === 64);

$rThreadA = $clientA->request('GET', $tokenPath);
check('1.4 GET /chat/t/{token} renders thread view (200 OK)', $rThreadA['status'] === 200);
check(
    '1.4 T11 XSS check: <script> tag in chat message is escaped as literal text',
    !str_contains($rThreadA['body'], "<script>alert('xss')</script>")
    && str_contains($rThreadA['body'], '&lt;script&gt;alert(&#039;xss&#039;)&lt;/script&gt;')
);

$newConv = DB::one('SELECT * FROM conversations WHERE access_token_hash = ?', [hash('sha256', $rawToken)]);
$newConvId = (int) ($newConv['id'] ?? 0);
check('1.5 DB stores only SHA-256 hash of inquiry token and records privacy_consent_at', $newConvId > 0 && !empty($newConv['privacy_consent_at']));

$firstMsg = DB::one('SELECT * FROM messages WHERE conversation_id = ? ORDER BY id ASC LIMIT 1', [$newConvId]);
$firstMsgId = (int) ($firstMsg['id'] ?? 0);
check('1.5 First client message stored in messages table', $firstMsgId > 0 && $firstMsg['sender_type'] === 'client');

// -------------------------------------------------------------------------
// 2. IDOR & Token Isolation (T11: another client cannot open chat)
// -------------------------------------------------------------------------
echo "\n--- Section 2: IDOR & Token Isolation (T11) ---\n";

$rBadToken = $clientB->request('GET', '/chat/t/' . str_repeat('a', 64));
check('2.1 T11 Changing inquiry token in URL returns 404', $rBadToken['status'] === 404);

$rIdorPoll = $clientB->request('GET', '/api/conversations/' . $newConvId . '/messages?since_id=0');
check('2.2 T11 Polling another client\'s conversation ID without ownership returns 403', $rIdorPoll['status'] === 403);

$rClientBHome = $clientB->request('GET', '/chat?new=1');
$csrfB = $clientB->csrfFrom($rClientBHome['body']);
$rIdorSend = $clientB->request('POST', '/api/conversations/' . $newConvId . '/messages', [
    '_csrf' => $csrfB,
    'body'  => 'Trying to write into someone else\'s chat',
]);
check('2.3 T11 Posting to another client\'s conversation ID returns 403', $rIdorSend['status'] === 403);

$rIdorBooking = $clientB->request('GET', '/booking/DPS-2026-0004/chat');
check('2.4 T11 Opening /booking/{ref}/chat without prior lookup redirects to /booking lookup', $rIdorBooking['status'] === 303 && $rIdorBooking['location'] === '/booking');

// -------------------------------------------------------------------------
// 3. Crew Role Strictly Forbidden from Chats (T11)
// -------------------------------------------------------------------------
echo "\n--- Section 3: Crew Role Forbidden from Client Chats (T11) ---\n";

$rCrewLoginGet = $crew->request('GET', '/admin/login');
$csrfCrew = $crew->csrfFrom($rCrewLoginGet['body']);
$rCrewLogin = $crew->request('POST', '/admin/login', [
    '_csrf'    => $csrfCrew,
    'username' => 'crew1',
    'password' => 'Crew1@2026',
]);
check('3.1 Crew login succeeds and redirects to /crew', $rCrewLogin['status'] === 303 && $rCrewLogin['location'] === '/crew');

$rCrewInbox = $crew->request('GET', '/admin/messages');
check('3.2 T11 Crew gets 403 on /admin/messages', $rCrewInbox['status'] === 403);

$rCrewThread = $crew->request('GET', '/admin/messages/1');
check('3.3 T11 Crew gets 403 on /admin/messages/{id}', $rCrewThread['status'] === 403);

$rCrewUnread = $crew->request('GET', '/api/unread');
check('3.4 T11 Crew gets 403 on /api/unread', $rCrewUnread['status'] === 403);

$rCrewPoll = $crew->request('GET', '/api/conversations/1/messages?since_id=0');
check('3.5 T11 Crew gets 403 on /api/conversations/{id}/messages', $rCrewPoll['status'] === 403);

$rCrewClientChat = $crew->request('GET', '/chat');
check('3.6 T11 Crew gets 403 on /chat', $rCrewClientChat['status'] === 403);

// -------------------------------------------------------------------------
// 4. Admin Inbox (A19), Unread Badges, Two-Way Polling & Attachments (T11)
// -------------------------------------------------------------------------
echo "\n--- Section 4: Admin Inbox (A19), Unread Counts, Polling & Attachments ---\n";

$rAdminLoginGet = $admin->request('GET', '/admin/login');
$csrfAdmin = $admin->csrfFrom($rAdminLoginGet['body']);
$rAdminLogin = $admin->request('POST', '/admin/login', [
    '_csrf'    => $csrfAdmin,
    'username' => 'owner',
    'password' => 'Owner@2026',
]);
check('4.1 Owner login succeeds', $rAdminLogin['status'] === 303 && $rAdminLogin['location'] === '/admin');

$rUnreadBefore = $admin->request('GET', '/api/unread');
$unreadJsonBefore = json_decode($rUnreadBefore['body'], true);
check('4.2 GET /api/unread returns 2 unread messages (Santos + Patricia Lim)', ($unreadJsonBefore['ok'] ?? false) === true && (int) ($unreadJsonBefore['unread'] ?? 0) === 2);

$rInbox = $admin->request('GET', '/admin/messages?filter=unread');
check('4.3 Screen A19 /admin/messages?filter=unread lists both unread threads (200 OK)', $rInbox['status'] === 200 && str_contains($rInbox['body'], 'Patricia Lim') && str_contains($rInbox['body'], 'Angela Santos'));

// Admin opens Patricia Lim's conversation -> marks client message as read
$rAdminThread = $admin->request('GET', '/admin/messages/' . $newConvId);
check('4.4 Screen A19 thread view renders Patricia Lim inquiry (200 OK)', $rAdminThread['status'] === 200 && str_contains($rAdminThread['body'], 'Patricia Lim'));
$csrfAdmin = $admin->csrfFrom($rAdminThread['body']);

$rUnreadAfterOpen = $admin->request('GET', '/api/unread');
$unreadJsonAfter = json_decode($rUnreadAfterOpen['body'], true);
check('4.5 Opening thread decrements admin unread count from 2 to 1', (int) ($unreadJsonAfter['unread'] ?? -1) === 1);

// ClientA polls and sees that their message is now marked Seen
$rClientPoll1 = $clientA->request('GET', '/api/conversations/' . $newConvId . '/messages?since_id=' . $firstMsgId . '&as=client&token=' . $rawToken);
$poll1Json = json_decode($rClientPoll1['body'], true);
check('4.6 Client polling sees seen_up_to_id updated to firstMsgId (Sent -> Seen)', (int) ($poll1Json['seen_up_to_id'] ?? 0) === $firstMsgId);

// Admin sends reply with a valid PNG image attachment via AJAX API
$pngFile = tempnam(sys_get_temp_dir(), 'dps_chat_e2e_') . '.png';
file_put_contents(
    $pngFile,
    base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==')
);

$rAdminSend = $admin->request('POST', '/api/conversations/' . $newConvId . '/messages', [
    '_csrf'      => $csrfAdmin,
    'as'         => 'admin',
    'body'       => 'Yes po, Dec 28 is open! Here is our sample package card.',
    'attachment' => new \CURLFile($pngFile, 'image/png', 'sample-card.png'),
]);
@unlink($pngFile);

$adminSendJson = json_decode($rAdminSend['body'], true);
$adminReplyId = (int) ($adminSendJson['message']['id'] ?? 0);
check('4.7 Admin POST /api/conversations/{id}/messages with PNG attachment returns 201', $rAdminSend['status'] === 201 && ($adminSendJson['ok'] ?? false) === true && $adminReplyId > $firstMsgId);

// ClientA polls since_id=$firstMsgId and immediately gets the admin reply + attachment URL
$rClientPoll2 = $clientA->request('GET', '/api/conversations/' . $newConvId . '/messages?since_id=' . $firstMsgId . '&as=client&token=' . $rawToken);
$poll2Json = json_decode($rClientPoll2['body'], true);
$polledMessages = $poll2Json['messages'] ?? [];
check(
    '4.8 T11 Client poll returns only the new admin reply with attachment_url',
    count($polledMessages) === 1
    && (int) ($polledMessages[0]['id'] ?? 0) === $adminReplyId
    && !empty($polledMessages[0]['attachment_url'])
);

// Authorized vs unauthorized access to /files/chat/{id}
$rFileClientA = $clientA->request('GET', '/files/chat/' . $adminReplyId . '?token=' . $rawToken);
check('4.9 Owning client can view chat attachment (/files/chat/{id} -> 200 image/png)', $rFileClientA['status'] === 200 && str_contains($rFileClientA['headers']['content-type'] ?? '', 'image/png'));

$rFileAdmin = $admin->request('GET', '/files/chat/' . $adminReplyId);
check('4.9 Admin can view chat attachment (/files/chat/{id} -> 200)', $rFileAdmin['status'] === 200);

$rFileClientB = $clientB->request('GET', '/files/chat/' . $adminReplyId);
check('4.9 Unrelated clientB gets 404 on /files/chat/{id}', $rFileClientB['status'] === 404);

$rFileCrew = $crew->request('GET', '/files/chat/' . $adminReplyId);
check('4.9 Crew gets 403 on /files/chat/{id}', $rFileCrew['status'] === 403);

// -------------------------------------------------------------------------
// 5. Reject Non-Image & Oversized (> 2 MB) Chat Attachments (T11)
// -------------------------------------------------------------------------
echo "\n--- Section 5: Reject Non-Image & Oversized Uploads (T11) ---\n";

$csrfA = $clientA->csrfFrom($rThreadA['body']);

$fakeFile = tempnam(sys_get_temp_dir(), 'dps_bad_') . '.png';
file_put_contents($fakeFile, '<?php echo "evil"; ?>');
$rBadMime = $clientA->request('POST', '/api/conversations/' . $newConvId . '/messages', [
    '_csrf'      => $csrfA,
    'as'         => 'client',
    'token'      => $rawToken,
    'body'       => 'Bad attachment',
    'attachment' => new \CURLFile($fakeFile, 'image/png', 'evil.png'),
]);
@unlink($fakeFile);
check('5.1 T11 Non-image disguised as .png is rejected with 422', $rBadMime['status'] === 422);

$oversizedFile = tempnam(sys_get_temp_dir(), 'dps_big_') . '.png';
file_put_contents($oversizedFile, str_repeat('X', 2 * 1024 * 1024 + 2048));
$rBigFile = $clientA->request('POST', '/api/conversations/' . $newConvId . '/messages', [
    '_csrf'      => $csrfA,
    'as'         => 'client',
    'token'      => $rawToken,
    'body'       => 'Oversized attachment',
    'attachment' => new \CURLFile($oversizedFile, 'image/png', 'oversized.png'),
]);
@unlink($oversizedFile);
check('5.2 T11 Oversized attachment (> 2 MB) is rejected with 422', $rBigFile['status'] === 422 && str_contains($rBigFile['body'], '2 MB'));

// -------------------------------------------------------------------------
// 6. Close/Reopen, Link to Booking, Export & Payment Verify System Message
// -------------------------------------------------------------------------
echo "\n--- Section 6: Thread Lifecycle, Link to Booking, Export & System Messages ---\n";

$rClose = $admin->request('POST', '/admin/messages/' . $newConvId . '/status', [
    '_csrf'  => $csrfAdmin,
    'status' => 'closed',
]);
check('6.1 Admin closes conversation (303 redirect)', $rClose['status'] === 303);

$rSendClosed = $clientA->request('POST', '/api/conversations/' . $newConvId . '/messages', [
    '_csrf' => $csrfA,
    'as'    => 'client',
    'token' => $rawToken,
    'body'  => 'Trying to reply while closed',
]);
check('6.1 Client reply to closed conversation is refused with 422', $rSendClosed['status'] === 422);

$rReopen = $admin->request('POST', '/admin/messages/' . $newConvId . '/status', [
    '_csrf'  => $csrfAdmin,
    'status' => 'open',
]);
check('6.2 Admin reopens conversation (303 redirect)', $rReopen['status'] === 303);

// Link Patricia Lim's inquiry to Booking #5 (Garcia Wedding, which has no conversation yet)
$rLink = $admin->request('POST', '/admin/messages/' . $newConvId . '/link', [
    '_csrf'      => $csrfAdmin,
    'booking_id' => '5',
]);
check('6.3 Admin links inquiry conversation to Booking #5 (303 redirect)', $rLink['status'] === 303);
$linkedRow = DB::one('SELECT booking_id, client_id FROM conversations WHERE id = ?', [$newConvId]);
check('6.3 DB verifies conversation is now linked to booking_id = 5', (int) ($linkedRow['booking_id'] ?? 0) === 5);

// Export thread transcript
$rExport = $admin->request('GET', '/admin/messages/' . $newConvId . '/export');
check(
    '6.4 GET /admin/messages/{id}/export downloads plain-text transcript',
    $rExport['status'] === 200
    && str_contains($rExport['headers']['content-type'] ?? '', 'text/plain')
    && str_contains($rExport['body'], 'DOUFFER PHOTOGRAPHY — CONVERSATION TRANSCRIPT')
);

// Payment verification posts a system message in Booking #6's chat (T11)
$rVerify = $admin->request('POST', '/admin/payments/8/verify', [
    '_csrf' => $csrfAdmin,
]);
check('6.5 Verifying Payment #8 redirects to A8 confirmation', $rVerify['status'] === 303);
$sysMsg = DB::one(
    "SELECT * FROM messages WHERE conversation_id = 2 AND sender_type = 'system' AND body LIKE '%Payment verified%' ORDER BY id DESC LIMIT 1"
);
check('6.5 T11 Payment verify automatically posts a system message into the booking chat', !empty($sysMsg));

// -------------------------------------------------------------------------
// 7. Rate Limiting (T11: > 10 messages per minute blocked with 429)
// -------------------------------------------------------------------------
echo "\n--- Section 7: Chat Message Rate Limiting (T11) ---\n";

DB::run("DELETE FROM rate_limits WHERE rl_key LIKE 'chat:%'");
$hit429 = false;
for ($i = 1; $i <= 12; $i++) {
    $rBurst = $clientA->request('POST', '/api/conversations/' . $newConvId . '/messages', [
        '_csrf' => $csrfA,
        'as'    => 'client',
        'token' => $rawToken,
        'body'  => "Rapid message #$i",
    ]);
    if ($rBurst['status'] === 429) {
        $hit429 = true;
        break;
    }
}
check('7.1 T11 Rate limiter blocks rapid message burst (>10/min) with HTTP 429', $hit429);

// -------------------------------------------------------------------------
// Restore clean seed state
// -------------------------------------------------------------------------
echo "\nRestoring fresh database state...\n";
passthru('"' . PHP_BINARY . '" ' . escapeshellarg(BASE_DIR . '/database/install.php') . ' --fresh > NUL');

echo "\nSummary: $passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
