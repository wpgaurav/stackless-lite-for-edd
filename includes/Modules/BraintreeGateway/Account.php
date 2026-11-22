<?php

namespace StacklessEDD\Modules\BraintreeGateway;

use StacklessEDD\Contracts\SubscriptionData;

defined('ABSPATH') || exit;

/**
 * Braintree cards on the account page's shared "Payment methods" tab: list, make default, remove
 * (which deletes the card from the vault) and add one with Hosted Fields and 3-D Secure. Opened
 * with ?add=stackless_edd_braintree&subscription={id} it saves a card for that subscription and
 * switches its renewals to it.
 */
final class Account
{
    public const TAB = 'payment-methods';
    public const FIELD = 'stackless_edd_braintree_task';
    private const NONCE = 'stackless_edd_braintree_account';
    private const NONCE_FIELD = '_stackless_edd_braintree_nonce';

    public static function registerHooks(): void
    {
        add_filter('stackless_edd_account_tabs', [self::class, 'tab'], 40);
        add_action('stackless_edd_account_payment_methods', [self::class, 'render'], 30);
        add_action('template_redirect', [self::class, 'handlePost'], 20);
        add_filter('stackless_edd_add_payment_method_url', [self::class, 'filterAddUrl'], 10, 3);
    }

    private static function ready(): bool
    {
        $mode = Config::mode();
        return Config::hasKeys($mode) && Gateway::instance()->isEnabled() && (bool) Connection::currencies($mode);
    }

    /**
     * @param mixed $tabs
     * @return array<string,mixed>
     */
    public static function tab($tabs): array
    {
        $tabs = is_array($tabs) ? $tabs : [];
        if (!is_user_logged_in() || isset($tabs[self::TAB])) {
            return $tabs;
        }
        // The tab stays for customers who already saved a card, even if Braintree was switched off.
        if (!self::ready() && !Tokens::forUser(get_current_user_id())) {
            return $tabs;
        }
        $tabs[self::TAB] = [
            'title' => __('Payment methods', 'stackless-lite-edd'),
            'icon' => 'credit-card',
            'priority' => 40,
            'callback' => static function (array $query = []): void {
                do_action('stackless_edd_account_payment_methods', $query);
            },
        ];
        return $tabs;
    }

    public static function listUrl(): string
    {
        return stackless_edd_account_url(self::TAB);
    }

    public static function addUrl(int $subscription_id = 0): string
    {
        $args = ['add' => Gateway::ID];
        if ($subscription_id > 0) {
            $args['subscription'] = $subscription_id;
        }
        return stackless_edd_account_url(self::TAB, $args);
    }

    /**
     * Where a customer adds a new card (optionally for one subscription).
     *
     * @param mixed $url
     * @param mixed $gateway_id
     * @param mixed $subscription_id
     */
    public static function filterAddUrl($url, $gateway_id = '', $subscription_id = 0): string
    {
        if ((string) $gateway_id !== Gateway::ID || !self::ready()) {
            return (string) $url;
        }
        return self::addUrl((int) $subscription_id);
    }

    /** The subscription when the signed-in user owns it. */
    public static function ownedSubscription(int $subscription_id, int $user_id): ?SubscriptionData
    {
        if ($subscription_id < 1 || $user_id < 1) {
            return null;
        }
        $subscription = stackless_edd_get_subscription($subscription_id);
        return $subscription instanceof SubscriptionData && $subscription->get_user_id() === $user_id ? $subscription : null;
    }

