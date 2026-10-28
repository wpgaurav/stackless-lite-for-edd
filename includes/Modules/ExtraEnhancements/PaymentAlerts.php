<?php

namespace StacklessEDD\Modules\ExtraEnhancements;

defined('ABSPATH') || exit;

/**
 * Emails the store owner about live payment failures: a live order of a watched gateway that
 * turns Failed, and failed live API calls made by Stackless's Stripe, PayPal, Polar, Paddle and
 * Dodo Payments code (refunds, checkouts, renewals).
 *
 * Test and sandbox activity never alerts. No keys, customer details or raw request and response
 * bodies go into the email. Repeats of the same failure (same order, or same API error) are held
 * back for 30 minutes, and mail is sent from Action Scheduler, never inside checkout.
 */
final class PaymentAlerts
{
    public const SEND_HOOK = 'stackless_edd_extra_enhancements_payment_alert';
    public const CLEAN_HOOK = 'stackless_edd_extra_enhancements_payment_alert_cleanup';
    public const GROUP = 'stackless-edd-extra_enhancements';
    public const LAST_RESULT = 'stackless_edd_extra_enhancements_alert_last_result';
    public const WINDOW = 1800;

    /**
     * Route => label, gateway ids (copies of a family included), live API hosts, the namespace of
     * the code whose calls count, and the Stackless module that holds the gateway's settings.
     */
    public const GATEWAYS = [
        'stripe' => ['label' => 'Stripe', 'ids' => ['stackless_edd_stripe'], 'hosts' => ['api.stripe.com'], 'class' => 'StacklessEDD\\Modules\\StripeGateway\\', 'module' => 'stripe_gateway'],
        'paypal' => ['label' => 'PayPal', 'ids' => ['stackless_edd_paypal'], 'hosts' => ['api-m.paypal.com', 'api.paypal.com'], 'class' => 'StacklessEDD\\Modules\\PayPalGateway\\', 'module' => 'paypal_gateway'],
        'polar' => ['label' => 'Polar', 'ids' => ['stackless_edd_polar'], 'hosts' => ['api.polar.sh'], 'class' => 'StacklessEDD\\Modules\\PolarGateway\\', 'module' => 'polar_gateway'],
        'paddle' => ['label' => 'Paddle', 'ids' => ['stackless_edd_paddle'], 'hosts' => ['api.paddle.com'], 'class' => 'StacklessEDD\\Modules\\PaddleGateway\\', 'module' => 'paddle_gateway'],
        'dodo' => ['label' => 'Dodo Payments', 'ids' => ['stackless_edd_dodo'], 'hosts' => ['live.dodopayments.com'], 'class' => 'StacklessEDD\\Modules\\DodoGateway\\', 'module' => 'dodo_gateway'],
    ];

    /** Order meta a gateway may record with the mode it charged in; EDD's own order mode is the fallback. */
    private const MODE_META = ['_stackless_edd_stripe_mode', '_stackless_edd_polar_mode', '_stackless_edd_paddle_mode', '_stackless_edd_dodo_mode'];

    /** @var array<string,array> */
    private static $pending = [];

    /** @var array<int,string> order id => route */
    private static $orders = [];

    /** @var string */
    private static $recipient = '';

    /** @var string[] */
    private static $routes = [];

    /**
     * Gateways to watch from the saved setting: every gateway until the setting is first saved
     * (null), exactly the saved list after that, so emptying the list watches none.
     *
     * @param mixed $saved
     * @return string[]
     */
    public static function selectedRoutes($saved): array
    {
        if ($saved === null) {
            return array_keys(self::GATEWAYS);
        }
        $saved = is_array($saved) ? array_map('strval', $saved) : [];
        return array_values(array_intersect(array_keys(self::GATEWAYS), $saved));
    }

