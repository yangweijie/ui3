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
- [x] `tests/CanvasImeTest.php`：Canvas 组合状态存储/清除/end 幂等
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

## All phases complete
十个方向全部补齐：主题/令牌、布局引擎、缺失组件、动画、事件/输入、状态/响应式、无障碍、多窗口、Web 目标、其他系统。P0/P1 渲染底座与动画/文本成熟度已提升。

## Key Decisions
- 每方向三层(DSL+Canvas+自动化)最小实现，可被 MCP 观测。
- 主题令牌驱动所有绘制；自定义绘制经 Ui::canvas 闭包。
- 布局用固定常量 + flex grow + grid + 绝对定位 + 虚拟列表。
