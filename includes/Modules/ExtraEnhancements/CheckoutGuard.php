<?php

namespace StacklessEDD\Modules\ExtraEnhancements;

defined('ABSPATH') || exit;

/**
 * Spam and fraud guard at checkout: refuses throwaway email addresses and slows down a
 * connection that tries to place too many orders in an hour (card testing). Runs on the
 * [download_checkout] shortcode and the EDD Checkout block alike. Store managers are never limited.
 *
 * EDD validates a purchase twice: once over AJAX while the buyer is still on the form, then on the
 * real submit (or a gateway's own AJAX step). One attempt is counted once, on whichever pass runs
 * first, so the AJAX pass already tells the buyer when the limit is reached.
 */
final class CheckoutGuard
{
    /** Well-known throwaway inbox services. Add more in the module settings. */
    public const DISPOSABLE = [
        '10minutemail.com', '1secmail.com', '1secmail.net', '1secmail.org', '20minutemail.com', 'burnermail.io', 'discard.email', 'dispostable.com',
        'dropmail.me', 'emailfake.com', 'emailondeck.com', 'fakeinbox.com', 'fakemail.net', 'getnada.com', 'grr.la', 'guerrillamail.biz',
        'guerrillamail.com', 'guerrillamail.de', 'guerrillamail.info', 'guerrillamail.net', 'guerrillamail.org', 'guerrillamailblock.com', 'inboxkitten.com',
        'mail.tm', 'mail7.io', 'mailcatch.com', 'maildrop.cc', 'mailinator.com', 'mailinator.net', 'mailnesia.com', 'mailpoof.com', 'mailsac.com',
        'minuteinbox.com', 'mintemail.com', 'moakt.com', 'mohmal.com', 'mytemp.email', 'pokemail.net', 'sharklasers.com', 'spam4.me', 'spambox.us',
        'spamgourmet.com', 'tempail.com', 'tempinbox.com', 'tempmail.com', 'tempmail.net', 'tempmailaddress.com', 'tempmailo.com', 'temp-mail.io',
        'temp-mail.org', 'tempr.email', 'throwawaymail.com', 'tmpmail.net', 'tmpmail.org', 'trash-mail.com', 'trashmail.com', 'trashmail.de',
        'wegwerfmail.de', 'yopmail.com', 'yopmail.fr', 'yopmail.net',
    ];

    /** Session key: when this visitor's current attempt was counted. */
    private const COUNTED = 'stackless_edd_extra_enhancements_guard_counted';

    /** @var bool */
    private $blockDisposable;

    /** @var string[] */
    private $extraDomains;

    /** @var int */
    private $hourlyLimit;

    /** @var bool Counted once per request. */
    private $counted = false;

    /** @param string[] $extraDomains */
    public function __construct(bool $blockDisposable, array $extraDomains, int $hourlyLimit)
    {
        $this->blockDisposable = $blockDisposable;
        $this->extraDomains = $extraDomains;
        $this->hourlyLimit = max(0, $hourlyLimit);
    }

    /** One domain per line, comma or space; "@example.com" also works. @return string[] */
    public static function parseDomains(string $value): array
    {
        $domains = [];
        foreach ((array) preg_split('/[\s,;]+/', strtolower($value)) as $domain) {
            $domain = ltrim(trim((string) $domain), '@');
            if (preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $domain)) {
                $domains[] = $domain;
            }
        }
        return array_values(array_unique($domains));
    }

    public function register(): void
    {
        if (!$this->blockDisposable && !$this->extraDomains && $this->hourlyLimit === 0) {
            return;
        }
        add_action('edd_checkout_error_checks', [$this, 'validate'], 5, 2);
    }

    public function blockedDomain(string $email): bool
    {
        $at = strrpos($email, '@');
        if ($at === false) {
            return false;
        }
        $domain = strtolower(trim(substr($email, $at + 1)));
        $blocked = array_merge($this->blockDisposable ? self::DISPOSABLE : [], $this->extraDomains);
        foreach ($blocked as $entry) {
            // Subdomains of a blocked service count too.
            if ($domain === $entry || substr($domain, -strlen('.' . $entry)) === '.' . $entry) {
                return true;
            }
        }
        return false;
    }

    /** Null when the attempt may go ahead, else the message shown to the buyer. */
    public function refusal(string $email, bool $ajaxPass = false): ?string
    {
        if (current_user_can('manage_shop_settings')) {
            return null;
        }
        if ($email !== '' && $this->blockedDomain($email)) {
            return __('Please use an email address you keep. We send your receipt and downloads there.', 'stackless-lite-edd');
        }
        if ($this->hourlyLimit > 0 && !$this->counted && $this->shouldCount($ajaxPass)) {
            $this->counted = true;
            if (!$this->withinLimit(self::clientIp())) {
                return __('Too many order attempts from your connection. Please try again in an hour, or contact us for help.', 'stackless-lite-edd');
            }
        }
        return null;
    }

    /** One count per attempt: the AJAX check counts and marks the session; the submit right after it does not. */
    private function shouldCount(bool $ajaxPass): bool
    {
        if (!function_exists('EDD') || !EDD()->session) {
            return true;
        }
        $marked = (int) EDD()->session->get(self::COUNTED);
        if ($ajaxPass) {
            EDD()->session->set(self::COUNTED, (string) time());
            return true;
        }
        EDD()->session->set(self::COUNTED, null);
        return !$marked || $marked < time() - 10 * MINUTE_IN_SECONDS;
    }

    /**
     * @param mixed $validData
     * @param mixed $post
     */
    public function validate($validData, $post): void
    {
        $post = is_array($post) ? $post : [];
        $email = isset($post['edd_email']) && is_string($post['edd_email']) ? sanitize_email(wp_unslash($post['edd_email'])) : '';
        if ($email === '' && is_array($validData) && !empty($validData['logged_in_user']['user_email'])) {
            $email = (string) $validData['logged_in_user']['user_email'];
        }
        $message = $this->refusal($email, !empty($post['edd_ajax']));
        if ($message !== null) {
            edd_set_error('stackless_edd_checkout_guard', $message); // @phpstan-ignore argument.type
        }
    }

    public static function clientIp(): string
    {
        $ip = function_exists('edd_get_ip') ? (string) edd_get_ip() : '';
        if ($ip === '' && isset($_SERVER['REMOTE_ADDR'])) {
            $ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
        }
        return $ip;
    }

    /** Counts order attempts per visitor IP in a rolling hour. Only a salted hash of the IP is stored. */
    public function withinLimit(string $ip): bool
    {
        if ($ip === '') {
            return true;
        }
        $key = 'stackless_edd_extra_enhancements_attempts_' . substr(hash_hmac('sha256', $ip, wp_salt('nonce')), 0, 24);
        $now = time();
        $attempts = array_values(array_filter((array) get_transient($key), static function ($time) use ($now) {
            return (int) $time > $now - HOUR_IN_SECONDS;
        }));
        if (count($attempts) >= $this->hourlyLimit) {
            return false;
        }
        $attempts[] = $now;
        set_transient($key, $attempts, HOUR_IN_SECONDS);
        return true;
    }
}
