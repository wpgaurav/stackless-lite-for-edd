<?php

namespace StacklessEDD\Admin;

defined('ABSPATH') || exit;

/**
 * Renders and saves module settings arrays (the WooCommerce settings-API shape every Stackless
 * module already uses), so the Settings screen looks and behaves the same as Stackless for WooCommerce.
 *
 * Supported types: title, sectionend, text, email, url, password, number, color, textarea, checkbox
 * (with checkboxgroup start/end), select, multiselect, radio, single_select_page, hidden, info.
 * Field keys: id, title, desc, desc_tip, type, default, options, placeholder, css, class, custom_attributes.
 *
 * Custom types: add_action('stackless_edd_admin_field_{type}', fn(array $field) => …) prints the row.
 * Custom sanitizing: add_filter('stackless_edd_settings_sanitize_option_{id}', fn($value, $field, $raw) => …, 10, 3).
 * Checkboxes store "yes"/"no", like WooCommerce.
 */
final class Fields
{
    private const TRANSIENT = 'stackless_edd_settings_messages_';

    /** @var string[] */
    private static $errors = [];

    /** Show an error after the current save (gateways use this for invalid credentials). */
    public static function addError(string $message): void
    {
        self::$errors[] = $message;
    }

    /** Keep errors from this save for the redirected page load. */
    public static function persistErrors(): void
    {
        if (self::$errors) {
            set_transient(self::TRANSIENT . get_current_user_id(), self::$errors, 60);
        }
    }

    public static function printErrors(): void
    {
        $key = self::TRANSIENT . get_current_user_id();
        $errors = get_transient($key);
        if (!is_array($errors) || !$errors) {
            return;
        }
        delete_transient($key);
        foreach ($errors as $error) {
            echo '<div class="notice notice-error stackless-notice"><p>' . esc_html((string) $error) . '</p></div>';
        }
    }

    /** @param array<int,array<string,mixed>> $fields */
    public static function output(array $fields): void
    {
        $open = false;
        foreach ($fields as $field) {
            if (!isset($field['type'])) {
                continue;
            }
            $field = self::normalize($field);
            $type = (string) $field['type'];
            if ($type === 'title') {
                if ($open) {
                    echo '</table>';
                }
                if ($field['title'] !== '') {
                    echo '<h2>' . esc_html($field['title']) . '</h2>';
                }
                if ($field['desc'] !== '') {
                    echo '<div class="stackless-section-desc">' . wpautop(wp_kses_post($field['desc'])) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
                }
                echo '<table class="form-table" role="presentation">';
                $open = true;
                continue;
            }
            if ($type === 'sectionend') {
                if ($open) {
                    echo '</table>';
                    $open = false;
                }
                continue;
            }
            if (!$open) {
                echo '<table class="form-table" role="presentation">';
                $open = true;
            }
            if (has_action('stackless_edd_admin_field_' . $type)) {
                do_action('stackless_edd_admin_field_' . $type, $field);
                continue;
            }
            self::row($field);
        }
        if ($open) {
            echo '</table>';
        }
    }

