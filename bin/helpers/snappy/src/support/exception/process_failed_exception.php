<?php
namespace Snappy\Support\Exception;

use Snappy\Support\Process\process_result;

class ProcessFailedException extends SnappyException {
    // Store full process result for future logging/inspection (T3.3 follow-up)
    private process_result $result;

    public function __construct(string $message, ?process_result $result = null) {
        parent::__construct($message);
        if ($result) { $this->result = $result; }
    }

    public function result(): ?process_result { return $this->result ?? null; }
}