    /** @param mixed $query */
    public static function render($query = []): void
    {
        $query = is_array($query) ? $query : [];
        $user_id = get_current_user_id();
        if ($user_id < 1) {
            return;
        }
        $tokens = Tokens::forUser($user_id);
        $ready = self::ready();
        if (!$ready && !$tokens) {
            return;
        }
        $adding = $ready && ($query['add'] ?? '') === Gateway::ID;
        if (!$adding && ($query['add'] ?? '') !== '') {
            return; // Another gateway's add form owns the tab right now.
        }
        if ($adding) {
            Assets::enqueue();
        }
        stackless_edd_get_template('braintree_gateway/payment-methods.php', [
            'adding' => $adding,
            'can_add' => $ready,
            'subscription' => $adding ? self::ownedSubscription((int) ($query['subscription'] ?? 0), $user_id) : null,
            'tokens' => $adding ? [] : $tokens,
            'list_url' => self::listUrl(),
            'add_url' => self::addUrl(),
            'nonce_action' => self::NONCE,
            'nonce_field' => self::NONCE_FIELD,
            'field' => self::FIELD,
            'testmode' => Config::mode() === 'test',
            'data' => $adding ? Gateway::instance()->scriptData('add') : [],
        ]);
    }

    /** Make default, remove, and add submissions from the tab. */
    public static function handlePost(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- verified right below.
        if (empty($_POST[self::FIELD]) || !is_user_logged_in()) {
            return;
        }
        check_admin_referer(self::NONCE, self::NONCE_FIELD);
        $task = sanitize_key(wp_unslash((string) $_POST[self::FIELD]));
        $user_id = get_current_user_id();
        $token_id = isset($_POST['token']) ? absint($_POST['token']) : 0;
        // phpcs:enable

        if ($task === 'delete' || $task === 'default') {
            $card = Tokens::getOwned($token_id, $user_id);
            if (!$card) {
                stackless_edd_add_notice(__('That card no longer exists.', 'stackless-lite-edd'), 'error');
            } elseif ($task === 'delete') {
                Tokens::delete($card);
                stackless_edd_add_notice(__('Card removed. Subscriptions that used it will ask for another payment method at renewal.', 'stackless-lite-edd'));
            } else {
                Tokens::setDefault($card);
                stackless_edd_add_notice(__('This card is now your default.', 'stackless-lite-edd'));
            }
            wp_safe_redirect(self::listUrl());
            exit;
        }
        if ($task === 'add') {
            $result = self::add($user_id);
            stackless_edd_add_notice($result['message'], $result['result'] === 'success' ? 'success' : 'error');
            wp_safe_redirect($result['redirect']);
            exit;
        }
    }

    /** @return array{result:string, redirect:string, message:string} */
    private static function add(int $user_id): array
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- handlePost() verified the nonce.
        $nonce = isset($_POST[Gateway::FIELD_NONCE]) ? sanitize_text_field(wp_unslash((string) $_POST[Gateway::FIELD_NONCE])) : '';
        $subscription_id = isset($_POST['subscription']) ? absint($_POST['subscription']) : 0;
        // phpcs:enable
        $subscription = self::ownedSubscription($subscription_id, $user_id);
        $back = self::addUrl($subscription ? $subscription_id : 0);
        if (!self::ready()) {
            return ['result' => 'failure', 'redirect' => $back, 'message' => __('Cards cannot be saved with Braintree right now.', 'stackless-lite-edd')];
        }
        $card = Vault::addCard($user_id, $nonce, Connection::cardCurrency(Config::mode(), stackless_edd_base_currency()));
        if (is_wp_error($card)) {
            return ['result' => 'failure', 'redirect' => $back, 'message' => $card->get_error_message()];
        }
        if (!$subscription) {
            return ['result' => 'success', 'redirect' => self::listUrl(), 'message' => __('Card saved.', 'stackless-lite-edd')];
        }
        $updated = Gateway::instance()->updateSubscriptionMethod($subscription, $card);
        return [
            'result' => 'success',
            'redirect' => stackless_edd_account_url('subscriptions', ['id' => $subscription->get_id()]),
            'message' => $updated ? __('Payment method updated.', 'stackless-lite-edd') : __('Card saved.', 'stackless-lite-edd'),
        ];
    }
}
