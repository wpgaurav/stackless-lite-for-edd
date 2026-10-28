<?php

namespace StacklessEDD\Core;

defined('ABSPATH') || exit;

/**
 * Base for every email Stackless sends (the WC_Email counterpart). Each email has an on/off switch,
 * subject and heading the store owner edits on Stackless → Emails, and a body template that is
 * wrapped in EDD's own email template (Downloads → Settings → Emails → Template), so Stackless
 * emails look like the store's EDD receipts.
 *
 * A module registers its emails in boot(): Emails::register(new RenewalInvoiceEmail());
 * and sends one with Emails::get('subscription_renewal_invoice')->send($to, $args, $placeholders).
 *
 * Body templates live in templates/{module}/emails/{name}.php, receive $args plus $email and
 * $heading, and print only the body: no header or footer.
 */
abstract class Email
{
    /** @var \EDD\Emails\Base|null One EDD mailer for every Stackless email: each instance hooks edd_email_send_before/after. */
    private static $mailer;

    /** Unique, prefixed by the module: subscription_renewal_invoice, license_expiring… */
    abstract public function id(): string;

    abstract public function title(): string;

    abstract public function description(): string;

    /** Path under templates/, e.g. "subscriptions/emails/renewal-invoice.php". */
    abstract protected function template(): string;

    abstract protected function defaultSubject(): string;

    abstract protected function defaultHeading(): string;

    /** Module id that owns the email (groups it on Stackless → Emails). */
    abstract public function module(): string;

    /** Customer emails are held on a copy of the live store; admin emails always send. */
    public function isCustomerEmail(): bool
    {
        return true;
    }

    public function enabledByDefault(): bool
    {
        return true;
    }

    /** Placeholders the subject and heading accept, for the settings help text. @return string[] */
    public function placeholders(): array
    {
        return ['{site_title}'];
    }

    public function optionName(): string
    {
        return 'stackless_edd_email_' . $this->id();
    }

    /** @return array<string,mixed> */
    public function settings(): array
    {
        $stored = get_option($this->optionName(), []);
        return (is_array($stored) ? $stored : []) + [
            'enabled' => $this->enabledByDefault() ? 'yes' : 'no',
            'subject' => '',
            'heading' => '',
            'recipient' => '',
        ];
    }

    public function isEnabled(): bool
    {
        return $this->settings()['enabled'] === 'yes';
    }

    /** Admin emails: the configured recipients, falling back to EDD's admin notice emails. */
    public function adminRecipients(): string
    {
        $recipient = trim((string) $this->settings()['recipient']);
        if ($recipient !== '') {
            return $recipient;
        }
        $notice = function_exists('edd_get_admin_notice_emails') ? (array) edd_get_admin_notice_emails() : [];
        return $notice ? implode(',', array_map('trim', $notice)) : (string) get_option('admin_email');
    }

    /** @param array<string,string> $placeholders */
    public function subject(array $placeholders = []): string
    {
        $subject = trim((string) $this->settings()['subject']);
        return $this->fill($subject !== '' ? $subject : $this->defaultSubject(), $placeholders);
    }

    /** @param array<string,string> $placeholders */
    public function heading(array $placeholders = []): string
    {
        $heading = trim((string) $this->settings()['heading']);
        return $this->fill($heading !== '' ? $heading : $this->defaultHeading(), $placeholders);
    }

    public function defaults(): array
    {
        return ['subject' => $this->defaultSubject(), 'heading' => $this->defaultHeading()];
    }

    /** The body HTML (no EDD wrapper), for sending and previews. */
    public function body(array $args = [], array $placeholders = []): string
    {
        return stackless_edd_get_template_html($this->template(), $args + [
            'email' => $this,
            'heading' => $this->heading($placeholders),
        ]);
    }

    /**
     * Send the email. $to is an address or comma list; for admin emails pass '' to use adminRecipients().
     *
     * @param array<string,mixed>  $args         template variables
     * @param array<string,string> $placeholders {token} => value for subject and heading
     * @param string[]             $attachments  file paths
     */
    public function send(string $to, array $args = [], array $placeholders = [], array $attachments = []): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }
        $to = $to !== '' ? $to : ($this->isCustomerEmail() ? '' : $this->adminRecipients());
        if ($to === '') {
            return false;
        }
        if ($this->isCustomerEmail() && !LiveStore::allowsCustomerEmail($this->id())) {
            return false;
        }
        $heading = $this->heading($placeholders);
        $body = $this->body($args, $placeholders);
        if (!self::$mailer && class_exists(\EDD\Emails\Base::class)) {
            self::$mailer = new \EDD\Emails\Base();
        }
        $mailer = self::$mailer;
        if (!$mailer) {
            return (bool) wp_mail($to, $this->subject($placeholders), $body, ['Content-Type: text/html; charset=UTF-8'], $attachments);
        }
        $mailer->__set('heading', $heading);
        $sent = (bool) $mailer->send($to, $this->subject($placeholders), $body, $attachments);
        if (!$sent) {
            Logger::log('emails', sprintf('Email "%s" to %s was not sent.', $this->id(), $to), 'warning');
        }
        return $sent;
    }

    /** @param array<string,string> $placeholders */
    private function fill(string $text, array $placeholders): string
    {
        $placeholders += ['{site_title}' => wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES)];
        return strtr($text, $placeholders);
    }
}