    /** @param string[] $routes Watched gateways (see selectedRoutes()); none means no alerts. */
    public static function init(string $recipient = '', array $routes = []): void
    {
        self::$recipient = $recipient;
        self::$routes = array_values(array_intersect(array_keys(self::GATEWAYS), $routes));
        if (!self::$routes) {
            return;
        }
        add_action('http_api_debug', [self::class, 'httpResult'], 10, 5);
        add_action('edd_transition_order_status', [self::class, 'orderStatus'], 10, 3);
        add_action('shutdown', [self::class, 'flush'], 5);
        add_action(self::SEND_HOOK, [self::class, 'send']);
        add_action(self::CLEAN_HOOK, [self::class, 'cleanup']);
        if (defined('WP_CLI') && WP_CLI && class_exists(\WP_CLI::class)) {
            \WP_CLI::add_command('stackless-edd payment-alert-test', [self::class, 'testEmail']);
        }
    }

    /** @return string[] */
    public static function routes(): array
    {
        return self::$routes;
    }

    public static function recipient(): string
    {
        return self::$recipient !== '' && is_email(self::$recipient) ? self::$recipient : (string) get_option('admin_email');
    }

    /** The watched route of a gateway id, copies included ("stackless_edd_dodo_copy2" → dodo). */
    public static function routeForGateway(string $id, ?array $routes = null): string
    {
        foreach ($routes ?? self::$routes as $route) {
            foreach (self::GATEWAYS[$route]['ids'] ?? [] as $base) {
                if ($id === $base || preg_match('/^' . preg_quote($base, '/') . '_copy\d+$/', $id)) {
                    return $route;
                }
            }
        }
        return '';
    }

    /* ---- API calls --------------------------------------------------------------------- */

    /** The watched gateway whose live API this URL belongs to, and whose code made the call. */
    private static function routeForCall(string $url, array $args): string
    {
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        foreach (self::$routes as $route) {
            $gateway = self::GATEWAYS[$route];
            if (!in_array($host, $gateway['hosts'], true) || !self::calledBy($gateway['class'])) {
                continue;
            }
            return self::isLiveCall($route, $args) ? $route : '';
        }
        return '';
    }

