<?php

namespace Snappy\Snapshot;

use Snappy\Support\Exception\ValidationException;

/**
 * Resolves an appropriate dump provider for a given context.
 */
class DumpProviderResolver {
    /** @var DumpProviderInterface[] */
    private array $providers = [];

    /** @param DumpProviderInterface[] $providers */
    public function __construct(array $providers = []) {
        if (!$providers) {
            $fake = getenv('SNAPPY_FAKE_DUMP');
            if ($fake !== false && $fake !== '') { $providers[] = new FakeDumpProvider(); }
            $providers[] = new TdbDumpProvider();
        }
        $this->providers = $providers;
    }

    /** @return DumpProviderInterface */
    public function resolve(array $context): DumpProviderInterface {
        foreach ($this->providers as $p) {
            if ($p->supports($context)) { return $p; }
        }
        throw new ValidationException('No dump provider available for context');
    }

    /** @return DumpProviderInterface[] */
    public function all(): array { return $this->providers; }
}
