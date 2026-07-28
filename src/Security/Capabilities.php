<?php

declare(strict_types=1);

namespace Yangweijie\Ui3\Security;

/**
 * Capability model — mirrors native's security/ permission gate. The app grants
 * the capabilities it needs; sensitive operations call demand() and fail closed
 * (throw) when the capability is missing.
 */
final class Capabilities
{
    /** @var array<string,bool> */
    private array $granted = [];

    public function grant(string $cap): void
    {
        $this->granted[$cap] = true;
    }

    public function deny(string $cap): void
    {
        $this->granted[$cap] = false;
    }

    public function revoke(string $cap): void
    {
        $this->deny($cap);
    }

    public function allows(string $cap): bool
    {
        return ($this->granted[$cap] ?? false) === true;
    }

    /** @throws SecurityException if the capability is not granted. */
    public function demand(string $cap): void
    {
        if (!$this->allows($cap)) {
            throw new SecurityException("capability not granted: {$cap}");
        }
    }
}
