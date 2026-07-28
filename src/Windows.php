<?php

declare(strict_types=1);

namespace Yangweijie\Ui3;

/**
 * Window state manager — mirrors native's window_state for multi-window apps.
 *
 * Tracks every window by id (title, geometry, model root, open/closed) and
 * which one is active. The Elm-style runtime renders the active window; this
 * class is the source of truth for window lifecycle and focus, independent of
 * how many native surfaces the host can actually present.
 */
final class Windows
{
    /** @var array<string,array{id:string,title:string,width:int,height:int,root:?Element,open:bool}> */
    private array $windows = [];

    private ?string $active = null;

    public function open(string $id, string $title, int $width = 320, int $height = 240): void
    {
        $this->windows[$id] = [
            'id' => $id, 'title' => $title, 'width' => $width, 'height' => $height,
            'root' => $this->windows[$id]['root'] ?? null, 'open' => true,
        ];
        if ($this->active === null) {
            $this->active = $id;
        }
    }

    public function close(string $id): void
    {
        if (!isset($this->windows[$id])) {
            return;
        }
        $this->windows[$id]['open'] = false;
        if ($this->active === $id) {
            $this->active = $this->firstOpen();
        }
    }

    public function focus(string $id): void
    {
        if ($this->isOpen($id)) {
            $this->active = $id;
        }
    }

    public function isOpen(string $id): bool
    {
        return ($this->windows[$id]['open'] ?? false) === true;
    }

    public function active(): ?string
    {
        return $this->active;
    }

    /** Attach/replace the model root for a window. */
    public function setRoot(string $id, ?Element $root): void
    {
        if (isset($this->windows[$id])) {
            $this->windows[$id]['root'] = $root;
        }
    }

    public function root(string $id): ?Element
    {
        return $this->windows[$id]['root'] ?? null;
    }

    /** @return list<array{id:string,title:string,width:int,height:int,open:bool}> */
    public function list(): array
    {
        $out = [];
        foreach ($this->windows as $w) {
            if (!$w['open']) {
                continue;
            }
            $out[] = ['id' => $w['id'], 'title' => $w['title'], 'width' => $w['width'], 'height' => $w['height'], 'open' => true];
        }
        return $out;
    }

    public function count(): int
    {
        return count($this->list());
    }

    private function firstOpen(): ?string
    {
        foreach ($this->windows as $w) {
            if ($w['open']) {
                return $w['id'];
            }
        }
        return null;
    }
}
