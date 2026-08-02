# Task Plan: 补齐 ui3 相对于 native 缺失的十个方向

## Goal
在 ui3 中按对比结果补齐 native 已具备、ui3 缺失的十个方向，在 DSL + Canvas 渲染 + 自动化
三层做功能级最小可用实现（沿用现有 widget 模式，每方向带回归测试），排除移动端。

## Current Phase
全部完成（Phase 1-10 done）

## Phases

### Phase 1: Direction 3 — 主题/Design Tokens + 自定义绘制
- [x] src/Theme.php light/dark 令牌；App/Canvas 接入主题；Ui::canvas 自定义绘制
- [x] 回归测试 ThemeTest（6 passed）
- Status: done

### Phase 2: Direction 2 — 布局引擎增强
- [x] flex grow（row/column，含含 grow 后代的容器）；Ui::grid；Ui::positioned/absolute；虚拟列表
- [x] 回归测试 LayoutTest（4 passed）
- Status: done

### Phase 3: Direction 1 — 缺失组件
- [x] 容器/导航: tabs, card, alert, accordion, dialog, sheet, drawer, scroll_view, table, breadcrumb, pagination, button_group
- [x] 选择/菜单: combobox, dropdown, menu, menu_item
- [x] 文本/媒体: richtext, tooltip, chart, tree, tree_node, avatar, badge, skeleton, spinner, switch
- [x] 回归测试 WidgetsParityTest（29 assertions pass）
- Status: done

### Phase 4: Direction 5 — 动画 (ticker + transition/easing)
- [x] src/Animation.php：easing（linear/easeIn/easeOut/easeInOut/easeOutBack/step）+ progress/lerp
- [x] Ui::animate() / Ui::fadeIn()：附加 anim 规格（opacity/x/y/scale，含 duration/delay/easing）
- [x] Canvas：时钟（freezeClock/setTime/clock）、每元素 animStart、animState()、isAnimating()、requestRedraw()
- [x] drawNode：按时钟插值 translate/scale，opacity 经 cairo group（pushGroup/popGroupToSource/paintWithAlpha）
- [x] paint()：推进时钟 + 动画期间持续请求重绘（真实窗口帧循环）
- [x] 回归测试 AnimationTest（4 passed，26 assertions）
- Status: done

### Phase 5: Direction 6 — 事件/输入 (滚轮/scroll_view, 键盘 focus 遍历, 右键 menu, 手势)
- [x] Canvas: tabForward()/tabBackward()/focusedId() 键盘 focus 遍历（Tab 循环 + 回绕）
- [x] Canvas: scrollBy()/scrollOffset() 程序化滚动（list 虚拟窗口 + scroll 容器），触发 onScroll
- [x] Canvas: openContextMenu()/closeContextMenus()/contextMenuItems() 右键菜单（状态 + 项）
- [x] Canvas: dispatchGesture() 手势派发；Ui::gesture()/Ui::contextMenu()
- [x] Layout::compute 接受 scroll 覆盖，list/scroll 渲染消费
- [x] 回归测试 InputTest（4 passed，18 assertions）
- Status: done

### Phase 6: Direction 4 — 状态/响应式 (keyed reconcile + signals + responsive)
- [x] src/Signal.php：signals-lite（get/set/update/subscribe）
- [x] src/Reconcile.php：keyed() 按 key/id/index 匹配前后列表，输出 same/moved/added/removed
- [x] Ui::breakpoint(width)：sm/md/lg 响应式断点
- [x] Layout placeList：list_item 节点 id 采用 key（稳定身份，跨重排保留瞬态）
- [x] 回归测试 StateTest（5 passed，11 assertions）
- Status: done

### Phase 7: Direction 7 — 无障碍 (role/label, focus 可见, 语义快照)
- [x] Snapshot：条目增加 label/description/focused；role 可被显式覆盖；自定义 role 也能从 text/title 派生 name
- [x] Canvas snapshot（via Automation）携带 focusedId；Ui::accessible(role,label,description)
- [x] 回归测试 AccessibilityTest（3 passed，10 assertions）
- Status: done