    private static function calledBy(string $prefix): bool
    {
        // Tells which gateway made the API call; arguments are not collected and nothing is printed.
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40) as $frame) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
            if (strpos((string) ($frame['class'] ?? ''), $prefix) === 0) {
                return true;
            }
        }
        return false;
    }

    /** Stripe shares one API host for test and live; the key's prefix tells them apart. */
    public static function isLiveCall(string $route, array $args): bool
    {
        if ($route !== 'stripe') {
            return true; // The other providers use separate sandbox hosts, which are not watched.
        }
        $headers = is_array($args['headers'] ?? null) ? array_change_key_case($args['headers']) : [];
        $auth = (string) ($headers['authorization'] ?? '');
        if (stripos($auth, 'Bearer ') === 0) {
            $auth = substr($auth, 7);
        }
        return strpos($auth, 'sk_live_') === 0 || strpos($auth, 'rk_live_') === 0;
    }

    /**
     * @param mixed $response
     * @param mixed $context
     * @param mixed $transport
     * @param mixed $args
     * @param mixed $url
     */
    public static function httpResult($response, $context, $transport, $args, $url): void
    {
        if ($context !== 'response') {
            return;
        }
        $args = is_array($args) ? $args : [];
        $route = self::routeForCall((string) $url, $args);
        if ($route === '') {
            return;
        }
        $path = (string) wp_parse_url((string) $url, PHP_URL_PATH);
        $method = strtoupper((string) ($args['method'] ?? 'GET'));
        $operation = hash('sha256', $route . $method . $path);
        $http = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
        $body = is_wp_error($response) ? [] : json_decode((string) wp_remote_retrieve_body($response), true);
        $body = is_array($body) ? $body : [];
        if (!is_wp_error($response) && $http >= 200 && $http < 300) {
            // A retry, refreshed auth, or reconciliation recovered.
            unset(self::$pending[$operation]);
            return;
        }
        if (self::expected($route, $method, $http, $body)) {
            return;
        }
        self::$pending[$operation] = [
            'route' => $route,
            'operation' => self::operationLabel($route, $path),
            'http' => $http,
            'code' => self::safeCode(is_wp_error($response) ? $response->get_error_code() : self::errorCode($route, $body)),
            'debug_id' => self::debugId($route, $response, $body),
        ];
    }

    /** Lookups that miss and card declines are normal: the gateway handles them, and declines alert as failed orders. */
    public static function expected(string $route, string $method, int $http, array $body): bool
    {
        if ($method === 'GET' && $http === 404) {
            return true;
        }
        return $route === 'stripe' && (($body['error']['type'] ?? '') === 'card_error' || $http === 402);
    }

    /** @return mixed */
    private static function errorCode(string $route, array $body)
    {
        switch ($route) {
            case 'paypal':
                return $body['details'][0]['issue'] ?? $body['error'] ?? $body['name'] ?? 'HTTP_ERROR';
            case 'stripe':
                return $body['error']['code'] ?? $body['error']['type'] ?? 'HTTP_ERROR';
            case 'paddle':
                return $body['error']['code'] ?? 'HTTP_ERROR';
            case 'dodo':
                return $body['code'] ?? 'HTTP_ERROR';
            default:
                return is_string($body['error'] ?? null) ? $body['error'] : 'HTTP_ERROR';
        }
    }

    /** @param mixed $response */
    private static function debugId(string $route, $response, array $body): string
    {
        if ($route === 'paypal') {
            return preg_match('/^[a-f0-9]{8,32}$/i', (string) ($body['debug_id'] ?? '')) ? (string) $body['debug_id'] : '';
        }
        if ($route === 'stripe' && !is_wp_error($response)) {
            $id = (string) wp_remote_retrieve_header($response, 'request-id');
            return preg_match('/^req_[A-Za-z0-9]{6,40}$/', $id) ? $id : '';
        }
        if ($route === 'paddle') {
            return preg_match('/^[a-z0-9-]{8,64}$/i', (string) ($body['meta']['request_id'] ?? '')) ? (string) $body['meta']['request_id'] : '';
        }
        return '';
    }

    /** @param mixed $value */
    public static function safeCode($value): string
    {
        return is_string($value) && preg_match('/^[a-z0-9_.-]{1,80}$/i', $value) ? $value : 'UNKNOWN_ERROR';
    }

    public static function operationLabel(string $route, string $path): string
    {
        $map = [
            'paypal' => ['/oauth2/token' => 'Authentication', '/capture' => 'Payment capture', '/refund' => 'Refund', '/checkout/orders' => 'Checkout order', '/notifications/' => 'Webhook setup or verification', '/vault/' => 'Saved payment method'],
            'stripe' => ['/refunds' => 'Refund', '/payment_intents' => 'Payment', '/setup_intents' => 'Saved payment method', '/customers' => 'Customer', '/payment_methods' => 'Saved payment method', '/webhook_endpoints' => 'Webhook setup', '/disputes' => 'Dispute'],
            'polar' => ['/refunds' => 'Refund', '/checkouts' => 'Checkout session', '/products' => 'Product', '/orders' => 'Order lookup', '/subscriptions' => 'Subscription', '/organizations' => 'Authentication'],
            'paddle' => ['/adjustments' => 'Refund', '/transactions' => 'Transaction', '/subscriptions' => 'Subscription', '/customers' => 'Customer'],
            'dodo' => ['/refunds' => 'Refund', '/checkouts' => 'Checkout session', '/products' => 'Product', '/payments' => 'Payment lookup', '/subscriptions' => 'Subscription', '/brands' => 'Authentication'],
        ];
        foreach ($map[$route] ?? [] as $needle => $label) {
            if (strpos($path, $needle) !== false) {
                return $label;
            }
        }
        return self::GATEWAYS[$route]['label'] . ' connection';
    }

    /* ---- Failed orders ----------------------------------------------------------------- */

    /** Whether an order was taken in live mode. A gateway's own mode meta wins; then EDD's order mode. */
    public static function liveOrder(\EDD\Orders\Order $order): bool
    {
        foreach (self::MODE_META as $key) {
            $mode = (string) edd_get_order_meta((int) $order->id, $key, true);
            if ($mode !== '') {
                return $mode === 'live';
            }
        }
        return (string) $order->mode === 'live';
    }

    /**
     * edd_transition_order_status: a watched live order that fails is queued; one that fails and
     * recovers in the same request (a retried charge) needs no alert.
     *
     * @param mixed $old
     * @param mixed $new
     * @param mixed $orderId
     */
    public static function orderStatus($old, $new, $orderId): void
    {
        $orderId = (int) $orderId;
        if (in_array((string) $new, ['complete', 'publish', 'processing', 'on_hold'], true)) {
            unset(self::$orders[$orderId]);
            return;
        }
        if ((string) $new !== 'failed') {
            return;
        }
        $order = edd_get_order($orderId);
        if (!$order instanceof \EDD\Orders\Order) {
            return;
        }
        $route = self::routeForGateway((string) $order->gateway);
        if ($route !== '' && self::liveOrder($order)) {
            self::$orders[$orderId] = $route;
        }
    }

    /** Queue only terminal failures; mail never sends inside checkout. */
    public static function flush(): void
    {
        $errors = array_values(self::$pending);
        $orders = self::$orders;
        self::$pending = [];
        self::$orders = [];
        if (!$errors && !$orders) {
            return;
        }
        if (!$orders) {
            foreach (array_unique(array_column($errors, 'route')) as $route) {
                self::queue(['order_id' => 0, 'route' => $route, 'errors' => array_slice(self::forRoute($errors, $route), 0, 8), 'time' => gmdate('c'), 'test' => false]);
            }
            return;
        }
        foreach ($orders as $id => $route) {
            $own = self::forRoute($errors, $route) ?: [['route' => $route, 'operation' => 'Payment', 'http' => 0, 'code' => 'PAYMENT_FAILED', 'debug_id' => '']];
            self::queue(['order_id' => $id, 'route' => $route, 'errors' => array_slice($own, 0, 8), 'time' => gmdate('c'), 'test' => false]);
        }
    }

    private static function forRoute(array $errors, string $route): array
    {
        return array_values(array_filter($errors, static function ($error) use ($route) {
            return ($error['route'] ?? '') === $route;
        }));
    }

    /** Same order, or same API error on the same route, within the window counts once. */
    public static function signature(array $payload): string
    {
        if (!empty($payload['order_id'])) {
            return 'order:' . (int) $payload['order_id'];
        }
        return (string) ($payload['route'] ?? '') . '|' . implode('|', array_map(static function ($error) {
            return $error['operation'] . ':' . $error['http'] . ':' . $error['code'];
        }, (array) ($payload['errors'] ?? [])));
    }

    /** @return string|false Queue key, or false when held back or not queued. */
    public static function queue(array $payload)
    {
        $key = substr(hash('sha256', (!empty($payload['test']) ? 'test:' . wp_generate_uuid4() : '') . self::signature($payload)), 0, 32);
        $lock = 'stackless_edd_ee_alert_lock_' . $key;
        $existing = (int) get_option($lock, 0);
        if ($existing && $existing < time() - self::WINDOW) {
            delete_option($lock);
        }
        if (!add_option($lock, time(), '', false)) {
            return false;
        }
        $payload['attempts'] = 0;
        set_transient('stackless_edd_ee_alert_' . $key, $payload, DAY_IN_SECONDS);
        if (!self::schedule(time() + 1, self::SEND_HOOK, $key)) {
            delete_option($lock);
            delete_transient('stackless_edd_ee_alert_' . $key);
            \StacklessEDD\Core\Logger::log('extra_enhancements', 'A payment alert could not be queued. Check Action Scheduler or WP-Cron.', 'error');
            return false;
        }
        self::schedule(time() + self::WINDOW + 1, self::CLEAN_HOOK, $key);
        return $key;
    }

    private static function schedule(int $time, string $hook, string $key): bool
    {
        if (function_exists('as_schedule_single_action')) {
            return (bool) as_schedule_single_action($time, $hook, [$key], self::GROUP);
        }
        $scheduled = wp_schedule_single_event($time, $hook, [$key], true);
        return $scheduled === true;
    }

    /** @return array{subject:string,body:string} */
    public static function compose(array $payload): array
    {
        $route = isset(self::GATEWAYS[$payload['route'] ?? '']) ? (string) $payload['route'] : 'stripe';
        $label = self::GATEWAYS[$route]['label'];
        $host = (string) wp_parse_url(home_url(), PHP_URL_HOST);
        $subject = (!empty($payload['test']) ? '[TEST] ' : '') . '[Shop] ' . $label . ' failure on ' . $host;
        $lines = [
            !empty($payload['test']) ? 'This is a controlled alert test. No payment failed or was charged.' : 'A live ' . $label . ' operation failed on your shop.',
            'Time (UTC): ' . (string) ($payload['time'] ?? ''),
        ];
        $order = !empty($payload['order_id']) ? edd_get_order((int) $payload['order_id']) : null;
        if ($order instanceof \EDD\Orders\Order) {
            $lines[] = 'Order: #' . $order->get_number();
            $lines[] = 'Review order: ' . edd_get_admin_url(['page' => 'edd-payment-history', 'view' => 'view-order-details', 'id' => (int) $order->id]);
        }
        foreach ((array) ($payload['errors'] ?? []) as $error) {
            $lines[] = $error['operation'] . ' | ' . $error['code'] . ' | HTTP: ' . ($error['http'] ?: 'not available');
            if (!empty($error['debug_id'])) {
                $lines[] = $label . ($route === 'stripe' ? ' request ID: ' : ' debug ID: ') . $error['debug_id'];
            }
        }
        $lines[] = $label . ' settings: ' . \StacklessEDD\Admin\SettingsPage::url(self::GATEWAYS[$route]['module']);
        $lines[] = 'Check the order and ' . $label . ' before retrying or requesting another payment. A failed API response does not prove that no payment was captured.';
        $lines[] = 'Repeated alerts for this order or API error are held back for 30 minutes. Keys, customer details and raw request and response bodies are left out.';
        return ['subject' => $subject, 'body' => implode("\n\n", $lines)];
    }

    /** @param mixed $key */
    public static function send($key): void
    {
        if (!is_string($key) || !preg_match('/^[a-f0-9]{32}$/', $key)) {
            return;
        }
        $payload = get_transient('stackless_edd_ee_alert_' . $key);
        if (!is_array($payload)) {
            return;
        }
        $mail = self::compose($payload);
        $recipient = self::recipient();
        try {
            $sent = is_email($recipient) && wp_mail($recipient, $mail['subject'], $mail['body'], ['Content-Type: text/plain; charset=UTF-8']);
        } catch (\Throwable $exception) {
            $sent = false;
        }
        update_option(self::LAST_RESULT, ['time' => gmdate('c'), 'route' => (string) ($payload['route'] ?? ''), 'accepted_by_mailer' => (bool) $sent, 'test' => !empty($payload['test'])], false);
        if ($sent) {
            delete_transient('stackless_edd_ee_alert_' . $key);
        } elseif (++$payload['attempts'] < 3) {
            set_transient('stackless_edd_ee_alert_' . $key, $payload, DAY_IN_SECONDS);
            self::schedule(time() + 300, self::SEND_HOOK, $key);
        } else {
            delete_transient('stackless_edd_ee_alert_' . $key);
            \StacklessEDD\Core\Logger::log('extra_enhancements', 'A payment alert email failed after three attempts. Check mail delivery.', 'error');
        }
    }

    /** @param mixed $key */
    public static function cleanup($key): void
    {
        if (!is_string($key) || !preg_match('/^[a-f0-9]{32}$/', $key)) {
            return;
        }
        // An old event must not erase a newer lock for this fingerprint.
        if ((int) get_option('stackless_edd_ee_alert_lock_' . $key, 0) > time() - self::WINDOW) {
            return;
        }
        delete_option('stackless_edd_ee_alert_lock_' . $key);
        delete_transient('stackless_edd_ee_alert_' . $key);
    }

    public static function testEmail(): void
    {
        $route = self::$routes[0] ?? 'stripe';
        $key = self::queue(['order_id' => 0, 'route' => $route, 'errors' => [['route' => $route, 'operation' => 'Authentication', 'http' => 401, 'code' => 'invalid_client', 'debug_id' => '']], 'time' => gmdate('c'), 'test' => true]);
        if ($key) {
            self::send($key);
        }
        $result = get_option(self::LAST_RESULT, []);
        $message = !$key ? 'Could not queue the test alert.' : 'Test alert for ' . self::recipient() . (!empty($result['accepted_by_mailer']) ? ' was accepted by the mailer.' : ' was not accepted by the mailer; it will be retried.');
        if (class_exists(\WP_CLI::class)) {
            $key && !empty($result['accepted_by_mailer']) ? \WP_CLI::success($message) : \WP_CLI::warning($message);
        }
    }
}
