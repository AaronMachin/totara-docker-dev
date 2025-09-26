<?php
// DEPRECATED: This file has been superseded by the Snappy\Hosting namespace classes:
//   - Snappy\Hosting\HostOptions (hostoptions.php)
//   - Snappy\Hosting\HostRuntime (hostruntime.php)
//   - Snappy\Hosting\remote_codec (remote_codec.php)
// Kept only for transitional compatibility. Do not use in new code.
// If loaded, we include the new implementations so any stray legacy references
// can still function (though class names differ, so update those usages).
require_once __DIR__ . '/../hosting/hostoptions.php';
require_once __DIR__ . '/../hosting/hostruntime.php';
require_once __DIR__ . '/../hosting/remote_codec.php';
