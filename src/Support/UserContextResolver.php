<?php

namespace Keepsuit\LaravelOpenTelemetry\Support;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;

class UserContextResolver
{
    /**
     * @var Closure(Authenticatable): array<non-empty-string, bool|int|float|string|array|null>|null
     */
    protected ?Closure $resolver = null;

    /**
     * Override the user context resolver.
     *
     * @param  Closure(Authenticatable): array<non-empty-string, bool|int|float|string|array|null>|null  $resolver
     */
    public function setResolver(?Closure $resolver): void
    {
        $this->resolver = $resolver;
    }

    /**
     * Collect attributes for a user.
     * By default, only the user ID is collected as 'user.id'.
     *
     * @return array<non-empty-string, bool|int|float|string|array|null>
     */
    public function collect(Authenticatable $user): array
    {
        $callback = match (true) {
            $this->resolver instanceof Closure => $this->resolver,
            default => fn (Authenticatable $user) => ['user.id' => $user->getAuthIdentifier()],
        };

        return $callback($user);
    }
}
