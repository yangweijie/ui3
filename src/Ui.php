<?php
declare(strict_types=1);

namespace Yangweijie\Ui3;

/**
 * Declarative UI builders (mirrors native's Ui.* helpers). Each returns an
 * immutable Element describing what to render; state lives only in the model.
 */
final class Ui
{
    /** Monotonic counter for anonymous canvas ids (stable, automation-friendly). */
    private static int $canvasSeq = 0;
    /** Monotonic counter for anonymous grid/absolute/positioned ids. */
    private static int $autoSeq = 0;
    /** Monotonic counter for anonymous animated element ids. */
    private static int $animSeq = 0;

    public static function window(string $title, array $children, int $width = 320, int $height = 240, ?string $id = null): Element
    {
        return new Element('window', [
            'title' => $title,
            'width' => $width,
            'height' => $height,
            'id' => $id,
        ], $children);
    }

    public static function column(array $children, ?string $id = null): Element
    {
        return new Element('column', ['id' => $id], $children);
    }

    public static function row(array $children, ?string $id = null): Element
    {
        return new Element('row', ['id' => $id], $children);
    }

    public static function label(string $text, ?string $id = null): Element
    {
        return new Element('label', ['text' => $text, 'id' => $id]);
    }

    public static function button(string $text, ?string $onClick = null, ?string $id = null): Element
    {
        return new Element('button', [
            'text' => $text,
            'onClick' => $onClick,
            'id' => $id,
        ]);
    }

    public static function heading(string $text, ?string $id = null): Element
    {
        return new Element('heading', ['text' => $text, 'id' => $id]);
    }

    /** Single-line editable text field. Emits $onInput with the new string. */
    public static function input(?string $value = null, ?string $placeholder = null, ?string $onInput = null, ?string $id = null, ?string $onComposition = null): Element
    {
        return new Element('input', [
            'text' => $value ?? '',
            'placeholder' => $placeholder ?? '',
            'onInput' => $onInput,
            'onComposition' => $onComposition,
            'id' => $id,
        ]);
    }

    /** Multi-line editable text area. Emits $onInput with the new string. */
    public static function textarea(?string $value = null, ?string $placeholder = null, ?string $onInput = null, ?string $id = null, ?string $onComposition = null): Element
    {
        return new Element('textarea', [
            'text' => $value ?? '',
            'placeholder' => $placeholder ?? '',
            'onInput' => $onInput,
            'onComposition' => $onComposition,
            'id' => $id,
        ]);
    }

    /** Toggle / checkbox. Emits $onChange with 1 (on) or 0 (off). */
    public static function checkbox(string $label, bool $checked = false, ?string $onChange = null, ?string $id = null): Element
    {
        return new Element('checkbox', [
            'text' => $label,
            'checked' => $checked,
            'onChange' => $onChange,
            'id' => $id,
        ]);
    }

    /** Radio button in a group. Emits $onChange with 1 when selected. */
    public static function radio(string $label, string $name, bool $checked = false, ?string $onChange = null, ?string $id = null): Element
    {
        return new Element('radio', [
            'text' => $label,
            'group' => $name,
            'checked' => $checked,
            'onChange' => $onChange,
            'id' => $id,
        ]);
    }

    /** Slider. Emits $onChange with the integer value. */
    public static function slider(int $min, int $max, int $value, ?string $onChange = null, ?string $id = null): Element
    {
        return new Element('slider', [
            'min' => $min,
            'max' => $max,
            'value' => $value,
            'onChange' => $onChange,
            'id' => $id,
        ]);
    }

    /** Progress bar. $value is 0..1. */
    public static function progress(float $value = 0.0, ?string $id = null): Element
    {
        return new Element('progress', ['value' => $value, 'id' => $id]);
    }

    /** Dropdown. Emits $onChange with the selected option index. */
    public static function select(array $options, int $selected = 0, ?string $onChange = null, ?string $id = null): Element
    {
        return new Element('select', [
            'options' => $options,
            'value' => $selected,
            'onChange' => $onChange,
            'id' => $id,
        ]);
    }

