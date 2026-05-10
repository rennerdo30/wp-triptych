<?php

declare(strict_types=1);

namespace Triptych;

use Triptych\Admin\SettingsPage;
use Triptych\Editor\AssetsEnqueue;
use Triptych\Editor\Metabox;
use Triptych\Frontend\ContentFilter;
use Triptych\Frontend\HreflangEmitter;
use Triptych\Frontend\PermalinkFilter;
use Triptych\Frontend\TitleFilter;
use Triptych\Translation\Translator;

/**
 * Plugin bootstrap. Wires every subsystem onto WordPress hooks.
 */
final class Plugin
{
    private static ?self $instance = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct() {}

    public function boot(): void
    {
        // Hook registrations that don't immediately call __() — safe on
        // plugins_loaded.  Each register() method internally calls
        // add_action / add_filter; the actual __() calls happen later
        // when those hooks fire (admin_menu, the_title, etc).
        Router::register();
        TitleFilter::register();
        ContentFilter::register();
        PermalinkFilter::register();
        HreflangEmitter::register();
        Metabox::register();
        AssetsEnqueue::register();
        Translator::registerRest();
        SettingsPage::register();

        // Anything that calls __()/load_plugin_textdomain MUST defer
        // until init — calling translation functions before then trips
        // WP 6.7's `_load_textdomain_just_in_time` warning, which leaks
        // into response bodies and breaks header() emission downstream.
        add_action('init', [self::class, 'bootTranslated'], 1);
    }

    /**
     * init-priority-1 phase: load textdomain and register the default
     * multilingual fields with their translatable labels.
     */
    public static function bootTranslated(): void
    {
        load_plugin_textdomain(
            'triptych',
            false,
            dirname(plugin_basename(TRIPTYCH_FILE)) . '/languages'
        );

        Fields::register('post_title',   ['type' => 'text',     'label' => __('Title',   'triptych')]);
        Fields::register('post_content', ['type' => 'textarea', 'label' => __('Content', 'triptych')]);
    }
}
