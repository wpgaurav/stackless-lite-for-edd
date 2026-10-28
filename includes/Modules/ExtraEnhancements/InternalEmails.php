<?php

namespace StacklessEDD\Modules\ExtraEnhancements;

defined('ABSPATH') || exit;

/**
 * Internal and test emails: orders from listed addresses or domains are recorded as usual, but
 * EDD's automatic account creation skips them and integrations don't run (Stackless modules that
 * ask the stackless_edd_skip_integrations filter, such as the FluentCRM sync). Their orders carry
 * the order meta _stackless_edd_internal_email = yes.
 */
final class InternalEmails
{
    public const META = '_stackless_edd_internal_email';

    /** @var string[] */
    private $rules;

    /** @var bool */
    private $skipAccounts;

    /** @var bool */
    private $skipIntegrations;

    /** @param string[] $rules */
    public function __construct(array $rules, bool $skipAccounts, bool $skipIntegrations)
    {
        $this->rules = $rules;
        $this->skipAccounts = $skipAccounts;
        $this->skipIntegrations = $skipIntegrations;
    }

    /** One address or domain per line, comma or space. "@example.com" and "example.com" both mean the whole domain. @return string[] */
    public static function parse(string $value): array
    {
        $rules = [];
        foreach ((array) preg_split('/[\s,;]+/', strtolower($value)) as $rule) {
            $rule = trim((string) $rule);
            if ($rule === '') {
                continue;
            }
            if (strpos($rule, '@') > 0) {
                if (is_email($rule)) {
                    $rules[] = $rule;
                }
                continue;
            }
            $domain = ltrim($rule, '@');
            if (preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain)) {
                $rules[] = '@' . $domain;
            }
        }
        return array_values(array_unique($rules));
    }

    public function matches(string $email): bool
    {
        $email = strtolower(trim($email));
        $at = strpos($email, '@');
        if ($email === '' || $at === false) {
            return false;
        }
        $domain = substr($email, $at);
        foreach ($this->rules as $rule) {
            if ($rule === $email || ($rule[0] === '@' && $rule === $domain)) {
                return true;
            }
        }
        return false;
    }

    public function register(): void
    {
        if ($this->skipAccounts) {
            add_filter('edd_can_create_user_for_order', [$this, 'noAccount'], 99, 3);
        }
        if ($this->skipIntegrations) {
            add_filter('stackless_edd_skip_integrations', [$this, 'skip'], 10, 2);
        }
        add_action('edd_built_order', [$this, 'mark'], 5, 2);
    }

    /**
     * EDD's automatic account creation (Customers → Accounts) leaves internal addresses alone.
     *
     * @param mixed $can
     * @param mixed $order
     * @param mixed $userName
     * @return mixed
     */
    public function noAccount($can, $order = null, $userName = '')
    {
        return $order instanceof \EDD\Orders\Order && $this->matches((string) $order->email) ? false : $can;
    }

    /** @param mixed $skip */
    public function skip($skip, $email = ''): bool
    {
        return (bool) $skip || $this->matches((string) $email);
    }

    /**
     * Tags the order and leaves a note so nobody wonders why nothing ran.
     *
     * @param mixed $orderId
     * @param mixed $orderData
     */
    public function mark($orderId, $orderData = []): void
    {
        $orderId = (int) $orderId;
        $order = $orderId > 0 ? edd_get_order($orderId) : null;
        if (!$order instanceof \EDD\Orders\Order || !$this->matches((string) $order->email)) {
            return;
        }
        $skipped = array_filter([
            $this->skipAccounts ? __('no account was created', 'stackless-lite-edd') : '',
            $this->skipIntegrations ? __('integrations did not run', 'stackless-lite-edd') : '',
        ]);
        edd_update_order_meta($orderId, self::META, 'yes');
        if ($skipped) {
            /* translators: %s: what was skipped, e.g. "no account was created and integrations did not run" */
            stackless_edd_add_order_note($orderId, sprintf(__('Internal email: this address is on the Extra Enhancements internal list, so %s.', 'stackless-lite-edd'), implode(__(' and ', 'stackless-lite-edd'), $skipped)));
        }
    }
}
