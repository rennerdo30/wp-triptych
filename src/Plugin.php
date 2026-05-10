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
        load_plugin_textdomain('triptych', false, dirname(plugin_basename(TRIPTYCH_FILE)) . '/languages');

        // Default field set — themes can register more.
        Fields::register('post_title', ['type' => 'text', 'label' => __('Title', 'triptych')]);
        Fields::register('post_content', ['type' => 'textarea', 'label' => __('Content', 'triptych')]);

        // Routing must run early so language is resolved before queries fire.
        Router::register();

        // Frontend filters.
        TitleFilter::register();
        ContentFilter::register();
        PermalinkFilter::register();
        HreflangEmitter::register();

        // Editor UI.
        Metabox::register();
        AssetsEnqueue::register();

        // REST + admin.
        Translator::registerRest();
        SettingsPage::register();
    }
}