    /** List box. Pass string items for plain rows, or Ui::list_item() Elements
     *  for custom rows (icon + title + subtitle). Emits $onSelect with the index. */
    public static function list(array $items, int $selected = -1, ?string $onSelect = null, ?string $id = null): Element
    {
        if ($items !== [] && $items[0] instanceof Element) {
            return new Element('list', [
                'value' => $selected,
                'onSelect' => $onSelect,
                'id' => $id,
            ], $items);
        }
        return new Element('list', [
            'items' => $items,
            'value' => $selected,
            'onSelect' => $onSelect,
            'id' => $id,
        ]);
    }

    public static function image(string $src, ?string $id = null): Element
    {
        return new Element('image', ['src' => $src, 'id' => $id]);
    }

    public static function spacer(?string $id = null): Element
    {
        return new Element('spacer', ['id' => $id]);
    }

    public static function divider(?string $id = null): Element
    {
        return new Element('divider', ['id' => $id]);
    }

    /** Group box with a title and child controls. */
    public static function panel(string $title, array $children, ?string $id = null): Element
    {
        return new Element('panel', ['title' => $title, 'id' => $id], $children);
    }

    /**
     * Stack container (native 'stack'): a column or row selected by $axis.
     * Distinct from column/row so the native kind is represented 1:1.
     */
    public static function stack(array $children, string $axis = 'vertical', ?string $id = null): Element
    {
        return new Element('stack', [
            'axis' => $axis === 'horizontal' ? 'horizontal' : 'vertical',
            'id' => $id,
        ], $children);
    }

    /** Toggle switch (native 'toggle'). Emits $onChange with 1 (on) or 0 (off). */
    public static function toggle(string $label, bool $on = false, ?string $onChange = null, ?string $id = null): Element
    {
        return new Element('toggle', [
            'text' => $label,
            'on' => $on,
            'onChange' => $onChange,
            'id' => $id,
        ]);
    }

    /** Icon button (native 'icon_button'): a glyph plus optional label. Emits $onClick. */
    public static function iconButton(string $icon, ?string $text = null, ?string $onClick = null, ?string $id = null): Element
    {
        return new Element('iconbutton', [
            'icon' => $icon,
            'text' => $text ?? '',
            'onClick' => $onClick,
            'id' => $id,
        ]);
    }

    /** Segmented control (native 'segmented_control'). Emits $onChange with the selected index. */
    public static function segmented(array $options, int $selected = 0, ?string $onChange = null, ?string $id = null): Element
    {
        return new Element('segmented', [
            'options' => $options,
            'value' => $selected,
            'onChange' => $onChange,
            'id' => $id,
        ]);
    }

    /** Search field (native 'search_field'). Emits $onInput with the new string. */
    public static function searchField(?string $value = null, ?string $placeholder = null, ?string $onInput = null, ?string $id = null, ?string $onComposition = null): Element
    {
        return new Element('search', [
            'text' => $value ?? '',
            'placeholder' => $placeholder ?? '',
            'onInput' => $onInput,
            'id' => $id,
        ]);
    }

    /**
     * List item (native 'list_item'): a custom row (icon + title + optional
     * subtitle). Use as a child of Ui::list(); a list renders list_item rows
     * when it has such children, otherwise it keeps rendering its `items` strings.
     */
    public static function listItem(?string $icon = null, string $title = '', ?string $subtitle = null, ?string $id = null): Element
    {
        return new Element('list_item', [
            'icon' => $icon,
            'title' => $title,
            'subtitle' => $subtitle,
            'id' => $id,
        ]);
    }

    /**
     * Split container (native 'split'): two panes with a draggable divider.
     * Emits $onChange with the divider position as a 0..100 integer on drag.
     */
    public static function split(array $panes, string $orientation = 'horizontal', float $position = 0.5, ?string $onChange = null, ?string $id = null): Element
    {
        return new Element('split', [
            'orientation' => $orientation === 'vertical' ? 'vertical' : 'horizontal',
            'position' => $position,
            'onChange' => $onChange,
            'id' => $id,
        ], array_slice($panes, 0, 2));
    }

    /** Toolbar (native 'toolbar'): a top strip region holding controls. */
    public static function toolbar(array $children, ?string $id = null): Element
    {
        return new Element('toolbar', ['id' => $id], $children);
    }

