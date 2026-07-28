<?php
declare(strict_types=1);

namespace Yangweijie\Ui3\Automation;

/**
 * A replayable automation script: an ordered list of high-level actions
 * (click by id, click by text, raw dispatch). Persisted as JSON so a recorded
 * session can be shipped and replayed against a fresh app — mirroring native's
 * record-replay harness.
 *
 * @phpstan-type Action array{type:string,target?:string,msg?:string}
 */
final class Script
{
    /** @param list<Action> $actions */
    public function __construct(public readonly array $actions)
    {
    }

    /** @return list<Action> */
    public function actions(): array
    {
        return $this->actions;
    }

    public function toFile(string $path): void
    {
        file_put_contents($path, json_encode(
            ['version' => 1, 'actions' => $this->actions],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        ));
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new \RuntimeException("automation script not found: {$path}");
        }
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        /** @var list<Action> $actions */
        $actions = $data['actions'] ?? [];
        return new self($actions);
    }
}
