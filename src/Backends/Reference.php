<?php
declare(strict_types=1);

namespace Yangweijie\Ui3\Backends;

use Yangweijie\Ui3\{Backend, Element, Theme};
use Yangweijie\Ui3\Animation;
use Yangweijie\Ui3\Canvas\Layout;
use Yangweijie\Ui3\Canvas\Node;

/**
 * Pure-PHP reference renderer (the "NullPlatform" equivalent).
 *
 * Rasterizes the Element tree to a PNG without FFI / the native libui3 host,
 * so rendering and layout can be exercised deterministically in CI and used for
 * pixel-level regression tests. It reuses the production layout engine
 * (Canvas\Layout) with an injected pure-PHP text measurer.
 */
final class Reference implements Backend
{
    private int $width;
    private int $height;
    /** @var array<string,array{0:float,1:float,2:float,3:float}> */
    private array $theme;
    private ?Element $root = null;
    /** @var ?\Closure(string,mixed=):mixed */
    private ?\Closure $dispatch = null;

    private ?\GdImage $img = null;
    /** @var array<string,int> */
    private array $colors = [];

    private float $clock = 0.0;
    /** @var array<string,float> */
    private array $animStart = [];
    /** @var array<string,array{alpha:float,dx:float,dy:float,scale:float,done:bool}> */
    private array $animStates = [];
    /** @var array<string,array{phase:string,text:string}> */
    private array $composition = [];

    /** @param string|array $theme Theme name (Theme::*) or a resolved token map */
    public function __construct(int $width = 640, int $height = 400, string|array $theme = Theme::LIGHT)
    {
        $this->width = $width;
        $this->height = $height;
        $this->theme = is_array($theme) ? $theme : Theme::get($theme);
    }

    public function mount(Element $root, \Closure $dispatch): void
    {
        $this->root = $root;
        $this->dispatch = $dispatch;
    }

    public function update(Element $root): void
    {
        $this->root = $root;
        $this->render();
    }

    public function isHeadless(): bool
    {
        return true;
    }

    public function step(): int
    {
        return 0;
    }

    public function run(): void
    {
        // Headless: nothing to pump.
    }

    public function quit(): void
    {
        $this->img = null;
    }

    // ----- headless animation clock -----
    public function setClock(float $t): void
    {
        $this->clock = $t;
    }

    public function clock(): float
    {
        return $this->clock;
    }

    public function resetClock(): void
    {
        $this->clock = 0.0;
        $this->animStart = [];
        $this->animStates = [];
    }

    /** True while any mounted element still has an in-flight animation. */
    public function isAnimating(): bool
    {
        foreach ($this->animStates as $st) {
            if (!($st['done'] ?? false)) {
                return true;
            }
        }
        return false;
    }

    /** Read the last interpolated state for an element id (matches Canvas::animState). */
    public function animState(string $id): ?array
    {
        return $this->animStates[$id] ?? null;
    }

    /**
     * Feed an IME composition event for a field. $phase is one of
     * 'start' | 'update' | 'end'; $text is the current candidate string
     * (empty on 'end'). The next paint draws it as a preview after the
     * committed value with an underline, like a real IME preview.
     */
    public function composition(string $id, string $phase, string $text): void
    {
        if ($phase === 'end') {
            unset($this->composition[$id]);
        } else {
            $this->composition[$id] = ['phase' => $phase, 'text' => $text];
        }
    }

    public function root(): ?Element
    {
        return $this->root;
    }

    /** @return list<Node> */
    public function layout(): array
    {
        if ($this->root === null) {
            return [];
        }
        return $this->compute();
    }

    public function focusedId(): ?string
    {
        return null;
    }

    // --- Rendering ---------------------------------------------------------

    /** Render the tree to an internal GD image (idempotent). */
    public function render(): \GdImage
    {
        $prev = Layout::$textWidthFn ?? null;
        Layout::setTextWidth([self::class, 'pureTextWidth']);
        $nodes = $this->compute();
        Layout::setTextWidth($prev);

        $img = imagecreatetruecolor($this->width, $this->height);
        $this->img = $img;
        $this->colors = [];
        imagefill($img, 0, 0, $this->c('bg'));

        $listItem = 0;
        foreach ($nodes as $n) {
            if ($n->type === 'list_item') {
                $listItem++;
            }
            $this->draw($n, $listItem);
        }
        return $img;
    }