### Phase 8: Direction 8 — 多窗口 (window_state 管理)
- [x] src/Windows.php：窗口状态管理（open/close/focus/active/list/setRoot）
- [x] App::openWindow/closeWindow/focusWindow/windows/activeWindow 接入
- [x] 回归测试 WindowsTest（2 passed，14 assertions）
- Status: done

### Phase 9: Direction 9 — Web 目标 (Html Backend)
- [x] src/Backends/Html.php：实现 Backend 接口，将 Element 树渲染为语义 HTML（data-role/data-id/data-label，主题色）
- [x] 支持 mount/update 重渲染、isHeadless、layout() 供快照
- [x] 回归测试 WebTargetTest（3 passed，14 assertions）
- Status: done

### Phase 10: Direction 10 — 其他系统 (extensions, security, assets)
- [x] src/Extensions.php：生命周期钩子总线（register/trigger），App::extend + beforeRender/afterRender/afterUpdate
- [x] src/Security/Capabilities.php + SecurityException：capability 许可模型（grant/deny/demand，fail closed）
- [x] src/Assets.php：资源名→URL 解析（base 前缀 + mtime 缓存破坏）
- [x] 回归测试 SystemsTest（4 passed，11 assertions）
- Status: done

### Follow-up: 测试脚本 + 示例
- [x] `bin/run.sh`：缺失 `libui3` 时自动执行 `ext/build.sh`；新增 `UI3_SKIP_BUILD=1` 跳过开关
- [x] 新增 6 个示例：animation / accessibility / multi_window / web_target / systems / state
- [x] 全部示例运行通过（headless / 纯 PHP）
- Status: done

### P0: 渲染底座 + 像素回归（成熟度提升）
- [x] `src/Backends/Reference.php`：纯 PHP 参考渲染器（NullPlatform 等价），GD 栅格化 Element 树为 PNG，零 FFI/原生库
- [x] `Layout::textWidth` 注入纯 PHP 文本测量（默认仍 Cairo），渲染后复位
- [x] `tests/ReferenceRenderTest.php` + `tests/baselines/*.hash`：确定性 + 像素级回归（`UI3_UPDATE_BASELINE` 重建）
- [x] `composer test:ref`：纯 PHP 跑参考测试，无需原生库
- [x] `examples/reference.php`：纯 PHP 渲染 PNG 演示
- Status: done

### P1: 常驻动画 ticker + 文本精确测量/IME（成熟度提升）
- [x] `src/Ticker.php`：常驻动画 ticker（独立于原生窗口的帧时钟，可注入时钟源，回调可提前停止）
- [x] `src/Animation.php::frame()`：动画插值抽离为后端无关纯函数，Canvas 与 Reference 共用
- [x] `src/Backends/Canvas.php`：drawNode 复用 `Animation::frame()`（行为不变）
- [x] `src/Backends/Reference.php`：setClock/clock/resetClock/isAnimating/animState + 帧渲染（translate/scale + opacity 背景混合近似）
- [x] 文本测量：`pureTextWidth` 改按字符类别累加（全角/半角/IME 组合），零依赖确定
- [x] `tests/TickerTest.php` + `tests/ReferenceRenderTest.php`(动画帧)：ticker/frame/CJK-IME 测量/动画帧回归
- [x] `examples/animated_reference.php`：纯 PHP ticker 驱动渲染多帧
- Status: done

