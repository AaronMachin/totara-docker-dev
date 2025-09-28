<?php
namespace Snappy\Support\Exception;

use Snappy\Support\Process\process_result;

class ProcessFailedException extends SnappyException {
    // Store full process result for future logging/inspection (T3.3 follow-up)
    private ?process_result $result = null; // now nullable
    private ?array $command = null; // vector of command arguments

    public function __construct(string $message, ?process_result $result = null, ?array $command = null) {
        parent::__construct($message);
        if ($result) { $this->result = $result; }
        $this->command = $command;
    }

    public function result(): ?process_result { return $this->result; }
    public function command(): ?array { return $this->command; }
}