    /** @return list<Node> */
    private function compute(): array
    {
        \assert($this->root !== null);
        return Layout::compute($this->root);
    }

    public function savePng(string $path): void
    {
        imagepng($this->render(), $path);
    }

    /** Deterministic MD5 over the raw RGB pixels (encoding-independent). */
    public function pixelsHash(): string
    {
        $img = $this->render();
        $out = '';
        for ($y = 0; $y < $this->height; $y++) {
            for ($x = 0; $x < $this->width; $x++) {
                $p = imagecolorat($img, $x, $y);
                $out .= chr(($p >> 16) & 0xFF) . chr(($p >> 8) & 0xFF) . chr($p & 0xFF);
            }
        }
        return md5($out);
    }

    // --- Draw helpers ------------------------------------------------------

    private function c(string $tok): int
    {
        if (!isset($this->colors[$tok])) {
            $t = $this->theme[$tok] ?? [0.0, 0.0, 0.0, 1.0];
            $this->colors[$tok] = imagecolorallocate(
                $this->img,
                (int) round($t[0] * 255),
                (int) round($t[1] * 255),
                (int) round($t[2] * 255),
            );
        }
        return $this->colors[$tok];
    }

    private function draw(Node $n, int $listItem): void
    {
        $x = $n->x;
        $y = $n->y;
        $w = $n->w;
        $h = $n->h;
        $el = $n->el;
        $img = $this->img;

        // ----- animation: interpolate from the clock (same math as Canvas) -----
        $alpha = 1.0;
        $anim = $el->prop('anim');
        if (is_array($anim) && $anim !== []) {
            $aid = (string)($el->prop('id') ?? spl_object_id($el));
            if (!isset($this->animStart[$aid])) {
                $this->animStart[$aid] = $this->clock;
            }
            $elapsed = ($this->clock - $this->animStart[$aid]) * 1000.0;
            $st = Animation::frame($anim, $elapsed);
            $alpha = $st['alpha'];
            $x = (int)($x + $st['dx']);
            $y = (int)($y + $st['dy']);
            $w = (int)max(1, $w * $st['scale']);
            $h = (int)max(1, $h * $st['scale']);
            $this->animStates[$aid] = $st;
        }

        switch ($n->type) {
            case 'window':
                return; // background already filled

            case 'scroll_end':
                return; // Canvas-only clip-stack sentinel, nothing to draw here

            case 'panel':
            case 'card':
            case 'dialog':
            case 'sheet':
            case 'drawer':
            case 'tabs':
            case 'menu':
            case 'tree':
                imagefilledrectangle($img, $x, $y, $x + $w - 1, $y + $h - 1, $this->c('surface'));
                imagerectangle($img, $x, $y, $x + $w - 1, $y + $h - 1, $this->c('border'));
                break;

            case 'label':
            case 'heading':
            case 'text':
                $size = (float) ($el->prop('size') ?? ($n->type === 'heading' ? 18.0 : 13.0));
                $this->text($x + 4, $y + (int) (($h - $size) / 2), (string) $el->prop('text'), 'text', $size, $w - 8);
                break;

            case 'button':
                $accent = (bool) ($el->prop('accent') ?? true);
                imagefilledrectangle($img, $x, $y, $x + $w - 1, $y + $h - 1, $this->c($accent ? 'accent' : 'surface'));
                imagerectangle($img, $x, $y, $x + $w - 1, $y + $h - 1, $this->c('border'));
                $this->text($x + 6, $y + (int) (($h - 13) / 2), (string) $el->prop('text'), $accent ? 'accentText' : 'text', 13.0, $w - 12);
                break;

            case 'input':
            case 'search':
            case 'textarea':
                imagefilledrectangle($img, $x, $y, $x + $w - 1, $y + $h - 1, $this->c('surface'));
                imagerectangle($img, $x, $y, $x + $w - 1, $y + $h - 1, $this->c('border'));
                $value = (string) $el->prop('text');
                $cid = (string) ($el->prop('id') ?? '');
                $ty = $y + (int) (($h - 13) / 2);
                $this->text($x + 4, $ty, $value, 'text', 13.0, $w - 8);
                $comp = $cid !== '' ? ($this->composition[$cid] ?? null) : null;
                if ($comp !== null && ($comp['text'] ?? '') !== '') {
                    $cx = $x + 4 + (int) self::pureTextWidth($value, 13.0);
                    $this->text($cx, $ty, $comp['text'], 'accentText', 13.0, max(1, $w - 8 - ($cx - $x)));
                    $ulen = max(1, (int) self::pureTextWidth($comp['text'], 13.0));
                    imageline($img, $cx, $ty + 13, $cx + $ulen, $ty + 13, $this->c('accent'));
                }
                break;

            case 'checkbox':
            case 'radio':
                $checked = (bool) $el->prop('checked');
                $s = min($h, 16);
                imagerectangle($img, $x, $y + (int) (($h - $s) / 2), $x + $s, $y + (int) (($h - $s) / 2) + $s, $this->c('border'));
                if ($n->type === 'radio') {
                    if ($checked) {
                        imagefilledellipse($img, $x + (int) ($s / 2), $y + (int) (($h - $s) / 2) + (int) ($s / 2), (int) ($s * 0.5), (int) ($s * 0.5), $this->c('accent'));
                    }
                } elseif ($checked) {
                    imagefilledrectangle($img, $x + 3, $y + (int) (($h - $s) / 2) + 3, $x + $s - 3, $y + (int) (($h - $s) / 2) + $s - 3, $this->c('accent'));
                }
                $this->text($x + $s + 4, $y + (int) (($h - 13) / 2), (string) $el->prop('text'), 'text', 13.0, $w - $s - 8);
                break;

            case 'toggle':
            case 'switch':
                $on = (bool) $el->prop('checked');
                $r = (int) ($h / 2);
                imagefilledrectangle($img, $x, $y + (int) (($h - $r * 2) / 2), $x + $w - 1, $y + (int) (($h - $r * 2) / 2) + $r * 2 - 1, $this->c($on ? 'accent' : 'surfaceAlt'));
                $kx = $on ? $x + $w - $r * 2 : $x;
                imagefilledellipse($img, $kx + $r, $y + (int) ($h / 2), $r, $r, $this->c('surface'));
                break;

            case 'slider':
                $min = (float) ($el->prop('min') ?? 0);
                $max = (float) ($el->prop('max') ?? 100);
                $val = (float) ($el->prop('value') ?? $min);
                $frac = $max > $min ? ($val - $min) / ($max - $min) : 0;
                imagefilledrectangle($img, $x, $y + (int) ($h / 2) - 2, $x + $w - 1, $y + (int) ($h / 2) + 2, $this->c('surfaceAlt'));
                imagefilledrectangle($img, $x, $y + (int) ($h / 2) - 2, $x + (int) ($w * $frac), $y + (int) ($h / 2) + 2, $this->c('accent'));
                break;

            case 'progress':
                $max = (float) ($el->prop('max') ?? 100);
                $val = (float) ($el->prop('value') ?? 0);
                $frac = $max > 0 ? min(1.0, $val / $max) : 0;
                imagefilledrectangle($img, $x, $y + 2, $x + $w - 1, $y + $h - 3, $this->c('surfaceAlt'));
                imagefilledrectangle($img, $x, $y + 2, $x + (int) ($w * $frac) - 1, $y + $h - 3, $this->c('accent'));
                break;

            case 'select':
            case 'segmented':
                imagefilledrectangle($img, $x, $y, $x + $w - 1, $y + $h - 1, $this->c('surface'));
                imagerectangle($img, $x, $y, $x + $w - 1, $y + $h - 1, $this->c('border'));
                $this->text($x + 4, $y + (int) (($h - 13) / 2), (string) $el->prop('value'), 'text', 13.0, $w - 8);
                break;

            case 'list_item':
                imagefilledrectangle($img, $x, $y, $x + $w - 1, $y + $h - 1, $this->c($listItem % 2 === 0 ? 'surfaceAlt' : 'surface'));
                $this->text($x + 4, $y + (int) (($h - 13) / 2), (string) $el->prop('text'), 'text', 13.0, $w - 8);
                break;

            case 'tab':
                $sel = (bool) $el->prop('selected');
                imagefilledrectangle($img, $x, $y, $x + $w - 1, $y + $h - 1, $this->c($sel ? 'accent' : 'surfaceAlt'));
                $this->text($x + 4, $y + (int) (($h - 13) / 2), (string) $el->prop('text'), $sel ? 'accentText' : 'textMuted', 13.0, $w - 8);
                break;

            case 'acc_header':
            case 'menu_item':
                $sel = (bool) $el->prop('selected');
                if ($sel) {
                    imagefilledrectangle($img, $x, $y, $x + $w - 1, $y + $h - 1, $this->c('selection'));
                }
                $this->text($x + 4, $y + (int) (($h - 13) / 2), (string) $el->prop('text'), 'text', 13.0, $w - 8);
                break;

            case 'tree_node':
                $this->text($x + 4, $y + (int) (($h - 13) / 2), (string) $el->prop('text'), 'text', 13.0, $w - 8);
                break;

            case 'divider':
                imagefilledrectangle($img, $x, $y + (int) ($h / 2), $x + $w - 1, $y + (int) ($h / 2), $this->c('border'));
                break;

            case 'spacer':
                return;

            case 'chart':
            case 'image':
            case 'webview':
            case 'gpusurface':
            case 'richtext':
            case 'avatar':
            case 'scroll':
                imagefilledrectangle($img, $x, $y, $x + $w - 1, $y + $h - 1, $this->c('surface'));
                imagerectangle($img, $x, $y, $x + $w - 1, $y + $h - 1, $this->c('border'));
                $this->text($x + 4, $y + (int) (($h - 13) / 2), (string) ($el->prop('title') ?? ''), 'textMuted', 13.0, $w - 8);
                break;

            case 'table':
                imagerectangle($img, $x, $y, $x + $w - 1, $y + $h - 1, $this->c('border'));
                $cols = $el->prop('columns', []);
                $rows = $el->prop('rows', []);
                $nc = max(1, count($cols));
                $ch = count($rows) > 0 ? (int) ($h / (count($rows) + 1)) : 20;
                $cw = (int) ($w / $nc);
                foreach ($cols as $i => $c) {
                    $this->text($x + $i * $cw + 4, $y + 4, (string) $c, 'textMuted', 11.0, $cw - 8);
                }
                foreach ($rows as $r => $row) {
                    foreach ($row as $i => $cell) {
                        $this->text($x + $i * $cw + 4, $y + 4 + ($r + 1) * $ch, (string) $cell, 'text', 11.0, $cw - 8);
                    }
                }
                break;

            case 'skeleton':
            case 'spinner':
            case 'badge':
            case 'breadcrumb':
            case 'pagination':
            case 'tooltip':
            default:
                imagerectangle($img, $x, $y, $x + $w - 1, $y + $h - 1, $this->c('border'));
                $txt = (string) ($el->prop('text') ?? $el->prop('title') ?? '');
                if ($txt !== '') {
                    $this->text($x + 4, $y + (int) (($h - 13) / 2), $txt, 'textMuted', 13.0, $w - 8);
                }
                break;
        }

        if ($alpha < 0.999) {
            $this->applyAlpha($x, $y, $w, $h, $alpha);
        }
    }