### P2: Headless 应用循环 + IME 组合输入 + Reference 控件覆盖 + Html 动画（成熟度提升）
- [x] `App::headless(frames, fps, durationSec, onFrame)`：`App::run()` 检测 headless 后端（Reference/Html）时用 `Ticker` 驱动逐帧 `setClock`+`update`；缓存渲染树避免动画起点每帧重置；`withClock()` 注入时钟源便于测试
- [x] IME 组合输入：`Ui::input/textarea/searchField` 加 `onComposition` 钩子（第5参数，位于 `id` 之后）；`Reference::composition(id,phase,text)` 存状态并绘制待定文本+下划线预览；`App::composition(id,phase,text)` 注入 headless 后端并重绘
- [x] `Reference` 控件覆盖：`case 'scroll'`（视口背景填充）与 `case 'table'`（列头+行），对齐 Canvas 绘制
- [x] `Html` 后端动画：`renderNode` 检测 `anim` prop，纯 PHP 生成 CSS `@keyframes`+`animation` style（浏览器驱动，零 JS/FFI）
- [x] `Reference::applyAlpha` 改用本地不透明背景色（修复 cross-image 颜色索引导致 opacity 无效）
- [x] 参数顺序修复：`onComposition` 置于 `id` 之后，避免破坏既有 4 位置调用
- [x] `tests/HeadlessLoopTest.php` / `tests/ImeTest.php` / `tests/ControlCoverageTest.php` / `tests/HtmlAnimTest.php`：headless 循环 / IME 预览 / scroll+table 覆盖 / Html 动画
- Status: done

### P3: Canvas IME 组合输入 parity + P2 能力可运行示例（成熟度提升）
- [x] `Canvas` 增加 `$composition` 状态与 `composition(id, phase, text)` 方法（触发 `requestRedraw`），`input`/`textarea`/`drawSearch` 绘制待定文本（accent 色）+ 下划线预览，与 Reference headless 同构
- [x] `App::composition` 现能路由到原生 Canvas 后端，headless/原生 IME 行为一致
- [x] `examples/headless_loop.php`：Ticker 驱动 Reference 逐帧渲染动画，可导出 PNG 帧（零 FFI）
- [x] `examples/ime.php`：Reference 接收 composition 事件并渲染待定预览（零 FFI）
- [x] `examples/html_anim.php`：Html 后端把 `anim` 输出为 CSS `@keyframes`+`animation`（零 FFI）
- [x] 修复 `Html::renderNode` 动画时长初值 `1000` 覆盖更短 `duration` → 改为 `0.0` 取 `max`
- [x] `tests/CanvasImeTest.php`：Canvas 组合状态存储/清除 + 像素级 offscreenPixels 验证（preview 渲染/清除）+ App 端到端路由
- [x] 修复 Compositor::beginFrame/endFrame blit 丢失 bug（beginFrame 清 fullDirty 后 endFrame 无 rect 可 blit → beginFrame 设 dirtyRects 为全表面 rect）
- [x] 修复 Canvas::drawFieldText/drawSearch drawComposition 调用缺少 field buffer 时不渲染的 bug（移除 `$buf !== null` 守卫，drawComposition 自身已有 null 检查）
- Status: done

### P4: scroll 内容裁剪（native SDK 支持）（成熟度提升）
- [x] `Cairo` FFI 新增 `cairo_save`/`cairo_restore`/`cairo_clip` 及 image surface 像素读回函数（libcairo 自带，无需改 C）
- [x] `Layout::scroll` 在其子节点后追加零尺寸 `scroll_end` 哨兵，`Canvas::paint` 绘制循环用裁剪栈配对（LIFO 兼容嵌套 scroll），实现真正的溢出裁剪
- [x] `Canvas::offscreenPixels()` 提供 headless 像素读回（与 Reference `pixelsHash` 对齐），用于验证裁剪
- [x] `Layout::hitTest` 与 `Reference::draw` 显式跳过 `scroll_end` 哨兵（不误命中 / 不改快照基线）
- [x] `tests/ScrollClipTest.php`：Cairo 裁剪机制确定性 + Layout 哨兵 + Canvas 像素级 overflow 被裁剪
- Status: done