    /** Sidebar (native 'sidebar'): a left strip region holding controls. */
    public static function sidebar(array $children, ?string $id = null): Element
    {
        return new Element('sidebar', ['id' => $id], $children);
    }

    /** Status bar (native 'statusbar'): a bottom strip region holding controls. */
    public static function statusbar(array $children, ?string $id = null): Element
    {
        return new Element('statusbar', ['id' => $id], $children);
    }

    /** Title bar accessory (native 'titlebar_accessory'): a right-aligned title region. */
    public static function titlebarAccessory(array $children, ?string $id = null): Element
    {
        return new Element('titlebar', ['id' => $id], $children);
    }

    /**
     * WebView (native 'webview'): an embedded browser surface. The canvas host
     * has no web engine, so this renders as a labelled placeholder; the url/src
     * props are carried so a real native backend can wire up the engine.
     */
    public static function webview(?string $url = null, ?string $id = null): Element
    {
        return new Element('webview', ['url' => $url ?? '', 'id' => $id]);
    }

    /**
     * GPU surface (native 'gpu_surface'): a native-accelerated drawing surface.
     * Our host already paints through Cairo/GPU, so this renders as a labelled
     * placeholder carrying width/height for a real native backend to honour.
     */
    public static function gpuSurface(int $width = 200, int $height = 120, ?string $id = null): Element
    {
        return new Element('gpusurface', ['width' => $width, 'height' => $height, 'id' => $id]);
    }

    /**
     * Free-form drawing surface (native has no direct equivalent; a Canvas API
     * for bespoke visuals). $draw is invoked as ($cr, $x, $y, $w, $h, $backend)
     * during paint, so users can draw anything Cairo supports without a native
     * widget. The closure is kept in element props (in-process only) and is
     * excluded from the automation snapshot.
     */
    public static function canvas(callable $draw, ?string $id = null): Element
    {
        return new Element('custom', ['draw' => $draw, 'id' => $id ?? ('canvas-' . (++self::$canvasSeq))], []);
    }

    /** Grid layout: children flow left-to-right, wrapping every $columns. */
    public static function grid(array $children, int $columns = 2, ?string $id = null): Element
    {
        return new Element('grid', ['columns' => $columns, 'id' => $id ?? ('grid-' . (++self::$autoSeq))], $children);
    }

    /** Absolute container: children are placed by Ui::positioned offsets. */
    public static function absolute(array $children, ?string $id = null): Element
    {
        return new Element('absolute', ['id' => $id ?? ('abs-' . (++self::$autoSeq))], $children);
    }

    /**
     * Pin a single child at an absolute offset within an Ui::absolute() parent.
     * Omitted edges default to 0; omitted width/height fall back to the parent's.
     */
    public static function positioned(Element $child, ?int $left = null, ?int $top = null, ?int $width = null, ?int $height = null, ?string $id = null): Element
    {
        return new Element('positioned', [
            'left' => $left,
            'top' => $top,
            'width' => $width,
            'height' => $height,
            'id' => $id ?? ('pos-' . (++self::$autoSeq)),
        ], [$child]);
    }

    /** Merge extra props into a cloned element (e.g. set flex `grow`). */
    public static function with(Element $el, array $props): Element
    {
        return new Element($el->type, [...$el->props, ...$props], $el->children);
    }

    /** Mark an element to grow and absorb leftover space along its container axis. */
    public static function grow(Element $el, float $amount = 1): Element
    {
        return self::with($el, ['grow' => $amount]);
    }

    /**
     * Attach one or more animation specs to an element. Each spec interpolates
     * a key (`opacity`|`x`|`y`|`scale`) from `from` to `to` over `duration` ms
     * (after `delay` ms) using an `easing` curve. The backend advances a clock
     * and interpolates on every paint — no event loop code required.
     */
    public static function animate(Element $el, array $specs): Element
    {
        if ($el->prop('id') === null) {
            $el = self::with($el, ['id' => 'anim-' . (++self::$animSeq)]);
        }
        return self::with($el, ['anim' => $specs]);
    }

    /** Convenience: fade an element in from fully transparent over $durationMs. */
    public static function fadeIn(Element $el, int $durationMs = 300, int $delayMs = 0): Element
    {
        return self::animate($el, [
            ['key' => 'opacity', 'from' => 0.0, 'to' => 1.0, 'duration' => $durationMs, 'delay' => $delayMs, 'easing' => 'easeOut'],
        ]);
    }

