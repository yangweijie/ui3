<?php
declare(strict_types=1);

namespace Yangweijie\Ui3\Automation;

/**
 * Records automation actions as they are performed, so a session can be saved
 * and replayed later. Each method drives the app immediately (so the live run
 * and the recording stay in lock-step) and appends the action to the log.
 */
final class Recorder
{
    /** @var list<array{type:string,target?:string,msg?:string}> */
    private array $actions = [];

    public function __construct(private Automation $auto)
    {
    }

    public function clickById(string $id): self
    {
        $this->actions[] = ['type' => 'click_id', 'target' => $id];
        $this->auto->clickById($id);
        return $this;
    }

    public function clickText(string $text): self
    {
        $this->actions[] = ['type' => 'click_text', 'target' => $text];
        $this->auto->clickText($text);
        return $this;
    }

    public function dispatch(string $msg): self
    {
        $this->actions[] = ['type' => 'dispatch', 'msg' => $msg];
        $this->auto->dispatch($msg);
        return $this;
    }

    public function script(): Script
    {
        return new Script($this->actions);
    }

    public function save(string $path): self
    {
        $this->script()->toFile($path);
        return $this;
    }

    /** @return list<array{type:string,target?:string,msg?:string}> */
    public function actions(): array
    {
        return $this->actions;
    }
}