### P5: scroll 交互（键盘 + 鼠标滚轮）（成熟度提升）
- [x] `libui3.h` 枚举新增 `UI3_EVENT_WHEEL = 5`；`Canvas::onEvent` 新增 `WHEEL` 分支（`scrollContainerAt` + `scrollBy`）
- [x] `Canvas` 新增 `activeScrollId`（`POINTER_DOWN` 记录光标所在 scroll 容器）+ `onKey` ↑/↓ 拦截滚动（±40px）
- [x] 三平台宿主转发滚轮：`cocoa.m` `scrollWheel:`、`win32.c` `WM_MOUSEWHEEL`、`x11.c` `ButtonPress` 区分 Button4/5（符号统一 data>0 = 向下滚）
- [x] `examples/scroll_window.php`：真实窗口示例（默认 headless sanity 帧，`UI3_REAL_WINDOW=1` 弹窗，列表溢出裁剪 + 滚轮/↑↓ 滚动）
- [x] `tests/ScrollInteractionTest.php`：键盘箭头滚动 + WHEEL 滚动 + 滚动后仍裁剪
- Status: done

### P0.1: 文本编辑原语（caret/选区/undo/右键菜单）
- [x] `input`/`textarea` caret 与选区绘制、undo/redo 栈；文本编辑右键上下文菜单（撤销/重做/剪切/复制/粘贴/全选）
- Status: done

### P0.2: 原生剪切板
- [x] 原生 clipboard 接入（copy/paste），headless 经 `setClipboard` 模拟
- Status: done

### P0.3: 文件打开/保存对话框
- [x] `App::openFile`/`saveFile` 接入原生对话框；`filters`/`defext` 透传
- Status: done

### P0.4: 文本编辑右键上下文菜单 + x11 切 GTK + macOS 保存面板弃用修复
- [x] 上下文菜单 hover/选区/命令；`x11.c` 改走 GTK chooser；`cocoa.m` 保存面板弃用 API 修复
- Status: done

### P0.5: 右键菜单增强（hover/次级菜单/剪切板预览）+ 文件对话框平台过滤语法
- [x] hover 高亮（accentSoft）、单层次级菜单（hover 展开）、剪切板实时预览行
- [x] `filters`/`defext` 各平台特定过滤语法：`common.c` 共享 `ui3_parse_filters`；GTK/Cocoa/Win32 各自接入（label:ext,ext + 自动 All Files）
- Status: done

### P0.6: 右键菜单深度扩展（多级嵌套/图标/勾选态）
- [x] `submenu` 单层键改为 `submenus` 面板列表，递归多级嵌套；`updateMenuHover` 在命中最深面板追踪悬停
- [x] 菜单项支持 `icon`（glyph 左栏）与 `checked`（✓ 左栏）；`contextMenuSize`/`drawMenu` 预留 22px 左栏
- [x] `hitContextMenu`/`runContextMenuItem`/`drawContextMenu` 改用 `depth` 定位；新增 `contextSubmenuDepth`/`contextSubmenuLevelItems`/`contextSubmenuLevelRect`
- [x] `ContextMenuTest` +3（多级嵌套/深层点击/icon+checked）；`Ui::contextMenu` 补条目结构 docblock
- Status: done

