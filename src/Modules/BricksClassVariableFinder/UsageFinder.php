<?php
declare(strict_types=1);

namespace AB\BricksTools\Modules\BricksClassVariableFinder;

use AB\BricksTools\System\WpCli;

final class UsageFinder
{
    public const ENGINE_WPCLI = 'wp-cli';
    public const ENGINE_PHP   = 'php';

    public string $lastEngine = '';

    /** @var array{stage:string, exitCode?:int, stderr?:string, cmd?:string}|null */
    public ?array $lastEngineError = null;

    /**
     * @param array{kind:string,id:string,name:string} $target
     * @return Usage[]
     */
    public function find(array $target): array
    {
        $raw = $this->dispatch($target);
        return array_map([$this, 'hydrate'], $raw);
    }

    /**
     * @param array{kind:string,id:string,name:string} $target
     * @return array<int, array<string, mixed>>
     */
    private function dispatch(array $target): array
    {
        if (WpCli::status()['available']) {
            $rows = $this->scanViaWpCli($target);
            if ($rows !== null) {
                $this->lastEngine = self::ENGINE_WPCLI;
                return $rows;
            }
        }
        global $wpdb;
        $this->lastEngine = self::ENGINE_PHP;
        return UsageScanner::scanFromWpdb($wpdb, $target);
    }

    /**
     * @param array{kind:string,id:string,name:string} $target
     * @return array<int, array<string, mixed>>|null
     */
    private function scanViaWpCli(array $target): ?array
    {
        $this->lastEngineError = null;

        if (!function_exists('proc_open') || !function_exists('proc_close')) {
            $this->lastEngineError = ['stage' => 'proc_open disabled'];
            return null;
        }

        $script = ABBTL_PLUGIN_DIR . 'src/Modules/BricksClassVariableFinder/wpcli-scan.php';
        if (!is_file($script)) {
            $this->lastEngineError = ['stage' => 'script missing'];
            return null;
        }

        $args = sprintf(
            'eval-file %s --skip-plugins --skip-themes --path=%s',
            escapeshellarg($script),
            escapeshellarg(ABSPATH)
        );
        $cmd = WpCli::buildCommand($args);
        if ($cmd === null) {
            $this->lastEngineError = ['stage' => 'buildCommand returned null'];
            return null;
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $options = [];
        if (defined('PHP_WINDOWS_VERSION_MAJOR')) {
            $options['bypass_shell'] = true;
        }

        $process = @proc_open($cmd, $descriptors, $pipes, null, null, $options);
        if (!is_resource($process)) {
            $this->lastEngineError = ['stage' => 'proc_open spawn failed', 'cmd' => $cmd];
            return null;
        }

        $payload = json_encode($target);
        if ($payload === false) {
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            $this->lastEngineError = ['stage' => 'target payload encode failed'];
            return null;
        }

        fwrite($pipes[0], $payload);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            $this->lastEngineError = [
                'stage'    => 'non-zero exit',
                'exitCode' => $exitCode,
                'stderr'   => mb_substr(trim($stderr), 0, 800),
                'cmd'      => (defined('WP_DEBUG') && WP_DEBUG) ? $cmd : null,
            ];
            return null;
        }

        $data = json_decode($stdout, true);
        if (!is_array($data)) {
            $this->lastEngineError = [
                'stage'  => 'invalid JSON from stdout',
                'stderr' => mb_substr(trim($stderr), 0, 400),
                'stdout' => mb_substr(trim($stdout), 0, 400),
                'cmd'    => (defined('WP_DEBUG') && WP_DEBUG) ? $cmd : null,
            ];
            return null;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $r
     */
    private function hydrate(array $r): Usage
    {
        $classIds = [];
        if (isset($r['classIds']) && is_array($r['classIds'])) {
            foreach ($r['classIds'] as $cid) {
                if (is_string($cid) && $cid !== '') {
                    $classIds[] = $cid;
                }
            }
        }

        return new Usage(
            postId:       (int) ($r['postId'] ?? 0),
            postTitle:    (string) ($r['postTitle'] ?? ''),
            postType:     (string) ($r['postType'] ?? ''),
            postStatus:   (string) ($r['postStatus'] ?? ''),
            metaKey:      (string) ($r['metaKey'] ?? ''),
            elementId:    (string) ($r['elementId'] ?? ''),
            elementName:  (string) ($r['elementName'] ?? ''),
            elementLabel: isset($r['elementLabel']) && is_string($r['elementLabel']) ? $r['elementLabel'] : null,
            classIds:     $classIds,
        );
    }
}
