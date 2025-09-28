<?php
namespace Snappy\Cli;

use Snappy\Util\color;

/**
 * Aggregated output formatter supporting text + JSON modes.
 */
class output_formatter {
    private bool $jsonMode;
    private bool $quiet;
    private array $messages = [];
    private array $tables = [];
    private array $errors = [];
    private array $payload = [];

    public function __construct(bool $jsonMode = false, bool $quiet = false) {
        $this->jsonMode = $jsonMode;
        $this->quiet = $quiet;
    }

    public function isJson(): bool { return $this->jsonMode; }
    public function isQuiet(): bool { return $this->quiet; }

    public function info(string $msg): void {
        if ($this->jsonMode) { $this->messages[] = $msg; return; }
        if ($this->quiet) { return; }
        echo $msg . "\n";
    }

    /**
     * Add a structured table.
     * @param string[] $headers
     * @param array<int,array<int,string>> $rows
     */
    public function table(array $headers, array $rows): void {
        if ($this->jsonMode) {
            $this->tables[] = ['headers'=>$headers,'rows'=>$rows];
            return;
        }
        if ($this->quiet) { return; }
        // compute widths
        $widths = [];
        foreach ($headers as $i => $h) { $widths[$i] = strlen($h); }
        foreach ($rows as $r) {
            foreach ($r as $i => $cell) { $widths[$i] = max($widths[$i] ?? 0, strlen($cell)); }
        }
        // header
        foreach ($headers as $i => $h) {
            $pad = $widths[$i];
            printf('%-' . $pad . 's', $h);
            if ($i < count($headers)-1) { echo '  '; }
        }
        echo "\n";
        // rows
        foreach ($rows as $r) {
            foreach ($r as $i => $cell) {
                $pad = $widths[$i];
                printf('%-' . $pad . 's', $cell);
                if ($i < count($r)-1) { echo '  '; }
            }
            echo "\n";
        }
    }

    /** Replace payload (domain specific data) */
    public function json($data): void {
        if ($this->jsonMode) { $this->payload = $data; }
    }

    public function error(string $msg, int $code): void {
        if ($this->jsonMode) { $this->errors[] = ['code'=>$code,'message'=>$msg]; return; }
        fwrite(STDERR, 'ERROR(' . $code . '): ' . $msg . "\n");
    }

    public function flush(?string $command, string $status = 'ok'): void {
        if (!$this->jsonMode) { return; }
        $out = [
            'command' => $command,
            'status' => $status,
            'data' => [
                'messages' => $this->messages,
                'tables' => $this->tables,
            ],
        ];
        if ($this->payload) { $out['data']['payload'] = $this->payload; }
        if ($this->errors) { $out['errors'] = $this->errors; }
        echo json_encode($out, JSON_UNESCAPED_SLASHES) . "\n";
    }
}

