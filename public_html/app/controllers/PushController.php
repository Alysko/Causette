<?php
use Causette\Push\PushEndpoint;

require_once __DIR__ . '/../core/PushEndpoint.php';

class PushController extends Controller {
    public function status() {
        Auth::requireAuth();

        $userId = (int)Auth::user()->id;
        $publicKey = WebPushService::getVapidPublicKey();
        $configured = WebPushService::isConfigured();

        $this->json([
            'success' => true,
            'configured' => $configured,
            'vapid_public_key' => $configured ? $publicKey : '',
            'enabled' => (string)(Setting::get('web_push_notifications_' . $userId) ?? '0') === '1',
            'subscription_count' => PushSubscription::countForUser($userId),
            'csrf_token' => $this->csrfToken(),
        ]);
    }

    public function subscribe() {
        Auth::requireAuth();
        Auth::csrfValidate();

        if (!WebPushService::isConfigured()) {
            $this->json(['error' => 'Web push is not configured on this server'], 503);
        }

        $userId = (int)Auth::user()->id;
        $endpoint = $_POST['endpoint'] ?? null;
        $p256dh = $_POST['p256dh'] ?? null;
        $auth = $_POST['auth'] ?? null;
        $userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        $userAgent = preg_match('//u', $userAgent) === 1 ? $userAgent : '';

        if (!is_string($endpoint) || !is_string($p256dh) || !is_string($auth)
            || PushEndpoint::parse($endpoint, WebPushService::allowedProviders()) === null
            || !preg_match('/\AB[A-Za-z0-9_-]{86}=?\z/D', $p256dh)
            || !preg_match('/\A[A-Za-z0-9_-]{22}(?:==)?\z/D', $auth)
        ) {
            $this->json(['error' => 'Invalid subscription payload'], 400);
        }

        $saved = PushSubscription::upsertForUser($userId, $endpoint, $p256dh, $auth, $userAgent);
        if ($saved !== PushSubscription::SAVED) {
            $message = $saved === PushSubscription::LIMIT ? 'Push subscription limit reached' : 'Push subscription is unavailable';
            $this->json(['error' => $message], 409);
        }

        Setting::set('web_push_notifications_' . $userId, '1');

        $this->json([
            'success' => true,
            'subscription_count' => PushSubscription::countForUser($userId),
            'csrf_token' => $this->csrfToken(),
        ]);
    }

    public function unsubscribe() {
        Auth::requireAuth();
        Auth::csrfValidate();

        $userId = (int)Auth::user()->id;
        $endpoint = $_POST['endpoint'] ?? null;
        if (is_string($endpoint) && $endpoint !== '' && strlen($endpoint) <= PushEndpoint::MAX_LENGTH) {
            PushSubscription::removeForUserByEndpoint($userId, $endpoint);
        }

        $remaining = PushSubscription::countForUser($userId);
        if ($remaining <= 0) {
            Setting::set('web_push_notifications_' . $userId, '0');
        }

        $this->json([
            'success' => true,
            'subscription_count' => $remaining,
            'csrf_token' => $this->csrfToken(),
        ]);
    }
}