    /**
     * Approximate opacity on a flat (no group-alpha) canvas: blend the theme
     * background back over the node rect by (1-alpha). Deterministic, no fonts
     * or FFI — a node at alpha 0.0 looks exactly like the background.
     */
    private function applyAlpha(int $x, int $y, int $w, int $h, float $alpha): void
    {
        $xx = max(0, $x);
        $yy = max(0, $y);
        $ww = min($w, $this->width - $xx);
        $hh = min($h, $this->height - $yy);
        if ($ww <= 0 || $hh <= 0) {
            return;
        }
        $bg = imagecreatetruecolor($ww, $hh);
        $t = $this->theme['bg'];
        $bgc = imagecolorallocate($bg, (int) round($t[0] * 255), (int) round($t[1] * 255), (int) round($t[2] * 255));
        imagefilledrectangle($bg, 0, 0, $ww - 1, $hh - 1, $bgc);
        imagecopymerge($this->img, $bg, $xx, $yy, 0, 0, $ww, $hh, (int)round((1.0 - $alpha) * 100));
    }

    private function text(int $x, int $y, string $s, string $colorTok, float $size, int $maxWidth): void
    {
        if ($s === '') {
            return;
        }
        $s = $this->ellipsize($s, $size, $maxWidth);
        imagestring($this->img, $size >= 16 ? 4 : 3, $x, $y, $s, $this->c($colorTok));
    }

