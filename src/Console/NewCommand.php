<?php

namespace TailPress\Installer\Console;

use RuntimeException;
use Symfony\Component\Process\Process;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use ZipArchive;

class NewCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('new')
            ->setDescription('Create a new TailPress theme')
            ->addArgument('folder', InputArgument::REQUIRED)
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'The name of your theme')
            ->addOption('git', null, InputOption::VALUE_NONE, 'Initialize a Git repository')
            ->addOption('branch', null, InputOption::VALUE_REQUIRED, 'The branch that should be created for a new repository', $this->defaultBranch())
            ->addOption('dev', null, InputOption::VALUE_NONE, 'Use development version of your TailPress.')
            ->addOption('wordpress', null, InputOption::VALUE_NONE, 'Install WordPress')
            ->addOption('dbname', null, InputOption::VALUE_OPTIONAL, 'The name of your database')
            ->addOption('dbuser', null, InputOption::VALUE_OPTIONAL, 'The name of your database user')
            ->addOption('dbpass', null, InputOption::VALUE_OPTIONAL, 'The password of your database')
            ->addOption('dbhost', null, InputOption::VALUE_OPTIONAL, 'The host of your database')
            ->addOption('author-name', null, InputOption::VALUE_OPTIONAL, 'The name of the theme author')
            ->addOption('author-email', null, InputOption::VALUE_OPTIONAL, 'The email of the theme author')
            ->addOption('local-dev-url', null, InputOption::VALUE_OPTIONAL, 'The local development url of your site');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $commands = [];

        $output->write(PHP_EOL."<fg=blue>
  _____     _ _ ____
 |_   _|_ _(_) |  _ \ _ __ ___  ___ ___
   | |/ _` | | | |_) | '__/ _ \/ __/ __|
   | | (_| | | |  __/| | |  __/\__ \__ \
   |_|\__,_|_|_|_|   |_|  \___||___/___/'</>".PHP_EOL.PHP_EOL);

        $folder = $input->getArgument('folder');

        $name = $input->getOption('name') ?? (new SymfonyStyle($input, $output))->ask('What is the name of your theme?', $folder);
        $authorName = $input->getOption('author-name') ?? (new SymfonyStyle($input, $output))->ask('What is the theme author name?', 'Jeffrey van Rossum');
        $authorEmail = $input->getOption('author-email') ?? (new SymfonyStyle($input, $output))->ask('What is the theme author email?', 'jeffrey@vanrossum.dev');
        $localDevUrl = $input->getOption('local-dev-url') ?? (new SymfonyStyle($input, $output))->ask('What is the local development url of your site?', 'http://localhost:8000');

        $installWordPress = ($input->getOption('wordpress') || (new SymfonyStyle($input, $output))->confirm('Would you like to install WordPress as well?', false));

        if ($installWordPress) {
            $dbName = $input->getOption('dbname') ?? (new SymfonyStyle($input, $output))->ask('What is the name of your database?', str_replace('-', '_', $folder));
            $dbUser = $input->getOption('dbuser') ?? (new SymfonyStyle($input, $output))->ask('What is the user of your database?', 'root');
            $dbPass = $input->getOption('dbpass') ?? (new SymfonyStyle($input, $output))->ask('What is the password of your database?');
            $dbHost = $input->getOption('dbhost') ?? (new SymfonyStyle($input, $output))->ask('What is the host of your database?', 'localhost');
        }

        $slug = $this->determineSlug($folder);
        $prefix = $this->determineSlug($folder, true);

        $baseDirectory = $folder !== '.' ? getcwd().DIRECTORY_SEPARATOR.$folder : getcwd();
        $workingDirectory = $baseDirectory;

        if ($installWordPress) {
            try {
                $this->installWordPress($baseDirectory, $output);
            } catch (RuntimeException $e) {
                $output->writeln(PHP_EOL.'<error>'.$e->getMessage().'</error>');

                return Command::FAILURE;
            }

            $workingDirectory = $baseDirectory.DIRECTORY_SEPARATOR.'wp-content'.DIRECTORY_SEPARATOR.'themes'.DIRECTORY_SEPARATOR.$slug;
        }

        $this->ensureDirectory($workingDirectory);

        $version = '';
        if ($input->getOption('dev')) {
            $version = '5.x-dev';
        }

        $commands[] = 'composer create-project tailpress/tailpress '.$this->escapeArgument($workingDirectory).' '.$version.' --remove-vcs --prefer-dist --no-scripts';

        if (($process = $this->runCommands($commands, $input, $output))->isSuccessful()) {
            $this->replacePackageJsonInfo($workingDirectory.DIRECTORY_SEPARATOR.'package.json', 'name', $prefix);
            $this->replacePackageJsonInfo($workingDirectory.DIRECTORY_SEPARATOR.'package.json', 'text_domain', $prefix);
            $this->replacePackageJsonInfo($workingDirectory.DIRECTORY_SEPARATOR.'package.json', 'version', '0.1.0');
            $this->replacePackageJsonInfo($workingDirectory.DIRECTORY_SEPARATOR.'package.json', 'author', $authorName);

            $this->replaceInFile('Jeffrey van Rossum', $authorName, $workingDirectory.DIRECTORY_SEPARATOR.'composer.json');
            $this->replaceInFile('jeffrey@vanrossum.dev', $authorEmail, $workingDirectory.DIRECTORY_SEPARATOR.'composer.json');

            if (file_exists($workingDirectory.DIRECTORY_SEPARATOR.'vite.config.mjs')) {
                $this->replaceInFile('http://tailpress.test', $localDevUrl, $workingDirectory.DIRECTORY_SEPARATOR.'vite.config.mjs');
                // Theme directory keeps the folder slug (hyphens), not the text-domain prefix.
                $this->replaceInFile('wp-content/themes/tailpress', "wp-content/themes/{$slug}", $workingDirectory.DIRECTORY_SEPARATOR.'vite.config.mjs');
            }

            $this->replaceThemeHeader($workingDirectory.DIRECTORY_SEPARATOR.'style.css', 'Theme Name', $name);
            $this->replaceThemeHeader($workingDirectory.DIRECTORY_SEPARATOR.'style.css', 'Author', $authorName);
            $this->replaceThemeHeader($workingDirectory.DIRECTORY_SEPARATOR.'style.css', 'Text Domain', $prefix);
            $this->replaceThemeHeader($workingDirectory.DIRECTORY_SEPARATOR.'style.css', 'Description', 'A WordPress theme made with TailPress.');
            $this->replaceThemeHeader($workingDirectory.DIRECTORY_SEPARATOR.'style.css', 'Version', '0.1.0');

            if ($installWordPress) {
                $wpConfig = $baseDirectory.DIRECTORY_SEPARATOR.'wp-config.php';

                if (! is_file($wpConfig)) {
                    $output->writeln(PHP_EOL.'<error>WordPress was installed but wp-config.php is missing at '.$wpConfig.'.</error>');

                    return Command::FAILURE;
                }

                $this->replaceInFile('database_name_here', $dbName, $wpConfig);
                $this->replaceInFile('username_here', $dbUser, $wpConfig);
                $this->replaceInFile('password_here', $dbPass ?? '', $wpConfig);
                $this->replaceInFile('localhost', $dbHost, $wpConfig);
                $this->replaceInFile(
                    "define( 'WP_DEBUG', false );",
                    "define( 'WP_DEBUG', false );\ndefine( 'WP_ENVIRONMENT_TYPE', 'development' );",
                    $wpConfig
                );
            }

            $finalCommands = [];

            if (is_dir($workingDirectory.DIRECTORY_SEPARATOR.'.git')) {
                if (PHP_OS_FAMILY === 'Windows') {
                    $finalCommands[] = 'rmdir /S /Q '.$this->escapeArgument($workingDirectory.DIRECTORY_SEPARATOR.'.git');
                } else {
                    $finalCommands[] = 'rm -rf '.$this->escapeArgument($workingDirectory.DIRECTORY_SEPARATOR.'.git');
                }
            }

            if (file_exists($workingDirectory.DIRECTORY_SEPARATOR.'composer.json')) {
                $finalCommands[] = 'composer install --working-dir='.$this->escapeArgument($workingDirectory);
            }

            $finalCommands[] = 'npm install --q --no-progress --prefix '.$this->escapeArgument($workingDirectory);
            $finalCommands[] = 'npm run build --prefix '.$this->escapeArgument($workingDirectory);

            $this->runCommands($finalCommands, $input, $output);

            if ($input->getOption('git')) {
                $this->createRepository($workingDirectory, $input, $output);
            }

            $output->writeln(PHP_EOL.'<comment>🌊 Your boilerplate is ready, go create something beautiful!</comment>');
            $output->writeln(PHP_EOL.'<info>🏗️ Your theme path: '.$workingDirectory.'</info>');
            if ($installWordPress) {
                $output->writeln(PHP_EOL.'<info>📝 Your WordPress path: '.$baseDirectory.'</info>');
            }
            $output->writeln(PHP_EOL.'<comment>✨ If you like TailPress, please consider starring the repo at https://github.com/tailpress/tailpress</comment>');
        }

        return $process->getExitCode() ?? Command::FAILURE;
    }

    protected function runCommands($commands, InputInterface $input, OutputInterface $output, array $env = [])
    {
        if ($commands === []) {
            return new Process([]);
        }

        $process = Process::fromShellCommandline(implode(' && ', $commands), null, $env, null, null);

        if ('\\' !== DIRECTORY_SEPARATOR && file_exists('/dev/tty') && is_readable('/dev/tty')) {
            try {
                $process->setTty(true);
            } catch (RuntimeException $e) {
                $output->writeln('Warning: '.$e->getMessage());
            }
        }

        $process->run(function ($type, $line) use ($output) {
            $output->write('    '.$line);
        });

        return $process;
    }

    protected function replaceInFile(string $search, string $replace, string $file)
    {
        if (! is_file($file)) {
            throw new RuntimeException("Unable to update missing file [{$file}].");
        }

        file_put_contents(
            $file,
            str_replace($search, $replace, file_get_contents($file))
        );
    }

    protected function replaceThemeHeader(string $stylesheet, string $header, string $value)
    {
        $content = file_get_contents($stylesheet);

        $content = preg_replace('/'.$header.': (.*)/', $header.': '.$value, $content);

        file_put_contents($stylesheet, $content);
    }

    protected function deleteFiles(string $workingDirectory, array $files)
    {
        foreach ($files as $file) {
            unlink($workingDirectory.'/'.$file);
        }
    }

    protected function replacePackageJsonInfo(string $packageJson, string $key, string $value)
    {
        $content = file_get_contents($packageJson);

        $content = preg_replace('/"'.$key.'": (.*)/', '"'.$key.'": "'.$value.'",', $content);

        file_put_contents($packageJson, $content);
    }

    /**
     * Download and extract WordPress using PHP so paths with spaces and
     * Windows shells work without relying on curl/tar/cp.
     */
    protected function installWordPress(string $directory, OutputInterface $output): void
    {
        $output->writeln('    <comment>Downloading WordPress…</comment>');

        $this->ensureDirectory($directory);

        $zipPath = $directory.DIRECTORY_SEPARATOR.'wordpress-latest.zip';
        $this->downloadFile('https://wordpress.org/latest.zip', $zipPath);

        $output->writeln('    <comment>Extracting WordPress…</comment>');

        if (! class_exists(ZipArchive::class)) {
            @unlink($zipPath);
            throw new RuntimeException('The PHP ZipArchive extension is required to install WordPress.');
        }

        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            @unlink($zipPath);
            throw new RuntimeException('Unable to open the WordPress archive.');
        }

        $extractPath = $directory.DIRECTORY_SEPARATOR.'.wordpress-extract';
        $this->ensureDirectory($extractPath);

        if (! $zip->extractTo($extractPath)) {
            $zip->close();
            @unlink($zipPath);
            $this->deleteDirectory($extractPath);
            throw new RuntimeException('Unable to extract the WordPress archive.');
        }

        $zip->close();
        @unlink($zipPath);

        $source = $extractPath.DIRECTORY_SEPARATOR.'wordpress';

        if (! is_dir($source)) {
            $this->deleteDirectory($extractPath);
            throw new RuntimeException('Unexpected WordPress archive layout.');
        }

        $this->moveDirectoryContents($source, $directory);
        $this->deleteDirectory($extractPath);

        $sample = $directory.DIRECTORY_SEPARATOR.'wp-config-sample.php';
        $config = $directory.DIRECTORY_SEPARATOR.'wp-config.php';

        if (! is_file($sample)) {
            throw new RuntimeException('wp-config-sample.php was not found after extracting WordPress.');
        }

        if (! copy($sample, $config)) {
            throw new RuntimeException('Unable to create wp-config.php from the sample file.');
        }

        $output->writeln('    <info>WordPress installed.</info>');
    }

    protected function downloadFile(string $url, string $destination): void
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 120,
                'header' => "User-Agent: TailPress Installer\r\n",
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $data = @file_get_contents($url, false, $context);

        if ($data === false) {
            throw new RuntimeException("Unable to download WordPress from [{$url}].");
        }

        if (file_put_contents($destination, $data) === false) {
            throw new RuntimeException("Unable to write WordPress archive to [{$destination}].");
        }
    }

    protected function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create directory [{$directory}].");
        }
    }

    protected function moveDirectoryContents(string $source, string $destination): void
    {
        $this->ensureDirectory($destination);

        $items = scandir($source);

        if ($items === false) {
            throw new RuntimeException("Unable to read directory [{$source}].");
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $from = $source.DIRECTORY_SEPARATOR.$item;
            $to = $destination.DIRECTORY_SEPARATOR.$item;

            if (is_dir($from)) {
                $this->moveDirectoryContents($from, $to);
                @rmdir($from);
                continue;
            }

            if (! rename($from, $to) && ! (copy($from, $to) && unlink($from))) {
                throw new RuntimeException("Unable to move [{$from}] to [{$to}].");
            }
        }
    }

    protected function deleteDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $items = scandir($directory);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $directory.DIRECTORY_SEPARATOR.$item;

            if (is_dir($path)) {
                $this->deleteDirectory($path);
                continue;
            }

            @unlink($path);
        }

        @rmdir($directory);
    }

    protected function escapeArgument(string $argument): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return '"'.str_replace('"', '""', $argument).'"';
        }

        return escapeshellarg($argument);
    }

    protected function createRepository(string $directory, InputInterface $input, OutputInterface $output)
    {
        chdir($directory);

        $branch = $input->getOption('branch') ?: $this->defaultBranch();

        $commands = [
            'git init -q',
            'git add .',
            'git commit -q -m "Initial commit"',
            "git branch -M {$branch}",
        ];

        $this->runCommands($commands, $input, $output);
    }

    protected function defaultBranch()
    {
        $process = new Process(['git', 'config', '--global', 'init.defaultBranch']);

        $process->run();

        $output = trim($process->getOutput());

        return $process->isSuccessful() && $output ? $output : 'main';
    }

    protected function determineSlug($folder, $sanitize = false)
    {
        $folder = str_replace('\\', '/', $folder);
        $folder = explode('/', $folder);
        $name = end($folder);

        if (! $sanitize) {
            return $name;
        }

        return str_replace('-', '_', $name);
    }
}
