<?php
declare(strict_types=1);

namespace Yangweijie\Ui3;

/**
 * Declarative UI builders (mirrors native's Ui.* helpers). Each returns an
 * immutable Element describing what to render; state lives only in the model.
 */
final class Ui
{
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
    public static function input(?string $value = null, ?string $placeholder = null, ?string $onInput = null, ?string $id = null): Element
    {
        return new Element('input', [
            'text' => $value ?? '',
            'placeholder' => $placeholder ?? '',
            'onInput' => $onInput,
            'id' => $id,
        ]);
    }

    /** Multi-line editable text area. Emits $onInput with the new string. */
    public static function textarea(?string $value = null, ?string $placeholder = null, ?string $onInput = null, ?string $id = null): Element
    {
        return new Element('textarea', [
            'text' => $value ?? '',
            'placeholder' => $placeholder ?? '',
            'onInput' => $onInput,
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
    public static function searchField(?string $value = null, ?string $placeholder = null, ?string $onInput = null, ?string $id = null): Element
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
}