    /**
     * Save every field from $_POST. Call after the nonce and capability checks.
     *
     * @param array<int,array<string,mixed>> $fields
     */
    public static function save(array $fields): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- the caller verified the nonce.
        foreach ($fields as $field) {
            if (empty($field['id']) || !isset($field['type']) || in_array($field['type'], ['title', 'sectionend', 'info'], true)) {
                continue;
            }
            $field = self::normalize($field);
            $id = (string) $field['id'];
            $raw = self::raw($id);
            $type = (string) $field['type'];
            switch ($type) {
                case 'checkbox':
                    $value = ($raw === '1' || $raw === 'yes') ? 'yes' : 'no';
                    break;
                case 'textarea':
                    $value = wp_kses_post(trim((string) $raw));
                    break;
                case 'multiselect':
                    $value = array_values(array_filter(array_map([self::class, 'clean'], (array) $raw), static function (string $v): bool {
                        return $v !== '';
                    }));
                    break;
                case 'select':
                case 'radio':
                    $allowed = array_map('strval', array_keys((array) $field['options']));
                    $default = (string) ($field['default'] !== '' ? $field['default'] : ($allowed[0] ?? ''));
                    $value = in_array((string) $raw, $allowed, true) ? (string) $raw : $default;
                    break;
                case 'password':
                    $value = is_string($raw) ? trim($raw) : '';
                    break;
                case 'number':
                    $value = is_scalar($raw) && $raw !== '' && is_numeric($raw) ? (string) $raw : '';
                    break;
                case 'email':
                    $value = sanitize_email((string) $raw);
                    break;
                case 'url':
                    $value = esc_url_raw(trim((string) $raw));
                    break;
                case 'color':
                    $value = sanitize_hex_color((string) $raw) ?: '';
                    break;
                case 'single_select_page':
                    $value = absint($raw);
                    break;
                default:
                    $value = is_array($raw) ? map_deep($raw, [self::class, 'clean']) : self::clean($raw);
            }
            /**
             * Sanitize one Stackless setting before it is stored. Return null to skip saving it.
             *
             * @param mixed $value Sanitized value.
             * @param array $field The field definition.
             * @param mixed $raw   Unslashed raw input.
             */
            $value = apply_filters('stackless_edd_settings_sanitize_option', $value, $field, $raw);
            $value = apply_filters('stackless_edd_settings_sanitize_option_' . $id, $value, $field, $raw);
            if ($value === null) {
                continue;
            }
            self::store($id, $value);
        }
        // phpcs:enable
    }

    /** @param mixed $value */
    public static function clean($value): string
    {
        return is_scalar($value) ? sanitize_text_field((string) $value) : '';
    }

    /** Current stored value, honouring "name[key]" ids like WooCommerce. */
    public static function value(string $id, $default = '')
    {
        if (preg_match('/^([^\[]+)\[([^\]]+)\]$/', $id, $m)) {
            $stored = get_option($m[1], []);
            return is_array($stored) && array_key_exists($m[2], $stored) ? $stored[$m[2]] : $default;
        }
        $value = get_option($id, null);
        return $value === null || $value === false ? $default : $value;
    }

    /* ------------------------------------------------------------------ */

    /** @param mixed $value */
    private static function store(string $id, $value): void
    {
        if (preg_match('/^([^\[]+)\[([^\]]+)\]$/', $id, $m)) {
            $stored = get_option($m[1], []);
            $stored = is_array($stored) ? $stored : [];
            $stored[$m[2]] = $value;
            update_option($m[1], $stored);
            return;
        }
        update_option($id, $value);
    }

    /** @return mixed unslashed raw input for a field id (null when absent) */
    private static function raw(string $id)
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- sanitized per type by save().
        if (preg_match('/^([^\[]+)\[([^\]]+)\]$/', $id, $m)) {
            return isset($_POST[$m[1]][$m[2]]) ? wp_unslash($_POST[$m[1]][$m[2]]) : null;
        }
        return isset($_POST[$id]) ? wp_unslash($_POST[$id]) : null;
        // phpcs:enable
    }

    /**
     * @param array<string,mixed> $field
     * @return array<string,mixed>
     */
    private static function normalize(array $field): array
    {
        $field += [
            'id' => '',
            'title' => $field['name'] ?? '',
            'desc' => '',
            'desc_tip' => false,
            'default' => '',
            'options' => [],
            'placeholder' => '',
            'css' => '',
            'class' => '',
            'custom_attributes' => [],
            'checkboxgroup' => '',
        ];
        $field['title'] = (string) $field['title'];
        $field['desc'] = (string) $field['desc'];
        return $field;
    }

    /** @param array<string,mixed> $field */
    private static function attributes(array $field): string
    {
        $out = '';
        foreach ((array) $field['custom_attributes'] as $name => $value) {
            $out .= ' ' . esc_attr((string) $name) . '="' . esc_attr((string) $value) . '"';
        }
        if ($field['css'] !== '') {
            $out .= ' style="' . esc_attr((string) $field['css']) . '"';
        }
        if ($field['placeholder'] !== '') {
            $out .= ' placeholder="' . esc_attr((string) $field['placeholder']) . '"';
        }
        return $out;
    }

    /**
     * Description markup. desc_tip true moves desc into the tip; a string desc_tip is a tip shown above desc.
     *
     * @param array<string,mixed> $field
     * @return array{0:string,1:string} [tip html for the label, description html for the control]
     */
    private static function describe(array $field): array
    {
        $desc = $field['desc'];
        $tip = '';
        if ($field['desc_tip'] === true) {
            $tip = $desc;
            $desc = '';
        } elseif (is_string($field['desc_tip']) && $field['desc_tip'] !== '') {
            $tip = $field['desc_tip'];
        }
        $tipHtml = $tip !== '' ? '<span class="stackless-help" aria-hidden="true" title="' . esc_attr(wp_strip_all_tags($tip)) . '">?</span>' : '';
        $descHtml = '';
        if ($tip !== '') {
            $descHtml .= '<p class="description stackless-tip-text">' . wp_kses_post($tip) . '</p>';
        }
        if ($desc !== '') {
            $descHtml .= in_array($field['type'], ['checkbox', 'radio'], true) ? '' : '<p class="description">' . wp_kses_post($desc) . '</p>';
        }
        return [$tipHtml, $descHtml];
    }

    /** @param array<string,mixed> $field */
    private static function row(array $field): void
    {
        $id = (string) $field['id'];
        $type = (string) $field['type'];
        $value = $id !== '' ? self::value($id, $field['default']) : $field['default'];
        [$tip, $desc] = self::describe($field);
        $attrs = self::attributes($field);
        $class = esc_attr((string) $field['class']);

        if ($type === 'hidden') {
            printf('<input type="hidden" name="%1$s" id="%1$s" value="%2$s">', esc_attr($id), esc_attr((string) $value));
            return;
        }

        if ($type === 'checkbox') {
            $group = (string) $field['checkboxgroup'];
            if ($group === '' || $group === 'start') {
                echo '<tr><th scope="row" class="titledesc">' . esc_html($field['title']) . $tip . '</th><td class="forminp forminp-checkbox"><fieldset>'; // phpcs:ignore WordPress.Security.EscapeOutput
            }
            if ($field['title'] !== '') {
                echo '<legend class="screen-reader-text"><span>' . esc_html($field['title']) . '</span></legend>';
            }
            printf(
                '<label for="%1$s"><input type="checkbox" name="%1$s" id="%1$s" value="1" class="%2$s"%3$s%4$s> %5$s</label>%6$s',
                esc_attr($id),
                $class,
                checked($value, 'yes', false),
                $attrs, // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in attributes().
                wp_kses_post($field['desc']),
                $group === '' || $group === 'end' ? '' : '<br>'
            );
            if ($group === '' || $group === 'end') {
                echo $desc . '</fieldset></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
            }
            return;
        }

        echo '<tr><th scope="row" class="titledesc"><label for="' . esc_attr($id) . '">' . esc_html($field['title']) . '</label>' . $tip . '</th>'; // phpcs:ignore WordPress.Security.EscapeOutput
        echo '<td class="forminp forminp-' . esc_attr($type) . '">';
        switch ($type) {
            case 'textarea':
                printf('<textarea name="%1$s" id="%1$s" class="large-text %2$s" rows="4"%3$s>%4$s</textarea>', esc_attr($id), $class, $attrs, esc_textarea((string) $value)); // phpcs:ignore WordPress.Security.EscapeOutput
                break;
            case 'select':
            case 'multiselect':
                $multi = $type === 'multiselect';
                $selected = array_map('strval', (array) $value);
                printf('<select name="%1$s%2$s" id="%1$s" class="%3$s"%4$s%5$s>', esc_attr($id), $multi ? '[]' : '', $class, $multi ? ' multiple size="6"' : '', $attrs); // phpcs:ignore WordPress.Security.EscapeOutput
                foreach ((array) $field['options'] as $key => $label) {
                    if (is_array($label)) {
                        echo '<optgroup label="' . esc_attr((string) $key) . '">';
                        foreach ($label as $k => $l) {
                            printf('<option value="%s"%s>%s</option>', esc_attr((string) $k), in_array((string) $k, $selected, true) ? ' selected' : '', esc_html((string) $l));
                        }
                        echo '</optgroup>';
                        continue;
                    }
                    printf('<option value="%s"%s>%s</option>', esc_attr((string) $key), in_array((string) $key, $selected, true) ? ' selected' : '', esc_html((string) $label));
                }
                echo '</select>';
                break;
            case 'radio':
                echo '<fieldset><ul class="stackless-radio">';
                foreach ((array) $field['options'] as $key => $label) {
                    printf(
                        '<li><label><input type="radio" name="%1$s" value="%2$s"%3$s%4$s> %5$s</label></li>',
                        esc_attr($id),
                        esc_attr((string) $key),
                        checked((string) $value, (string) $key, false),
                        $attrs, // phpcs:ignore WordPress.Security.EscapeOutput
                        esc_html((string) $label)
                    );
                }
                echo '</ul>' . ($field['desc'] !== '' ? '<p class="description">' . wp_kses_post($field['desc']) . '</p>' : '') . '</fieldset>';
                break;
            case 'single_select_page':
                $dropdown = wp_dropdown_pages([
                    'name' => $id,
                    'id' => $id,
                    'echo' => false,
                    'selected' => absint($value),
                    'show_option_none' => ' ',
                    'option_none_value' => '0',
                    'class' => (string) $field['class'],
                ]);
                echo $dropdown; // phpcs:ignore WordPress.Security.EscapeOutput -- core markup.
                break;
            case 'info':
                echo wp_kses_post((string) ($field['text'] ?? ''));
                break;
            default:
                $input = in_array($type, ['text', 'email', 'url', 'password', 'number', 'color', 'date', 'tel'], true) ? $type : 'text';
                printf(
                    '<input type="%1$s" name="%2$s" id="%2$s" value="%3$s" class="%4$s"%5$s%6$s>',
                    esc_attr($input),
                    esc_attr($id),
                    esc_attr(is_scalar($value) ? (string) $value : ''),
                    $input === 'number' ? 'small-text ' . $class : 'regular-text ' . $class,
                    $input === 'password' ? ' autocomplete="new-password"' : '',
                    $attrs // phpcs:ignore WordPress.Security.EscapeOutput
                );
        }
        echo $desc . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
    }
}
