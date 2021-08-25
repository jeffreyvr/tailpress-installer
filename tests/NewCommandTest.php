<?php

namespace Jeffreyvr\TailPressInstaller\Console\Tests;

use Jeffreyvr\TailPressInstaller\Console\NewCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class NewCommandTest extends TestCase
{
    public function test_it_can_scaffold_a_new_tailpress_theme()
    {
        $scaffoldDirectoryName = 'tests-output/just-tailpress';
        $scaffoldDirectory = __DIR__.'/../'.$scaffoldDirectoryName;

        if (file_exists($scaffoldDirectory)) {
            if (PHP_OS_FAMILY == 'Windows') {
                exec("rd /s /q \"$scaffoldDirectory\"");
            } else {
                exec("rm -rf \"$scaffoldDirectory\"");
            }
        }

        $app = new Application('TailPress Installer');
        $app->add(new NewCommand);

        $tester = new CommandTester($app->find('new'));

        $tester->execute(['folder' => $scaffoldDirectoryName, '--name' => 'Just TailPress']);

        $this->assertDirectoryExists($scaffoldDirectory);
        $this->assertFileExists($scaffoldDirectory.'/functions.php');
        $this->assertStringContainsString('just_tailpress', file_get_contents($scaffoldDirectory.'/functions.php'));
    }

    public function test_it_can_scaffold_a_new_tailpress_theme_with_wordpress()
    {
        $scaffoldDirectoryName = 'tests-output/with-wordpress';
        $scaffoldDirectory = __DIR__.'/../'.$scaffoldDirectoryName;

        if (file_exists($scaffoldDirectory)) {
            if (PHP_OS_FAMILY == 'Windows') {
                exec("rd /s /q \"$scaffoldDirectory\"");
            } else {
                exec("rm -rf \"$scaffoldDirectory\"");
            }
        }

        $app = new Application('TailPress Installer');
        $app->add(new NewCommand);

        $tester = new CommandTester($app->find('new'));

        $tester->execute(['folder' => $scaffoldDirectoryName, '--name' => 'Just TailPress', '--wordpress' => true]);

        $this->assertDirectoryExists($scaffoldDirectory);
        $this->assertFileExists($scaffoldDirectory.'/wp-content/themes/with-wordpress/functions.php');
        $this->assertStringContainsString('with_wordpress',
            file_get_contents($scaffoldDirectory.'/wp-content/themes/with-wordpress/functions.php'));
    }
}
