<?php

declare(strict_types=1);

namespace Yangweijie\Ui3\Backends;

use Yangweijie\Ui3\Backend;
use Yangweijie\Ui3\Canvas\Layout;
use Yangweijie\Ui3\Element;
use Yangweijie\Ui3\Theme;

/**
 * Web target backend — renders the Element tree to HTML (server-side / static
 * web export), mirroring native's web target. Produces semantic markup with
 * data-role / data-id / data-label attributes so the same accessibility tree
 * and automation contract the Canvas backend offers is available on the web.
 */
final class Html implements Backend
{
    private ?Element $root = null;
    private ?\Closure $dispatch = null;
    private string $html = '';
    private array $theme = [];

    public function __construct(private string|array $themeName = Theme::LIGHT)
    {
        $this->theme = Theme::get($themeName);
    }

    public function mount(Element $root, \Closure $dispatch): void
    {
        $this->root = $root;
        $this->dispatch = $dispatch;
        $this->render();
    }

    public function update(Element $root): void
    {
        $this->root = $root;
        $this->render();
    }

    public function step(): int
    {
        return 1; // server-rendered: no event loop to pump
    }

    public function run(): void
    {
        // The web backend does not own a loop; the surrounding HTTP server does.
    }

    public function quit(): void
    {
    }

    public function isHeadless(): bool
    {
        return true;
    }

    /** Rendered HTML document fragment for the current tree. */
    public function html(): string
    {
        return $this->html;
    }

    /** Layout nodes for snapshot compatibility. */
    public function layout(): array
    {
        return $this->root !== null ? Layout::compute($this->root) : [];
    }

    public function focusedId(): ?string
    {
        return null;
    }

    private function render(): void
    {
        if ($this->root === null) {
            $this->html = '';
            return;
        }
        $bg = $this->css($this->theme['bg'] ?? [1, 1, 1]);
        $fg = $this->css($this->theme['text'] ?? [0, 0, 0]);
        $this->html = '<div class="ui3-root" style="background:' . $bg . ';color:' . $fg . '">'
            . $this->renderNode($this->root) . '</div>';
    }

    private function renderNode(Element $el, int $depth = 0): string
    {
        if ($depth > 64) {
            return '';
        }
        $role = $el->prop('role') ?? $el->type;
        $id = $el->prop('id');
        $attrs = ' data-role="' . $this->esc((string) $role) . '"'
            . ($id !== null ? ' data-id="' . $this->esc((string) $id) . '"' : '')
            . ($el->prop('label') !== null ? ' data-label="' . $this->esc((string) $el->prop('label')) . '"' : '')
            . ($el->prop('description') !== null ? ' data-description="' . $this->esc((string) $el->prop('description')) . '"' : '');

        $tag = $this->tagFor((string) $el->type);
        $selfClosing = $tag === 'input';
        $inner = $selfClosing ? '' : $this->escapeText($el) . $this->renderChildren($el, $depth + 1);

        if ($selfClosing) {
            $value = $el->prop('value') ?? $el->prop('text') ?? '';
            $ph = $el->prop('placeholder') ?? '';
            $type = match ($el->type) {
                'checkbox', 'radio', 'toggle' => 'checkbox',
                'slider' => 'range',
                'search' => 'search',
                'textarea' => 'textarea',
                default => 'text',
            };
            return '<' . $tag . $attrs . ' type="' . $type . '"'
                . ($value !== '' ? ' value="' . $this->esc((string) $value) . '"' : '')
                . ($ph !== '' ? ' placeholder="' . $this->esc((string) $ph) . '"' : '')
                . '>';
        }

        return '<' . $tag . $attrs . '>' . $inner . '</' . $tag . '>';
    }

    /** @param array<int,Element> $children */
    private function renderChildren(Element $el, int $depth): string
    {
        $out = '';
        foreach ($el->children as $c) {
            $out .= $this->renderNode($c, $depth);
        }
        return $out;
    }

    private function tagFor(string $type): string
    {
        return match ($type) {
            'window' => 'div',
            'button', 'iconbutton' => 'button',
            'label', 'text' => 'span',
            'input', 'search', 'textarea', 'checkbox', 'radio', 'slider', 'toggle' => 'input',
            'list' => 'ul',
            'list_item' => 'li',
            'select', 'dropdown' => 'select',
            'menu', 'contextmenu' => 'div',
            'menu_item' => 'div',
            'card', 'panel', 'column', 'row', 'stack', 'alert', 'dialog', 'sheet', 'drawer',
            'scroll', 'tabs', 'tab_page', 'accordion', 'tree', 'tree_node', 'table',
            'breadcrumb', 'pagination', 'button_group', 'chart', 'avatar', 'badge',
            'skeleton', 'spinner', 'tooltip', 'richtext', 'segmented', 'split', 'strip',
            'icon', 'image', 'webview', 'gpusurface', 'custom' => 'div',
            default => 'div',
        };
    }

    private function escapeText(Element $el): string
    {
        $text = $el->prop('text') ?? $el->prop('title') ?? $el->prop('value') ?? '';
        return $text === '' ? '' : $this->esc((string) $text);
    }

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** @param array<int,float> $rgb */
    private function css(array $rgb): string
    {
        $r = (int) round(($rgb[0] ?? 0) * 255);
        $g = (int) round(($rgb[1] ?? 0) * 255);
        $b = (int) round(($rgb[2] ?? 0) * 255);
        return 'rgb(' . $r . ',' . $g . ',' . $b . ')';
    }
}
