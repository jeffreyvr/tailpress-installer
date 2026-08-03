<?php

namespace TailPress\Installer\Console\Tests;

use TailPress\Installer\Console\NewCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class NewCommandTest extends TestCase
{
    protected string $testDirectory = 'tests-output';

    protected function setUp(): void
    {
        parent::setUp();

        $testDirectoryName = __DIR__ . '/../' . $this->testDirectory;

        if (!file_exists($testDirectoryName)) {
            exec("mkdir " . __DIR__ . '/../' . $this->testDirectory);
        }
    }

    public function test_it_can_scaffold_a_new_tailpress_theme()
    {
        $scaffoldDirectoryName =  $this->testDirectory . '/just-tailpress';
        $scaffoldDirectory = __DIR__ . '/../' . $scaffoldDirectoryName;

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

        $tester->execute(['folder' => $scaffoldDirectoryName, '--name' => 'Just TailPress', '--dev' => true]);

        $this->assertDirectoryExists($scaffoldDirectory);
        $this->assertFileExists($scaffoldDirectory . '/functions.php');
        // $this->assertStringContainsString('just_tailpress', file_get_contents($scaffoldDirectory . '/functions.php'));
    }

    public function test_it_can_scaffold_a_new_tailpress_theme_with_wordpress()
    {
        $scaffoldDirectoryName = $this->testDirectory . '/with-wordpress';
        $scaffoldDirectory = __DIR__ . '/../' . $scaffoldDirectoryName;

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

        $tester->execute([
            'folder' => $scaffoldDirectoryName,
            '--name' => 'Just TailPress',
            '--wordpress' => true,
            '--dev' => true,
            '--dbname' => 'test_db',
            '--dbuser' => 'root',
            '--dbpass' => '',
            '--dbhost' => '127.0.0.1',
        ]);

        $this->assertDirectoryExists($scaffoldDirectory);
        $this->assertFileExists($scaffoldDirectory . '/wp-config.php');
        $this->assertFileExists($scaffoldDirectory . '/wp-content/themes/with-wordpress/functions.php');

        $config = file_get_contents($scaffoldDirectory . '/wp-config.php');
        $this->assertStringContainsString("define( 'DB_NAME', 'test_db' );", $config);
        $this->assertStringContainsString("define( 'DB_HOST', '127.0.0.1' );", $config);
        $this->assertStringContainsString("define( 'WP_ENVIRONMENT_TYPE', 'development' );", $config);
    }
}
