<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Snappy\Config\config_manager;
use Snappy\Snapshot\remote_registry;
use Snappy\Snapshot\snapshot_manager;
use Snappy\Snapshot\remote_snapshot_cache;
use Snappy\Snapshot\index_manager;
use Snappy\Snapshot\remote_index_manager;
use Snappy\Cli\context;
use Snappy\Cli\command_router;

abstract class InProcessCliTestCase extends TestCase {
    protected string $tmpRoot;
    private ?context $sharedCtx = null; // persistent context per test

    protected function setUp(): void {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/snappy_inproc_' . bin2hex(random_bytes(5));
        @mkdir($this->tmpRoot, 0777, true);
    }

    protected function tearDown(): void {
        $this->removeDir($this->tmpRoot);
        parent::tearDown();
    }

    protected function buildContext(bool $forceNew = false): context {
        if (!$forceNew && $this->sharedCtx) { return $this->sharedCtx; }
        $cfgFile = $this->tmpRoot . '/config.json';
        $schemaFile = $this->tmpRoot . '/config.schema.json';
        file_put_contents($schemaFile, json_encode(['fields'=>[]]));
        $cfg = new config_manager($cfgFile, $this->tmpRoot);
        $snapshotBase = $this->tmpRoot . '/root';
        @mkdir($snapshotBase, 0777, true);
        $registry = new remote_registry($cfg, $snapshotBase);
        $manager = new snapshot_manager($registry);
        $cache = new remote_snapshot_cache($snapshotBase); $manager->set_cache($cache);
        $index = new index_manager($snapshotBase); $manager->set_index($index);
        $remoteIdx = new remote_index_manager($registry); $manager->set_remote_index($remoteIdx);
        $this->sharedCtx = new context($cfg, $registry, $manager, $cache, $index, $remoteIdx);
        return $this->sharedCtx;
    }

    protected function buildRouter(): command_router {
        // Fallback require for command classes if autoloader missed them (CI edge cases)
        $cmdDir = __DIR__ . '/../../src/cli/commands';
        $needed = ['snapshot_create','snapshot_list','snapshot_show','snapshot_tag','share_create','share_list','share_fetch','prune_run','verify_run','config_get','config_set','remote_list','remote_add','remote_remove'];
        foreach ($needed as $n) {
            $fq = 'Snappy\\Cli\\Commands\\' . $n;
            if (!class_exists($fq)) {
                $file = $cmdDir . '/' . $n . '.php';
                if (is_file($file)) { require_once $file; }
            }
        }
        $router = new command_router();
        $router->register('snapshot','create', new Snappy\Cli\Commands\snapshot_create());
        $router->register('snapshot','list',   new Snappy\Cli\Commands\snapshot_list());
        $router->register('snapshot','show',   new Snappy\Cli\Commands\snapshot_show());
        $router->register('snapshot','tag',    new Snappy\Cli\Commands\snapshot_tag());
        $router->register('share','create', new Snappy\Cli\Commands\share_create());
        $router->register('share','list',   new Snappy\Cli\Commands\share_list());
        $router->register('share','fetch',  new Snappy\Cli\Commands\share_fetch());
        $router->register('prune','run',    new Snappy\Cli\Commands\prune_run());
        $router->register('verify','run',   new Snappy\Cli\Commands\verify_run());
        $router->register('config','get',   new Snappy\Cli\Commands\config_get());
        $router->register('config','set',   new Snappy\Cli\Commands\config_set());
        // remote commands
        $router->register('remote','list',  new Snappy\Cli\Commands\remote_list());
        $router->register('remote','add',   new Snappy\Cli\Commands\remote_add());
        $router->register('remote','remove',new Snappy\Cli\Commands\remote_remove());
        return $router;
    }

    /** Run hierarchical command in-process; returns [output, exitCode] */
    protected function runInProcess(string $args, bool $newContext = false): array {
        $ctx = $this->buildContext($newContext);
        $prev = getenv('SNAPPY_FAKE_DUMP');
        putenv('SNAPPY_FAKE_DUMP=1');
        $router = $this->buildRouter();
        $argv = array_values(array_filter(explode(' ', trim($args)),'strlen'));
        ob_start();
        $extraOut = '';
        try {
            $code = $router->route($argv, $ctx);
        } catch (\Snappy\Support\Exception\SnappyException $e) {
            $code = \Snappy\Support\Exception\ExitCodes::codeFor($e);
            $extraOut .= 'ERROR(' . $code . '): ' . $e->getMessage() . "\n";
        } catch (\Throwable $e) {
            $code = \Snappy\Support\Exception\ExitCodes::UNKNOWN;
            $extraOut .= 'ERROR(' . $code . '): ' . $e->getMessage() . "\n";
        }
        $out = ob_get_clean() . $extraOut;
        // restore env
        if ($prev === false) { putenv('SNAPPY_FAKE_DUMP'); } else { putenv('SNAPPY_FAKE_DUMP='.$prev); }
        return [$out, $code, $ctx];
    }

    private function removeDir(string $dir): void {
        if (!is_dir($dir)) return; $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST); foreach($it as $f){ $f->isDir()?@rmdir($f->getPathname()):@unlink($f->getPathname()); } @rmdir($dir);
    }
}
