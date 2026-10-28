<?php

namespace StacklessEDD\Modules\SoldCount;

defined('ABSPATH') || exit;

/**
 * Prints a badge at a chosen spot on download pages and download grids.
 *
 * Download page: above the description (edd_before_download_content), or just before or after the
 * purchase form of the download being viewed (edd_purchase_download_form, which also covers the Buy
 * Button block and themes that call edd_get_purchase_link()).
 * Grids: the [downloads] shortcode hooks (edd_download_after_title, edd_download_after_price) and the
 * EDD downloads block (edd_blocks_downloads_after_entry_title; its price sits right above the purchase
 * form, so "after the price" there means the top of the form). "Before the button" works for any
 * purchase button that is not the viewed download's own.
 */
final class Placement
{
    public const SINGLE_POSITIONS = ['before_content', 'before_button', 'after_button'];
    public const ARCHIVE_POSITIONS = ['after_title', 'after_price', 'before_button'];

    /** Runs after External Downloads (100) and Hide Price Until Login (110) replace the form, before Wishlist (120). */
    private const FORM_PRIORITY = 115;

    /** @var callable(int, string): string */
    private $renderer;

    /** @var string */
    private $single;

    /** @var string */
    private $archive;

    /** @var array<int, bool> */
    private $singleDone = [];

    /** @var int Nesting depth of edd/downloads blocks being rendered. */
    private $blockDepth = 0;

    /**
     * @param callable(int, string): string $renderer Returns escaped HTML for a download in "single" or "archive" context.
     * @param string $single  Download page position, or '' to skip download pages.
     * @param string $archive Grid position, or '' to skip grids.
     */
    public function __construct(callable $renderer, string $single, string $archive)
    {
        $this->renderer = $renderer;
        $this->single = in_array($single, self::SINGLE_POSITIONS, true) ? $single : '';
        $this->archive = in_array($archive, self::ARCHIVE_POSITIONS, true) ? $archive : '';
    }

    public function register(): void
    {
        if ($this->single === 'before_content') {
            add_action('edd_before_download_content', [$this, 'beforeContent'], 20, 1);
        }
        if (in_array($this->single, ['before_button', 'after_button'], true) || in_array($this->archive, ['after_price', 'before_button'], true)) {
            add_filter('edd_purchase_download_form', [$this, 'purchaseForm'], self::FORM_PRIORITY, 2);
        }
        if ($this->archive === 'after_title') {
            add_action('edd_download_after_title', [$this, 'shortcodeItem'], 20, 0);
            add_action('edd_blocks_downloads_after_entry_title', [$this, 'blockItem'], 20, 1);
        } elseif ($this->archive === 'after_price') {
            add_action('edd_download_after_price', [$this, 'shortcodeItem'], 20, 0);
            add_filter('render_block_data', [$this, 'enterDownloadsBlock'], 10, 1);
            add_filter('render_block_edd/downloads', [$this, 'leaveDownloadsBlock'], 10, 1);
        }
    }

    /** @param mixed $downloadId */
    public function beforeContent($downloadId = 0): void
    {
        $downloadId = is_numeric($downloadId) ? (int) $downloadId : 0;
        if ($downloadId > 0 && $this->isMainDownload($downloadId)) {
            echo $this->renderSingle($downloadId); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the renderer.
        }
    }

    /**
     * @param mixed $html
     * @param mixed $args
     * @return mixed
     */
    public function purchaseForm($html, $args = [])
    {
        if (!is_string($html) || $html === '' || !is_array($args)) {
            return $html;
        }
        $downloadId = isset($args['download_id']) && is_numeric($args['download_id']) ? (int) $args['download_id'] : 0;
        if ($downloadId < 1) {
            return $html;
        }
        if ($this->isMainDownload($downloadId) && $this->blockDepth === 0) {
            if ($this->single === 'before_button') {
                return $this->renderSingle($downloadId) . $html;
            }
            if ($this->single === 'after_button') {
                return $html . $this->renderSingle($downloadId);
            }
            return $html;
        }
        $grid = $this->archive === 'before_button' || ($this->archive === 'after_price' && $this->blockDepth > 0);
        if (!$grid) {
            return $html;
        }
        return (string) call_user_func($this->renderer, $downloadId, 'archive') . $html;
    }

    /** [downloads] shortcode item hooks (no arguments; the loop's post is the download). */
    public function shortcodeItem(): void
    {
        $downloadId = (int) get_the_ID();
        if ($downloadId > 0 && get_post_type($downloadId) === 'download') {
            echo call_user_func($this->renderer, $downloadId, 'archive'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the renderer.
        }
    }

    /** @param mixed $blockAttributes */
    public function blockItem($blockAttributes = []): void
    {
        $this->shortcodeItem();
    }

    /**
     * @param mixed $parsedBlock
     * @return mixed
     */
    public function enterDownloadsBlock($parsedBlock)
    {
        if (is_array($parsedBlock) && ($parsedBlock['blockName'] ?? '') === 'edd/downloads') {
            $this->blockDepth++;
        }
        return $parsedBlock;
    }

    /**
     * @param mixed $content
     * @return mixed
     */
    public function leaveDownloadsBlock($content)
    {
        if ($this->blockDepth > 0) {
            $this->blockDepth--;
        }
        return $content;
    }

    private function renderSingle(int $downloadId): string
    {
        if (!empty($this->singleDone[$downloadId])) {
            return '';
        }
        $html = (string) call_user_func($this->renderer, $downloadId, 'single');
        if ($html !== '') {
            $this->singleDone[$downloadId] = true;
        }
        return $html;
    }

    private function isMainDownload(int $downloadId): bool
    {
        return is_singular('download') && $downloadId === (int) get_queried_object_id();
    }
}
