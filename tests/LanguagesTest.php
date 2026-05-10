<?php

declare(strict_types=1);

namespace Triptych\Tests;

use PHPUnit\Framework\TestCase;
use Triptych\Languages;

final class LanguagesTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['triptych_test_options'] = [];
        Languages::flushCache();
    }

    public function testParsesDefaultConfiguration(): void
    {
        update_option('triptych_languages', 'zh:中文,ja:日本語,en:English');
        update_option('triptych_default_language', 'en');
        Languages::flushCache();

        $all = Languages::all();
        self::assertSame(['zh', 'ja', 'en'], array_keys($all));
        self::assertSame('English', $all['en']);
        self::assertSame('en', Languages::default());
    }

    public function testFallsBackToFirstSlugWhenDefaultMissing(): void
    {
        update_option('triptych_languages', 'fr:Français,de:Deutsch');
        update_option('triptych_default_language', 'en'); // not in list
        Languages::flushCache();

        self::assertSame('fr', Languages::default());
    }

    public function testIsValid(): void
    {
        update_option('triptych_languages', 'zh:中文,en:English');
        Languages::flushCache();

        self::assertTrue(Languages::isValid('zh'));
        self::assertFalse(Languages::isValid('ru'));
    }

    public function testHandlesBareSlugWithoutLabel(): void
    {
        update_option('triptych_languages', 'es,pt:Português');
        Languages::flushCache();

        $all = Languages::all();
        self::assertSame('es', $all['es']);
        self::assertSame('Português', $all['pt']);
    }
}
