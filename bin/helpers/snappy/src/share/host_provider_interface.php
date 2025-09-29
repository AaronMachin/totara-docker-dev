<?php
namespace Snappy\Share;

interface host_provider_interface {
    public function name(): string;
    /** Start exposing the given local port. Return ['host'=>string,'port'=>int,'tunnel'=>string] or null on failure. */
    public function start(int $localPort): ?array;
    /** Shutdown / cleanup any resources */
    public function shutdown(): void;
}