    /**
     * Attach a right-click context menu to an element. Items are
     * [['title' => string, 'msg' => string], ...]; selecting one dispatches $msg.
     */
    public static function contextMenu(Element $el, array $items): Element
    {
        return self::with($el, ['contextMenu' => $items]);
    }

    /** Tag an element as a gesture target; $onGesture fires with the gesture type. */
    public static function gesture(Element $el, string $type, ?string $onGesture = null): Element
    {
        return self::with($el, ['gesture' => $type, 'onGesture' => $onGesture]);
    }

    /**
     * Responsive breakpoint for the current window width: 'sm' (<480),
     * 'md' (<900), or 'lg'. Views branch on this to restructure themselves.
     */
    public static function breakpoint(int $width): string
    {
        if ($width < 480) {
            return 'sm';
        }
        if ($width < 900) {
            return 'md';
        }
        return 'lg';
    }

    /**
     * Attach accessibility metadata to an element: an explicit semantic $role
     * (overrides the widget type), an accessible $label (name), and an optional
     * $description (aria-description). Surfaced in the automation snapshot.
     */
    public static function accessible(
        Element $el,
        ?string $role = null,
        ?string $label = null,
        ?string $description = null,
    ): Element {
        $props = [];
        if ($role !== null) {
            $props['role'] = $role;
        }
        if ($label !== null) {
            $props['label'] = $label;
        }
        if ($description !== null) {
            $props['description'] = $description;
        }
        return self::with($el, $props);
    }

    // ---- Phase 3: widgets that native has but ui3 was missing ----

    public static function tabs(array $tabs, int $selected = 0, ?string $onSelect = null, ?string $id = null): Element
    {
        // $tabs: list of Ui::tabPage()
        return new Element('tabs', ['tabs' => $tabs, 'selected' => $selected, 'onSelect' => $onSelect, 'id' => $id ?? ('tabs-' . (++self::$autoSeq))], $tabs);
    }

    public static function tabPage(string $title, array $children, ?string $id = null): Element
    {
        return new Element('tab_page', ['title' => $title, 'id' => $id ?? ('tp-' . (++self::$autoSeq))], $children);
    }

    public static function card(string $title, array $children, ?string $id = null): Element
    {
        return new Element('card', ['title' => $title, 'id' => $id ?? ('card-' . (++self::$autoSeq))], $children);
    }

    public static function alert(string $severity, string $message, ?string $id = null): Element
    {
        return new Element('alert', ['severity' => $severity, 'text' => $message, 'id' => $id ?? ('alert-' . (++self::$autoSeq))]);
    }

    public static function accordion(array $sections, ?string $id = null): Element
    {
        // $sections: [['title'=>string, 'children'=>Element[]], ...]
        return new Element('accordion', ['sections' => $sections, 'id' => $id ?? ('acc-' . (++self::$autoSeq))]);
    }

    public static function dialog(string $title, array $children, array $actions = [], ?string $id = null): Element
    {
        return new Element('dialog', ['title' => $title, 'actions' => $actions, 'id' => $id ?? ('dlg-' . (++self::$autoSeq))], $children);
    }

    public static function sheet(string $title, array $children, ?string $id = null): Element
    {
        return new Element('sheet', ['title' => $title, 'id' => $id ?? ('sheet-' . (++self::$autoSeq))], $children);
    }

    public static function drawer(string $side, array $children, ?string $id = null): Element
    {
        return new Element('drawer', ['side' => $side, 'id' => $id ?? ('drw-' . (++self::$autoSeq))], $children);
    }

    public static function scrollView(array $children, int $offset = 0, ?string $id = null): Element
    {
        return new Element('scroll', ['offset' => $offset, 'id' => $id ?? ('scr-' . (++self::$autoSeq))], $children);
    }

    public static function table(array $columns, array $rows, ?string $id = null): Element
    {
        // $columns: [string], $rows: [[]string]
        return new Element('table', ['columns' => $columns, 'rows' => $rows, 'id' => $id ?? ('tbl-' . (++self::$autoSeq))]);
    }