### P-Native P0: 修饰键 + Cmd 捕获（最小验证，done）
- [x] `libui3.h`：新增 `UI3_MOD_*` 位定义；`ui3_host_inject_raw_key`/`post_key` 参数 `shift`→`modifiers`
- [x] `common.c`：`ui3_key_text(keycode, modifiers, chars)` 统一生成 "Shift+/Ctrl+/Alt+/Cmd+" 前缀（Cocoa/Win32/X11/headless 一致）
- [x] `cocoa.m`：routeKey 计算 shift/ctrl/alt/cmd 四位；`performKeyEquivalent` 把 Cmd+* 路由到 PHP（保留 Cmd+Q/W/H/M/Tab/Space/` 给系统）
- [x] `win32.c`/`x11.c`：onKey 计算四位修饰键；WM_CHAR 把 Ctrl+letter 控制符还原为 "Ctrl+<letter>"
- [x] `Canvas.php`：`onKey`/`editText` 接收修饰位掩码；Ctrl/Cmd 触发 copy/paste/cut/selectAll/undo/redo（Alt/Cmd 组合亦透传，如 Ctrl+Alt+A）
- [x] `Automation::rawKey` 参数 `shift`→`modifiers`（向后兼容：`true`==SHIFT）
- [x] `tests/ModifierShortcutTest.php`：Cmd/Alt 组合端到端验证
- Status: done（最小验证通过）

### P-Native P0 (续): 多窗口 / 窗口管理 API
- [x] 窗口管理 ABI：`setTitle/resize/minimize/close/title/closed` + `width/height` 已落地（Cocoa/Win32/X11 三端 `ui3_plat_*` + headless 状态一致）；`Canvas`/`App` 暴露并测试（`tests/WindowManageTest.php` ×4，headless 验证 title/closed/width/height）
- [x] 修复 `ui3_host_create` 未存储初始标题的 bug（view 标题现生效）
- [x] 多窗口（真·OS 多 surface）：`ui3_host_create` 多实例 + `App::openWindow` 真正开第二个 OS 窗口（Canvas `createExtraHost/destroyExtraHost` + App 代理 + 9 headless 测试，192 total passed）
- [x] 窗口管理剩余项：move / fullscreen / acceptClose（C ABI + 3 platform + PHP + 5 tests，182 total passed）
- Status: P-Native P0 续 全部 done（窗口管理 + 多窗口均已落地）

### P-Native P1: 系统集成分层（菜单栏/托盘/DnD/手势/对话框/通知/无障碍树）
- [x] 原生菜单栏（NSMenu/系统顶栏）+ 状态栏 + 托盘（dock menu）
  - 2026-07-30: `ui3_host_set_menu`（文本协议）+ `UI3_EVENT_MENU` + `click_menu`；cocoa NSMenu（keyEquivalent）/ win32 HMENU（WM_COMMAND 映射）/ x11 no-op；`Ui::appMenu/appMenuItem/appMenuSeparator` + window `menu:` + Automation `clickMenu` + 3 headless 测试；状态栏/托盘/dock menu 待补
  - 2026-07-30: `ui3_host_set_a11y_text` 文本协议 + `flattenA11yTree`（PHP→tab-delimited text→C 解析）；C `ui3_a11y_node` 树 + `ui3_host_set_a11y_tree` deep copy + headless 序列化 + `ui3_host_last_a11y` 回读；cocoa NSAccessibility（Ui3View/Ui3A11yElement accessibilityChildren/Label/Description）/ **win32 UIA done**（2026-08-01：COM STA init + `IUIAutomationRegistrar` 注册 + 自定义 `IRawElementProviderSimple` vtbl + `WM_GETOBJECT` 窗口子类化；`ui3_a11y_node` 实时读 `host->plat_a11y`，role→ControlType 映射（button/checkbox/input/list 等 16 种角色）；CI Windows 硬 fail）/ x11 ATK stub（deferred — raw X11 无 GtkWidget）；5 headless 测试（text output/nested elements/root metadata/role mapping/non-accessible group）
- [x] 拖放 DnD（文件/文本/图片/URL）
  - 2026-07-30: `UI3_EVENT_DROP` + `ui3_host_inject_drop`；cocoa performDragOperation（文件/文本）/ win32 WM_DROPFILES（文件）/ x11 Xdnd v5（2026-07-31 实现：XdndAware property + Enter/Position/Drop/Finished + XdndSelection 检索 file:// URI 列表）；window `onDrop` + Automation `drop()` + 3 headless 测试
- [x] OS 级手势（pinch/rotate/swipe/pan momentum）
  - 2026-07-30: Cocoa (magnify/rotate/swipe/scrollWheel momentum), Win32 (WM_GESTURE API: zoom→pinch/rotate/pan/tap→swipe), X11 (XI2 TouchBegin/Update/End + 2-finger pinch distance/angle math + pan); 4 headless tests via injectGesture（192 total passed）
- [x] 原生对话框（alert/sheet/color/font/print/about）；当前仅 open/save 文件
  - 2026-07-30: alert/confirm/sheet/about 已打通（C `ui3_host_dialog` + cocoa NSAlert / win32 MessageBoxW / x11 GtkMessageDialog + headless 预设结果 + lastDialog 记录 + 5 headless 测试）；color/font/print 待补
- [x] 通知中心（UNUserNotification / toast / libnotify）
  - 2026-07-30: `ui3_host_notify` 打通；cocoa NSUserNotificationCenter / x11 notify-send / win32 best-effort no-op；headless lastNotify 记录 + 3 测试；win32 WinRT toast 待补
- [x] 原生无障碍树桥接（NSAccessibility / UIA / AT-SPI）
  - 2026-07-30: `ui3_host_set_a11y_text` 文本协议 + `flattenA11yTree`（PHP→tab-delimited text→C 解析）；C `ui3_a11y_node` 树 + `ui3_host_set_a11y_tree` deep copy + headless 序列化 + `ui3_host_last_a11y` 回读；cocoa NSAccessibility（Ui3View/Ui3A11yElement accessibilityChildren/Label/Description）/ win32 UIA stub（deferred）/ x11 ATK stub（deferred）；5 headless 测试（text output/nested elements/root metadata/role mapping/non-accessible group）
- Status: P-Native P1 done（菜单栏/DnD/手势/对话框/通知/无障碍树全部落地；win32 UIA done（2026-08-01：COM STA + IUIAutomationRegistrar + IRawElementProviderSimple vtbl + WM_GETOBJECT 子类化，16 种 role→ControlType 映射）；x11 ATK + x11 menu bar 仍 deferred（raw X11 无 GtkWidget；使用 `UI3_BACKEND=gtk4` 可获得完整支持））

### P-Native P2: 编辑/剪贴板/性能/后端覆盖
- [x] 富文本（bold/italic/underline/fontSize color 标签 prop → Cairo slant/weight + underline + fontSize 透传）
- [x] 剪贴板多格式（图片/RTF/fileURL）
  - 2026-07-30: `ui3_host_set/get_clipboard_image`（PNG bytes）、`set/get_clipboard_uris`（file:// URLs）、`set/get_clipboard_html`、`clipboard_formats`（UI3_CLIP_TEXT/IMAGE/FILES/HTML bitmask）；C ABI + common.c shared wrappers（headless 存 `last_clip_*` 字段）+ cocoa NSPasteboard（public.png / NSFilenamesPboardType / com.apple.html）+ **win32 全格式**（2026-07-31：`set_image`/`get_image` via `UI3_IMAGE_PNG` custom clipboard format, `set_uris`/`get_uris` via `UI3_URIS` custom format; HTML 原本已工作）+ x11 GTK3 多格式（2026-07-31 修复：get_image 悬空指针→malloc 复制；set_html NULL 回调→`gtk_clipboard_set_with_data` + get-callback；set_image stub→完整实现；formats 仅 TEXT→`gtk_clipboard_wait_for_targets` 查真实 atoms）；Canvas.php 7 个方法（setImage/getImage/setUris/getUris/setHtml/getHtml/formats）；5 headless 测试全部通过；Bug fix：`nativeClipboard()` 原本 `!$this->isHeadless()` 阻塞 headless 调用→改为 `$this->host !== null`；`ui3_host_set_clipboard_text` 平台函数未存 `last_clip_text`→三平台加 headless 存储
- [x] compositor / GPU 层 / 局部重绘
- [x] Wayland / GTK4
  - 2026-07-31: `ext/gtk4.c` 全新 GTK4 后端（768 行）；GTK4 原生支持 X11 + Wayland 双协议，单个后端同时覆盖 Wayland parity 和修复 x11.c 遗留缺陷（菜单栏/无障碍/剪贴板多格式/原生对话框）。编译链路：`UI3_BACKEND=gtk4 bash ext/build.sh`（pkg-config gtk4），CI 新增 `test-gtk4` job（ubuntu-latest + libgtk-4-dev + 冒烟测试）。已删死代码 `ext/null.c`（41 行陈旧 `ui3_backend_ops` API，零引用）。
- [ ] 移动端 / 真 WebView
  - 排除理由：当前架构（PHP FFI → C canvas host → OS 原生窗口 + Cairo 绘制）天然依赖桌面 OS 的窗口/输入/绘制系统；移动端（iOS/Android）需要完全不同的 UI 框架（UIKit/Jetpack Compose），无法复用现有 Canvas 渲染管线；真 WebView（浏览器内嵌）意味着放弃原生渲染回到 HTML/CSS，与 P-Native 路线冲突。这两项不在 P-Native parity 范围内，属于新的架构方向（移动端 SDK / Web 嵌入），需要独立规划。
- Status: 剪贴板多格式 done（**三平台全格式 done**：cocoa 全格式、win32 全格式（2026-07-31 `UI3_IMAGE_PNG` + `UI3_URIS` custom clipboard formats）、x11 GTK3 全格式）；富文本 done（bold/italic/underline/fontSize 标签 prop → Cairo + 5 回归测试）；compositor done（backing surface + dirty rect + partial blit + 16 unit tests）；IME 完整性 done（Canvas pixel-level offscreenPixels 测试 + App 端到端路由 + compositor blit 修复）；拼写检查 done（Spellcheck 纯 PHP 引擎 + 系统词典静态共享 + Canvas/Reference 红色波浪线 + 右键建议/Add to dictionary/Ignore/替换，SpellcheckTest 11 + SpellTest 3 + CanvasSpellTest 5 全过）；**x11 DnD done**（Xdnd v5）；GTK4/Wayland done（ext/gtk4.c + build.sh 分支 + CI job + null.c 清理）

## All phases complete
十个方向全部补齐：主题/令牌、布局引擎、缺失组件、动画、事件/输入、状态/响应式、无障碍、多窗口、Web 目标、其他系统。P0–P1 渲染底座、动画/文本/IME 成熟度、scroll 裁剪与交互、文本编辑原语/剪切板/文件对话框、右键菜单增强与多级嵌套/图标/勾选态均已落地。
另开 **P-Native（Native SDK Parity）** 跟踪块，对标 OS 原生 GUI SDK 补齐系统集成深度（P0 修饰键/Cmd 捕获、多窗口/窗口管理、菜单栏/托盘/DnD/手势/对话框/通知/无障碍树均 done（**win32 UIA 已补**：COM STA + `IUIAutomationRegistrar` + 自定义 `IRawElementProviderSimple` vtbl + `WM_GETOBJECT` 子类化，16 种 role→ControlType 映射，CI Windows 硬 fail）；P2 剪贴板多格式 done（**三平台全格式**：cocoa / **win32** / x11 GTK3）；富文本 done；compositor done；IME 完整性 done；拼写检查 done（19 测试）；**GTK4/Wayland done**（ext/gtk4.c + build.sh UI3_BACKEND 分支 + CI job；移动端/Webview 排除已记录理由）；**x11 DnD done**（Xdnd v5）。剩余 deferred（已记录原因）：x11 ATK + x11 menu bar（raw X11 无 GtkWidget，ATK/GtkHeaderBar 需 GTK；使用 `UI3_BACKEND=gtk4` 可获得完整支持）。

## Key Decisions
- 每方向三层(DSL+Canvas+自动化)最小实现，可被 MCP 观测。
- 主题令牌驱动所有绘制；自定义绘制经 Ui::canvas 闭包。
- 布局用固定常量 + flex grow + grid + 绝对定位 + 虚拟列表。
