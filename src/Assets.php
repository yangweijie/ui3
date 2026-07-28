<?php

declare(strict_types=1);

namespace Yangweijie\Ui3;

/**
 * Assets pipeline — mirrors native's assets/. Registers logical asset names to
 * concrete paths and resolves them to URLs (relative to a base directory, with
 * optional cache-busting by mtime). Keeps view code referencing 'icon:save'
 * instead of hard-coded paths.
 */
final class Assets
{
    /** @var array<string,string> */
    private array $paths = [];

    public function __construct(private string $base = '')
    {
        $this->base = rtrim($base, '/');
    }

    public function register(string $name, string $path): void
    {
        $this->paths[$name] = $path;
    }

    public function has(string $name): bool
    {
        return isset($this->paths[$name]);
    }

    /** Resolve a registered asset to a URL (base prefix applied when relative). */
    public function url(string $name): ?string
    {
        if (!isset($this->paths[$name])) {
            return null;
        }
        $p = $this->paths[$name];
        if ($this->base !== '' && !str_starts_with($p, '/') && !preg_match('#^[a-z]+://#i', $p)) {
            $p = $this->base . '/' . $p;
        }
        return $p;
    }

    /** Resolve to a URL with a ?v=<mtime> cache-buster when the file exists. */
    public function urlWithVersion(string $name): ?string
    {
        $url = $this->url($name);
        if ($url === null) {
            return null;
        }
        $file = $this->paths[$name];
        if (is_file($file)) {
            $url .= '?v=' . filemtime($file);
        }
        return $url;
    }
}