    public static function comboBox(array $options, int $selected = 0, ?string $onSelect = null, ?string $id = null): Element
    {
        return new Element('combobox', ['options' => $options, 'value' => $selected, 'onSelect' => $onSelect, 'id' => $id ?? ('cmb-' . (++self::$autoSeq))]);
    }

    public static function dropDown(array $options, ?string $onSelect = null, ?string $id = null): Element
    {
        return new Element('dropdown', ['options' => $options, 'onSelect' => $onSelect, 'id' => $id ?? ('dd-' . (++self::$autoSeq))]);
    }

    public static function menu(array $items, ?string $id = null): Element
    {
        // $items: list of Ui::menuItem()
        return new Element('menu', ['items' => $items, 'id' => $id ?? ('menu-' . (++self::$autoSeq))], $items);
    }

    public static function menuItem(string $title, ?string $onSelect = null, ?string $id = null): Element
    {
        return new Element('menu_item', ['title' => $title, 'onSelect' => $onSelect, 'id' => $id ?? ('mi-' . (++self::$autoSeq))]);
    }

    public static function tree(array $nodes, ?string $id = null): Element
    {
        return new Element('tree', ['nodes' => $nodes, 'id' => $id ?? ('tree-' . (++self::$autoSeq))], $nodes);
    }

    public static function treeNode(string $title, array $children = [], bool $expanded = false, ?string $id = null): Element
    {
        return new Element('tree_node', ['title' => $title, 'expanded' => $expanded, 'id' => $id ?? ('tn-' . (++self::$autoSeq))], $children);
    }

    public static function chart(array $data, string $kind = 'bar', ?string $id = null): Element
    {
        // $data: list of numbers (bar) or [x,y] pairs (line)
        return new Element('chart', ['data' => $data, 'kind' => $kind, 'id' => $id ?? ('chart-' . (++self::$autoSeq))]);
    }

    public static function tooltip(string $text, ?string $id = null): Element
    {
        return new Element('tooltip', ['text' => $text, 'id' => $id ?? ('tip-' . (++self::$autoSeq))]);
    }

    public static function badge(string $text, ?string $id = null): Element
    {
        return new Element('badge', ['text' => $text, 'id' => $id ?? ('badge-' . (++self::$autoSeq))]);
    }

    public static function avatar(string $name, ?string $id = null): Element
    {
        return new Element('avatar', ['text' => $name, 'id' => $id ?? ('av-' . (++self::$autoSeq))]);
    }

    public static function skeleton(int $lines = 3, ?string $id = null): Element
    {
        return new Element('skeleton', ['lines' => $lines, 'id' => $id ?? ('sk-' . (++self::$autoSeq))]);
    }

    public static function spinner(?string $id = null): Element
    {
        return new Element('spinner', ['id' => $id ?? ('spin-' . (++self::$autoSeq))]);
    }

    public static function switchControl(bool $on, ?string $onChange = null, ?string $id = null): Element
    {
        return new Element('switch', ['checked' => $on, 'onChange' => $onChange, 'id' => $id ?? ('sw-' . (++self::$autoSeq))]);
    }

    public static function richText(array $spans, ?string $id = null): Element
    {
        // $spans: [['text'=>string, 'bold'?=>bool, 'color'?=>string], ...]
        return new Element('richtext', ['spans' => $spans, 'id' => $id ?? ('rt-' . (++self::$autoSeq))]);
    }

    public static function breadcrumb(array $crumbs, ?string $id = null): Element
    {
        return new Element('breadcrumb', ['crumbs' => $crumbs, 'id' => $id ?? ('bc-' . (++self::$autoSeq))]);
    }

    public static function pagination(int $page, int $pages, ?string $onSelect = null, ?string $id = null): Element
    {
        return new Element('pagination', ['page' => $page, 'pages' => $pages, 'onSelect' => $onSelect, 'id' => $id ?? ('pg-' . (++self::$autoSeq))]);
    }

    public static function buttonGroup(array $buttons, ?string $id = null): Element
    {
        // $buttons: list of Ui::button()
        return new Element('button_group', ['buttons' => $buttons, 'id' => $id ?? ('bg-' . (++self::$autoSeq))], $buttons);
    }
}
