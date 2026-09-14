<?php

declare(strict_types=1);

namespace Trilobit\Tests\Unit\Core\Media;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Bootstrap;

/**
 * The directory the variants are published in runs nothing.
 *
 * The library never writes a file there that is not a picture - names are
 * random and the extension is the detected type's - so this is the second
 * wall, for the day something else puts a file there: under Apache, a script
 * in www/media is refused rather than run. Other servers need the same said in
 * their own configuration; the README says so.
 */
#[CoversNothing]
final class PublicMediaDirectoryTest extends TestCase
{
    public function testAScriptInThePublicMediaDirectoryIsNeitherRunNorServed(): void
    {
        $file = Bootstrap::rootDirectory() . '/www/media/.htaccess';
        self::assertFileExists($file);

        $rules = (string) file_get_contents($file);

        self::assertStringContainsString('php_flag engine off', $rules, 'mod_php runs nothing here');
        self::assertMatchesRegularExpression(
            '~<FilesMatch "[^"]*php[^"]*">\s*Require all denied\s*</FilesMatch>~',
            $rules,
            'a script is not handed to a PHP handler either, whichever one the server has',
        );
        self::assertStringContainsString('Options -ExecCGI', $rules);
    }
}