    /** Truncate to $maxWidth using per-character width classes (CJK/IME aware). */
    private static function ellipsize(string $s, float $size, int $maxWidth): string
    {
        if ($maxWidth <= 0) {
            return $s;
        }
        $used = 0.0;
        $out = '';
        $len = mb_strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $ch = mb_substr($s, $i, 1);
            $uw = self::charWidthUnit($ch) * $size;
            if ($used + $uw > $maxWidth && $i > 0) {
                break;
            }
            $used += $uw;
            $out .= $ch;
        }
        return $out === $s ? $s : $out . '…';
    }

    /**
     * Per-character width estimate used by Layout when no real font is present.
     * Half-width glyphs cost ~0.6em, full-width (CJK / fullwidth forms) ~1.0em,
     * combining marks and IME composition chars cost 0 — they attach to the base
     * glyph. Deterministic and font-free, so baselines stay stable across machines.
     * This is a layout-width estimate, not a glyph-accurate metric (the Reference
     * renderer draws Latin bitmap glyphs only).
     */
    public static function pureTextWidth(string $s, float $size = 13.0): float
    {
        $total = 0.0;
        $len = mb_strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $total += self::charWidthUnit(mb_substr($s, $i, 1)) * $size;
        }
        return $total;
    }

    /** Width unit (in em) for a single character, by Unicode class. */
    private static function charWidthUnit(string $ch): float
    {
        $cp = mb_ord($ch);
        if ($cp === false || $cp < 0x20) {
            return 0.0;
        }
        if ($cp === 0x200B) { // zero-width space
            return 0.0;
        }
        if (($cp >= 0x0300 && $cp <= 0x036F)   // combining diacriticals
            || ($cp >= 0x1AB0 && $cp <= 0x1AFF)
            || ($cp >= 0x1DC0 && $cp <= 0x1DFF)
            || ($cp >= 0x20D0 && $cp <= 0x20FF)) { // combining marks (IME composition)
            return 0.0;
        }
        if (($cp >= 0x1100 && $cp <= 0x115F)   // Hangul Jamo
            || ($cp >= 0x2E80 && $cp <= 0x303E) // CJK radicals / symbols
            || ($cp >= 0x3041 && $cp <= 0x3096) // Hiragana
            || ($cp >= 0x30A1 && $cp <= 0x30FF) // Katakana
            || ($cp >= 0x3400 && $cp <= 0x4DBF) // CJK Ext A
            || ($cp >= 0x4E00 && $cp <= 0x9FFF) // CJK Unified
            || ($cp >= 0xAC00 && $cp <= 0xD7A3) // Hangul syllables
            || ($cp >= 0xF900 && $cp <= 0xFAFF) // CJK compat ideographs
            || ($cp >= 0xFF00 && $cp <= 0xFF60) // fullwidth ASCII
            || ($cp >= 0xFFE0 && $cp <= 0xFFE6) // fullwidth signs
            || ($cp >= 0x3000 && $cp <= 0x303F)) { // CJK symbols & punctuation
            return 1.0;
        }
        if ($cp >= 0xFF61 && $cp <= 0xFF9F) { // halfwidth katakana
            return 0.6;
        }
        return 0.6; // Latin / Cyrillic / symbols / etc.
    }
}
