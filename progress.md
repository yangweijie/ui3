# Progress Log

## Session: 2026-07-28

### Phase 1: Direction 3 — 主题/Design Tokens + 自定义绘制
- **Status:** done
- Actions taken:
  - 创建 src/Theme.php (light/dark 令牌)；App/Canvas 接入主题；Ui::canvas 自定义绘制
- Files: src/Theme.php, src/App.php, src/Backends/Canvas.php, src/Ui.php, tests/ThemeTest.php

### Phase 2: Direction 2 — 布局引擎增强
- **Status:** done
- Actions taken: flex grow；Ui::grid；Ui::positioned/absolute；虚拟列表
- Files: src/Canvas/Layout.php, src/Ui.php, tests/LayoutTest.php

### Phase 3: Direction 1 — 缺失组件
- **Status:** done
- Actions taken: ~28 个缺失 widget（容器/导航/选择/菜单/文本媒体），布局+渲染+快照可观测
- Files: src/Ui.php, src/Backends/Canvas.php, src/Canvas/Layout.php, tests/WidgetsParityTest.php

### Phase 4: Direction 5 — 动画 (ticker + transition/easing)
- **Status:** done
- Actions taken:
  - 新增 src/Animation.php（easing/progress/lerp）
  - Ui::animate()/Ui::fadeIn() 附加 anim 规格（opacity/x/y/scale + duration/delay/easing）
  - Canvas 时钟（freezeClock/setTime/clock）、animStart/animState()/isAnimating()/requestRedraw()
  - drawNode 按时钟插值 translate/scale，opacity 经 cairo group
  - paint() 推进时钟 + 动画期间持续请求重绘
  - 新增 Cairo pushGroup/popGroupToSource/paintWithAlpha
- Files created/modified:
  - src/Animation.php (created)
  - src/Ui.php (modified: animate/fadeIn + animSeq)
  - src/Backends/Canvas.php (modified: 时钟/animState/drawNode group)
  - src/FFI/Cairo.php (modified: group + paintWithAlpha)
  - tests/AnimationTest.php (created)
- Test Results: AnimationTest 4 passed / 26 assertions；全量 72 passed / 272 assertions / 1 skipped

### Phase 5: Direction 6 — 事件/输入
- **Status:** done
- Actions taken:
  - Canvas 暴露 tabForward/tabBackward/focusedId（Tab 焦点遍历 + 回绕）
  - Canvas scrollBy/scrollOffset（list 虚拟窗口 + scroll 容器，派发 onScroll）；Layout::compute 接受 scroll 覆盖
  - Canvas openContextMenu/closeContextMenus/contextMenuItems（右键菜单状态）
  - Canvas dispatchGesture；Ui::gesture/Ui::contextMenu
- Files: src/Backends/Canvas.php, src/Canvas/Layout.php, src/Ui.php, tests/InputTest.php
- Test Results: InputTest 4 passed / 18 assertions；全量 76 passed / 290 assertions / 1 skipped

### Phase 6: Direction 4 — 状态/响应式
- **Status:** done
- Actions taken:
  - src/Signal.php（signals-lite：get/set/update/subscribe）
  - src/Reconcile.php（keyed() 前后列表按 key/id/index 匹配，输出 same/moved/added/removed）
  - Ui::breakpoint(width) 响应式断点 sm/md/lg
  - Layout placeList：list_item 节点 id 采用 key（稳定身份，跨重排保留瞬态）
- Files: src/Signal.php, src/Reconcile.php, src/Ui.php, src/Canvas/Layout.php, tests/StateTest.php
- Test Results: StateTest 5 passed / 11 assertions

### Phase 7: Direction 7 — 无障碍
- **Status:** done
- Actions taken:
  - Snapshot 条目增加 label/description/focused；role 可被显式覆盖；自定义 role 从 text/title 派生 name
  - Automation::snapshot 携带 focusedId；Ui::accessible(role,label,description)
- Files: src/Automation/Snapshot.php, src/Automation/Automation.php, src/Ui.php, tests/AccessibilityTest.php
- Test Results: AccessibilityTest 3 passed / 10 assertions

### Phase 8: Direction 8 — 多窗口
- **Status:** done
- Actions taken:
  - src/Windows.php（open/close/focus/active/list/setRoot 窗口状态管理）
  - App::openWindow/closeWindow/focusWindow/windows/activeWindow 接入
- Files: src/Windows.php, src/App.php, tests/WindowsTest.php
- Test Results: WindowsTest 2 passed / 14 assertions

### Phase 9: Direction 9 — Web 目标
- **Status:** done
- Actions taken:
  - src/Backends/Html.php（实现 Backend 接口，Element 树渲染为语义 HTML，data-role/data-id/data-label + 主题色），mount/update 重渲染、isHeadless、layout()
- Files: src/Backends/Html.php, tests/WebTargetTest.php
- Test Results: WebTargetTest 3 passed / 14 assertions

### Phase 10: Direction 10 — 其他系统
- **Status:** done
- Actions taken:
  - src/Extensions.php（register/trigger 钩子总线）；App::extend + beforeRender/afterRender/afterUpdate
  - src/Security/{Capabilities,SecurityException}.php（capability 许可，demand fail closed）
  - src/Assets.php（资源名→URL，base 前缀 + mtime 缓存破坏）
- Files: src/Extensions.php, src/Security/Capabilities.php, src/Security/SecurityException.php, src/Assets.php, src/App.php, tests/SystemsTest.php
- Test Results: SystemsTest 4 passed / 11 assertions

### Follow-up: 测试脚本 + 示例
- **Status:** done
- Actions taken:
  - `bin/run.sh`：缺失 `libui3` 时自动执行 `ext/build.sh`；新增 `UI3_SKIP_BUILD=1` 跳过开关（供 CI 独立构建步骤）；保留库路径注入与用法提示。
  - 新增 6 个示例，演示补齐的能力：`animation.php`(Animation/Ui::fadeIn)、`accessibility.php`(Ui::accessible + 快照 role/label)、`multi_window.php`(Windows 状态)、`web_target.php`(Html 后端语义 HTML)、`systems.php`(Extensions/Capabilities/Assets)、`state.php`(Signal/Reconcile/breakpoint)。
  - 示例默认 headless，`UI3_REAL_WINDOW=1` 可选真实窗口；web_target/systems 为纯 PHP（不依赖 libui3）。
- Files: `bin/run.sh`, `examples/{animation,accessibility,multi_window,web_target,systems,state}.php`
- Verification: 6 个示例全部运行通过（headless 经 `bin/run.sh` / 纯 PHP），exit 0；`bash -n` 通过。

### P0: 渲染底座 + 像素回归（2026-07-28）
- **Status:** done
- Actions:
  - `src/Backends/Reference.php`（新增）：纯 PHP 参考渲染器（NullPlatform 等价物）。复用 `Canvas\Layout` 布局，注入纯 PHP 文本测量（`Layout::setTextWidth` 可注入，默认仍 Cairo），GD 栅格化 Element 树为 PNG，零 FFI / 零原生库。
  - `Layout::textWidth` 支持注入测量函数（`?callable`），渲染后复位，避免污染 Canvas 路径。
  - `tests/ReferenceRenderTest.php`（新增）：确定性 + 像素级回归；基线哈希 `tests/baselines/*.hash`，`UI3_UPDATE_BASELINE=1` 重建；含同树同像素 / 模型变化像素变化 / 三张基线对照。
  - `composer test:ref`：纯 PHP 跑参考测试，无需 `bin/run.sh` / FFI。
  - `examples/reference.php`：演示无头纯 PHP 渲染 PNG。
- Files: `src/Backends/Reference.php`, `src/Canvas/Layout.php`(注入点), `tests/ReferenceRenderTest.php`, `tests/baselines/*.hash`, `examples/reference.php`, `composer.json`(test:ref)
- Verification: `composer test:ref` 5 passed；篡改基线后正确失败（证明回归有效）；`composer test` 全量 98 passed / 355 assertions / 1 skipped，未破坏既有 Canvas 测试。

### P1: 常驻动画 ticker + 文本精确测量/IME（2026-07-28）
- **Status:** done
- Actions:
  - `src/Ticker.php`（新增）：常驻动画 ticker——独立于原生窗口的帧时钟（hrtime + usleep），可注入时钟源，驱动 `onFrame($t)` 直到 duration（回调可 return false 提前停止）。
  - `src/Animation.php`：新增 `frame()`，把 `Canvas::drawNode` 的插值逻辑抽成后端无关纯函数（Canvas 与 Reference 共用），消除双份插值数学。
  - `src/Backends/Canvas.php`：`drawNode` 复用 `Animation::frame()`（行为不变）。
  - `src/Backends/Reference.php`：新增 `setClock()/clock()/resetClock()/isAnimating()/animState()` + 帧渲染（translate/scale + opacity 经 `imagecopymerge` 与背景混合的确定性近似）。
  - 文本测量升级：`pureTextWidth` 改为按字符类别累加（半角 ~0.6em / 全角 CJK ~1.0em / 组合变音与 IME 组合符号 0 宽），`Reference::text` 截断同步；保持零字体依赖、确定性（不引入 `imagettfbbox`，避免破坏跨机器基线）。
  - 示例 `examples/animated_reference.php`：纯 PHP 用 Ticker 驱动 Reference 渲染并逐帧存 PNG（零 FFI 证明动画可行）。
- Files: `src/Ticker.php`, `src/Animation.php`, `src/Backends/Canvas.php`, `src/Backends/Reference.php`, `tests/TickerTest.php`, `tests/ReferenceRenderTest.php`(+动画帧基线), `examples/animated_reference.php`
- Verification: `composer test:ref` + TickerTest 共 13 passed / 29 assertions（无 PHP 警告）；`composer test` 全量 106 passed / 379 assertions / 1 skipped；动画帧 t=0 vs t=0.6 像素不同且各有基线。

### P2: Headless 应用循环 + IME 组合输入 + Reference 控件覆盖 + Html 动画（2026-07-28）
- **Status:** done
- Actions:
  - **Headless 应用循环**：`App::headless(frames, fps, durationSec, onFrame)` + `App::run()` 检测 headless 后端（Reference/Html）时用 `Ticker` 驱动逐帧 `setClock` + `update`；复用 P1 的 `setClock`/帧渲染。`Ticker` 可注入时钟源（`withClock`）便于测试。关键修复：缓存渲染树（只渲染一次），否则每帧重建树会让动画起点（按元素身份）被重置回 t=0。
  - **IME 组合输入**：`Ui::input/textarea/searchField` 增加 `onComposition` 钩子（第5参数，位于 `id` 之后，向后兼容）；`Reference::composition(id, phase, text)` 存运行时组合状态，`draw` 在输入框内绘制待定文本（accent 色）+ 下划线预览；`App::composition(id, phase, text)` 注入 headless 后端并重绘一次。
  - **Reference 控件覆盖**：新增 `case 'scroll'`（视口背景填充 + 边框）与 `case 'table'`（列头 + 行渲染），对齐 Canvas 绘制，使 headless/像素回归覆盖 scroll 与 table。
  - **Html 后端动画**：`Html::renderNode` 检测 `anim` prop，纯 PHP 生成 CSS `@keyframes` + `animation` style（浏览器驱动 opacity/translate/scale，零 JS 运行时、零 FFI）。
  - 修复：`Reference::applyAlpha` 改用本地分配的不透明背景色（修复跨图像颜色索引导致 opacity 无效）；补 `Reference` 缺失的 `$composition` 类属性声明（消除 PHP 8.5 动态属性 deprecation）。
- Files: `src/App.php`(headless/composition), `src/Backends/Reference.php`(composition/scroll/table/applyAlpha), `src/Backends/Html.php`(anim), `src/Ui.php`(onComposition 钩子), `tests/HeadlessLoopTest.php`, `tests/ImeTest.php`, `tests/ControlCoverageTest.php`, `tests/HtmlAnimTest.php`
- Verification: P2 新增测试 11 passed / 34 assertions；`composer test` 全量 **117 passed / 396 assertions / 1 skipped**（0 failed）。回归修复：`Ui::input` 的 `onComposition` 参数曾误置于 `id` 之前，导致既有 4 位置调用 `id` 错位、Canvas snapshot 丢失 input 角色；已修正为 `id` 之后。

### P3: Canvas IME 组合输入 parity + P2 能力可运行示例（2026-07-29）
- **Status:** done
- Actions:
  - **Canvas IME parity**：`Canvas` 增加 `$composition` 状态与 `composition(id, phase, text)` 方法（触发 `requestRedraw`），`input`/`textarea`/`drawSearch` 绘制待定文本（accent 色）+ 下划线预览，与 Reference headless 同构；`App::composition` 因此能路由到原生后端，headless/原生 IME 行为一致。
  - **P2 能力可运行示例**（均零 FFI）：`examples/headless_loop.php`（Ticker 驱动 Reference 逐帧渲染动画并可导出 PNG 帧）、`examples/ime.php`（Reference 接收 composition 事件并渲染待定预览）、`examples/html_anim.php`（Html 后端把 `anim` 输出为 CSS `@keyframes`+`animation`，浏览器驱动）。
  - 修复：`Html::renderNode` 动画时长初值 `1000` 会覆盖更短 `duration` → 改为 `0.0` 取 `max`（例：`600ms` 现正确生效）。
- Files: `src/Backends/Canvas.php`(composition/drawComposition), `src/Backends/Html.php`(dur 初值), `examples/headless_loop.php`, `examples/ime.php`, `examples/html_anim.php`, `tests/CanvasImeTest.php`
- Verification: P3 新增测试 2 passed / 5 assertions；三个示例零 FFI 运行通过；`composer test` 全量 **119 passed / 401 assertions / 1 skipped**（0 failed）。

### P4: scroll 内容裁剪（native SDK 支持）（2026-07-29）
- **Status:** done
- Actions:
  - **裁剪机制**：原生 host 走 Cairo，`cairo_save`/`cairo_clip`/`cairo_restore` 原生支持，故实现真正的 scroll 裁剪（用户确认"native sdk 支持 就做"）。
  - 采用最小侵入方案：`Layout::scroll` 在其内容子节点后追加一个零尺寸的 `scroll_end` 哨兵节点；`Canvas::paint` 绘制循环维护裁剪栈——遇到 `scroll` 节点先画视口框（不裁剪）再压入视口矩形，遇到 `scroll_end` 弹出。LIFO 天然兼容嵌套 scroll，无需给几十处 `new Node` 透传 clip（避免侵入式重构）。
  - `Cairo` FFI 新增 `cairo_save`/`cairo_restore`/`cairo_clip` 及 `cairo_image_surface_get_data/width/height/stride`/`cairo_surface_flush`（均 libcairo 自带函数，无需改 C）；`Canvas` 新增 `offscreenPixels()` 提供 headless 像素读回（与 Reference `pixelsHash` 对齐），用于确定性验证裁剪。
  - 护栏：`Layout::hitTest` 与 `Reference::draw` 显式跳过 `scroll_end` 哨兵（零尺寸不误命中、不改 Reference 快照基线）。
- Files: `src/FFI/Cairo.php`(save/restore/clip/读回), `src/Canvas/Layout.php`(scroll_end 哨兵 + hitTest 跳过), `src/Backends/Canvas.php`(裁剪栈 + offscreenPixels), `src/Backends/Reference.php`(draw 跳过 scroll_end), `tests/ScrollClipTest.php`, `examples/scroll_clip.php`(headless 渲染可滚动列表并导出裁剪帧)
- Verification: P4 新增测试 3 passed / 9 assertions（Cairo 裁剪机制确定性 + Layout 哨兵 + Canvas 像素级 overflow 被裁剪）；`composer test` 全量 **122 passed / 410 assertions / 1 skipped**（0 failed），Reference 快照基线未变（哨兵零影响）。
- 已知限制已解除：scroll 内容溢出裁剪完成。

### P5: scroll 交互（键盘 + 鼠标滚轮）（2026-07-29）
- **Status:** done
- 背景：库的 Canvas 原生宿主此前只有 `QUIT/POINTER_DOWN/UP/MOVE/KEY` 五种事件，**没有鼠标滚轮事件**，且 `onKey` 的 ↑/↓ 仅用于 list/select 高亮浏览，scroll 容器无法滚动。用户确认"两者都要"（纯 PHP 键盘滚动先做，真正的鼠标滚轮后做）。
- Actions:
  - **PHP 端**：`Canvas` 新增 `activeScrollId`（`POINTER_DOWN` 命中后通过 `scrollContainerAt()` 记录光标所在 scroll 容器 id）；`onKey` 开头拦截 ↑/↓ 作用于该容器（`scrollBy` ±40px）。信号约定统一为 data>0 = 向下滚（offset 增加）。
  - **WHEEL 事件**：`libui3.h` 枚举新增 `UI3_EVENT_WHEEL = 5`；`onEvent` 新增 `WHEEL` 分支（`scrollContainerAt` + `scrollBy`）。三平台宿主转发：`cocoa.m` 的 `scrollWheel:`（取 `scrollingDeltaY` 并取反以匹配"向下为正"）、`win32.c` 的 `WM_MOUSEWHEEL`（取反 Win32 的"向上为正"并按 40px/格归一）、`x11.c` 的 `ButtonPress` 区分 Button4（上，−40）/Button5（下，+40）。
  - 重建 `libui3`（Cocoa）通过；真实窗口滚轮由 OS 事件驱动，headless 下用反射驱动 `onEvent` 验证 PHP 分支。
  - 新增 `examples/scroll_window.php`：默认 headless sanity 帧，`UI3_REAL_WINDOW=1` 弹真实窗口（列表溢出被裁剪，可滚轮/↑↓ 滚动）。
- Files: `ext/libui3.h`(WHEEL 枚举), `ext/cocoa.m`, `ext/win32.c`, `ext/x11.c`(三平台 WHEEL 转发), `src/Backends/Canvas.php`(activeScrollId + scrollContainerAt + onKey 拦截 + WHEEL 分支), `examples/scroll_window.php`, `tests/ScrollInteractionTest.php`
- Verification: `ScrollInteractionTest` 3 passed / 8 assertions（键盘箭头滚动、WHEEL 滚动、滚动后仍裁剪）；`composer test` 全量 **125 passed / 418 assertions / 1 skipped**（0 failed）；`bash ext/build.sh` 重建 libui3 成功（Cocoa）。
- 已知限制已解除：scroll 容器现在既可由键盘 ↑/↓ 滚动（点击激活），也可由鼠标滚轮滚动（三平台原生事件转发）。

### P6: scroll 容器滚动条绘制（2026-07-29）
- **Status:** done
- 背景：P5 实现了交互滚动，但 Canvas 渲染的 scroll 容器只有边框、没有任何滚动条，用户反馈"可以滚动但没出现滚动条"。Reference 后端也从未画滚动条。
- Actions:
  - `Layout`：`placeColumn` 在 `scroll` 分支本来返回内容自然高度却被丢弃，改为捕获并缓存到 `self::$scrollContent[id]`（compute 开头重置），新增 `Layout::scrollContentHeight($id)` getter。
  - `Canvas` 绘制循环：把裁剪栈元素从裸 `[x,y,w,h]` 改为字典，附带 `node/contentH/off`；在 `scroll_end` 哨兵弹 clip **之前**调用新增的 `drawScrollbar()`（此时视口 clip 仍生效，滚动条落在内容之上、框内）。`drawScrollbar()` 仅在 `contentH > 视口高` 时绘制：右侧 6px 车道（border 色轨道 + accent 色 thumb），thumb 高度按 `视口/content` 比例、位置按 `off/(contentH-视口)` 推算。
  - 新增测试 `tests/ScrollInteractionTest.php::overflowing scroll container paints a scrollbar thumb`（headless 渲染后扫描视口右缘列，确认出现 accent 色 thumb 像素）。
- Files: `src/Canvas/Layout.php`（缓存 contentH + getter）, `src/Backends/Canvas.php`（绘制循环字典化 + drawScrollbar），`tests/ScrollInteractionTest.php`（滚动条断言）
- Verification: `ScrollInteractionTest` 4 passed / 9 assertions（含滚动条绘制断言）；`composer test` 全量仍 **125 passed / 418 assertions / 1 skipped**（0 failed）；`examples/scroll_clip.php` 重新导出 PNG 帧含滚动条。
- 已知限制已解除：scroll 容器溢出时现在会绘制滚动条，且随滚动实时移动；内容不足一屏时不画滚动条。

### P7: 滚动条改为 Design Tokens 驱动（混合方案·自绘外观）(2026-07-29)
- **Status:** done
- 背景：用户明确滚动条采用**混合方案**——外观自绘（与 native SDK 的 `tokens.controls.scrollbar` 一致），滚动物理委托系统原生（`ext/` 已转发的 `UI3_EVENT_WHEEL`，macOS `scrollWheel:` 自带 trackpad 动量）。此前滚动条硬编码 `border`/`accent` 且用 `fillRect`（非圆角）。
- Actions:
  - `Theme`：LIGHT/DARK 各加 `scrollbarTrack`/`scrollbarThumb`/`scrollbarRadius`/`scrollbarThickness` 四个令牌（对应 Zig 引擎 `tokens.controls.scrollbar`）。
  - `Cairo`：cdef 增加 `cairo_arc`/`cairo_new_path`/`cairo_close_path` 绑定；新增 `fillRoundedRect()`（对应 native 的 `fillRoundedRect`）。
  - `Canvas::drawScrollbar()`：粗细/圆角取自主题令牌，轨道+滑块用圆角矩形绘制（轨道半透明 0.45、滑块 0.9），颜色由 `scrollbarTrack`/`scrollbarThumb` 令牌驱动，可随主题自定义。
  - `tests/ScrollInteractionTest.php` 断言改为匹配 `scrollbarThumb` 令牌色。
- Files: `src/Theme.php`（scrollbar 令牌）、`src/FFI/Cairo.php`（cairo_arc 绑定 + fillRoundedRect）、`src/Backends/Canvas.php`（drawScrollbar 令牌化）、`tests/ScrollInteractionTest.php`（断言）
- Verification: `ScrollInteractionTest` 4 passed / 9 assertions；`composer test` 全量 **126 passed / 419 assertions / 1 skipped**（0 failed）；`examples/scroll_clip.php` 重新导出 PNG 帧含主题化圆角滚动条。
- 已知限制：**水平滚动仍待实现**（与 native SDK Issue #137 一致，仅垂直）。拖拽 thumb 已在 P9.5 实现。

### P8: 滚动条原生物理——橡皮筋 + 覆盖式自动隐藏（2026-07-29）
- **Status:** done
- 背景：用户确认滚动条采用混合方案（外观自绘 + 滚动物理委托原生）。P7 已把外观接到 Design Tokens。`ext/` 转发的 `UI3_EVENT_WHEEL` 本身就是系统事件，macOS `scrollWheel:` 自带 trackpad 动量——所以"动量"已是原生的。缺口是**橡皮筋回弹**和**覆盖式自动隐藏**。
- 设计权衡：用户原话提到用 invisible `NSScrollView` 覆盖层实现物理。但本仓库是 libui-free 单表面架构，字面嵌入 NSScrollView 会带来反馈环/双滚动条风险且只覆盖 macOS。因此改为**在 canvas 层用已有的原生 WHEEL 输入驱动橡皮筋与覆盖式淡出**（跨平台、可 headless 测试、不碰脆弱的 C 宿主），同样符合"视觉自绘、物理原生输入"。若需 OS 原生橡皮筋的精确弹簧时机，可后续加 macOS `NSScrollView` 覆盖层。
- Actions:
  - `Canvas::scrollBy()`：原只钳制下限 `max(0,...)`，现同时按 `scrollContentHeight(id) - 视口高` 钳制上限，并把越界量作为 `scrollElastic[id]` 弹性过冲；并在调用前惰性确保 `layout` 已计算（与 `onEvent` 一致，避免未 paint 时 `findNodeById` 落空）。
  - `Canvas::paint()`：每帧把 `scrollElastic` 按 0.82 衰减至 0（橡皮筋回弹）；将阻尼后的过冲并入布局偏移，内容视觉过冲并回弹；通过 `effectiveScrollOffset()` 让滑块跟随内容。
  - `Canvas::drawScrollbar()`：覆盖式行为——滚动/悬停后完全可见，闲置 `SCROLLBAR_IDLE`(0.9s) 后于 `SCROLLBAR_FADE`(0.5s) 内淡出；alpha 同时作用于轨道与滑块。
  - `Canvas::onEvent()` `POINTER_MOVE`：悬停在滚动容器上时显示滚动条（native overlay 行为）。
  - `Canvas` 新增 `now()`（与 paint 同源的时间源），修复 scrollBy 在首次 paint 前用 `$this->clock=0` 设可见时间导致滚动条误判隐藏的 bug。
  - `Layout::placeList()`：虚拟化/非虚拟化/children 三种 list 均把内容高度写入 `scrollContent[id]`，使 `scrollContentHeight()` 对 scroll 与 list 都有效（修复橡皮筋上限钳制对 list 失效）。
  - 测试：`tests/ScrollInteractionTest.php` 新增 `scrolling past the end clamps and rubber-bands back`（越界钳制到 maxOff、回弹后不变）；既有 scrollbar 断言改为交互后采样（覆盖式初始隐藏）。`tests/InputTest.php` 的 `scr` 用例因内容恰好填满视口、钳制到 0 而更新断言。
- Files: `src/Backends/Canvas.php`（scrollBy 钳制+弹性、paint 衰减、drawScrollbar 淡出、onEvent 悬停、now）、`src/Canvas/Layout.php`（list 内容高度缓存）、`tests/ScrollInteractionTest.php`、`tests/InputTest.php`
- Verification: `ScrollInteractionTest` 5 passed / 11 assertions；`composer test` 全量 **127 passed / 421 assertions / 1 skipped**（0 failed）；`examples/scroll_clip.php` 重新导出 `frame_scrolled.png` 含滚动条（frame_top 初始隐藏，符合覆盖式行为）。
- 已知限制：**水平滚动仍待实现**；橡皮筋/覆盖式淡出是 canvas 层用原生 WHEEL 输入模拟（非字面 NSScrollView 物理），动量本身已是原生（macOS trackpad）。拖拽 thumb 已在 P9.5 实现。

### P9: 修复真实窗口下滚动条淡出导致的无限递归崩溃 (2026-07-29)
- **Status:** done
- 现象：`examples/scroll_window.php` 真实窗口滚动后崩溃，栈顶为 `Canvas.php(592) ui3_host_request_redraw` → `Canvas.php(320) paint` 无限重复，最终 `Throwing from FFI callbacks is not allowed`（栈溢出）。
- 根因：`ext/` 的 `ui3_plat_request_redraw` 在 macOS（`lockFocus`+`cocoa_paint`）和 X11（`x11_draw`）都是**同步**绘制（直接调 `draw_cb`）。P8 让 `Canvas::paint()` 末尾在滚动条淡出期（`scrollAnimating` 恒真约 1.4s）调用 `requestRedraw()`，于是 `paint` → 同步重绘 → `paint` → … 无限递归。headless 测试不触发，因为 `ui3_host_request_redraw` 在 headless 下只置 `needs_redraw` 标志、不真正绘制。
- 修复（C 宿主层，跨平台一致）：把平台 `request_redraw` 改为**异步**，不再同步重入 `paint`：
  - 新增同步出帧函数 `ui3_plat_present()`（macOS `lockFocus`+`cocoa_paint`；X11 `x11_draw`；win32 `InvalidateRect`）。
  - `ui3_host_present()` 改用 `ui3_plat_present()`（初始帧/显式出帧语义**不变**）。
  - `ui3_plat_request_redraw()` 改为只"排程"重绘：macOS 用 `[view setNeedsDisplay:YES]`（OS 在下一 run-loop pass 调 `drawRect`，形成正确的 vsync 对齐帧循环）；X11 只置 `host->needs_redraw=1`，改 `ui3_plat_run` 循环在 `needs_redraw` 时出帧、空闲时阻塞（`XNextEvent`）以保留 0 CPU 空闲；win32 的 `InvalidateRect` 本就异步，仅补 `ui3_plat_present` 定义供链接。
  - 这样 `paint()` 末尾请求重绘不再同步重入，递归被切断；动画帧循环由 OS（macOS/win32）或 run 循环（X11）按 `needs_redraw` 推进，且 `scrollAnimating`/橡皮筋衰减归零后自行停止。
- Files: `ext/internal.h`（`ui3_plat_present` 声明）、`ext/common.c`（`ui3_host_present` 改用同步 present）、`ext/cocoa.m`（`request_redraw`→`setNeedsDisplay` + 新增 `present`）、`ext/x11.c`（`request_redraw`→置标志 + 新增 `present` + `run` 循环改非阻塞出帧）、`ext/win32.c`（新增 `present` 定义）
- Verification: `ext/build.sh` 编译通过（`build/libui3.dylib` ok）；`composer test` 全量 **127 passed / 421 assertions / 1 skipped**（0 failed，headless 路径无回归）。真实窗口递归因 headless 无法在 CI 复现，已由 C 层异步化从根因切断。
- 已知限制：真实窗口下滚动条淡出/橡皮筋的物理已在 macOS/X11/win32 通过异步帧循环驱动，但需在带显示的 macOS 上手动验证一次（`scroll_window.php` 滚动后观察淡出且不崩）。

### P9.5: 实现滚动条 thumb 拖拽 + 轨道点击跳转 (2026-07-29)
- **Status:** done
- 现象/需求：P9 后真实窗口不再崩溃，但鼠标拖动滚动条 thumb 无效（之前标记"拖拽 thumb 滚动尚未实现"）。根因：`onEvent` 的 `POINTER_DOWN` 只做内容 hitTest/fire，滚动条 thumb 区域完全没有命中处理，拖拽从未被接收。
- 修复（`src/Backends/Canvas.php`，纯 PHP 层）：
  - 抽出 `scrollbarGeom(Node, contentH, off)` 统一几何（track/thumb 矩形），`drawScrollbar` 与命中测试共用——保证"看到的 thumb 就是能抓的 thumb"。
  - 新增 `hitScrollbar(x,y,g)`：命中 thumb 返回 `'thumb'`，命中轨道其余区域返回 `'track'`，否则 `null`。
  - 新增 `tryBeginScrollbarDrag(x,y)`：`POINTER_DOWN` 时先调用；命中滚动条则记录 `drag = ['type'=>'scrollbar','id','grab','trackY','trackH','thumbH','maxOff']` 并返回 `true`（阻止后续内容点击）。`'track'` 命中先按 `(y-trackY)*maxOff/(trackH-thumbH) - thumbH/2` 跳转到点击处、再以 thumb 中点为抓取点继续拖拽；`'thumb'` 命中以 `y - thumbY` 为抓取偏移。
  - 新增 `scrollTo(id, off)`：绝对滚动（钳制 + 清橡皮筋 + onScroll + 重绘），供拖拽/跳转使用。
  - `onPointerDrag` 的 `MOVE` 分支新增 `'scrollbar'` 处理：`off = (y - grab - trackY) * maxOff / (trackH - thumbH)`，反算滚动偏移后 `scrollTo`。
  - `onEvent` 的 `POINTER_DOWN` 在内容 hitTest 之前插入 `tryBeginScrollbarDrag` 短路。
- Files: `src/Backends/Canvas.php`（scrollbarGeom/hitScrollbar/scrollTo/tryBeginScrollbarDrag + 接入 onEvent/onPointerDrag）、`tests/ScrollInteractionTest.php`（新增 2 测试：thumb 拖到轨道中点→offset=maxOff/2；轨道点击跳转）
- Verification: `composer test` 全量 **129 passed / 425 assertions / 1 skipped**（0 failed）；新增 `dragging the scrollbar thumb scrolls the content` 与 `clicking the scrollbar track jumps the thumb there` 两个交互测试通过（off-by-one 已用与实现一致的 `(int)` 截断对齐）。
- 已知限制：**水平滚动仍待实现**（仅垂直滚动条可拖拽）；thumb 拖拽命中宽度为 thumb + 4px 容差。

### P9.6: 修复 macOS 真实窗口 thumb 拖拽无效 (2026-07-29)
- **Status:** done
- 现象：`examples/scroll_window.php` 在 Mac 真实窗口下 thumb 拖拽仍无效果（P9.5 只改了 PHP 层逻辑，headless 测试直接调 `onEvent` 通过，但真实窗口走宿主 C 转发路径）。
- 根因：`ext/cocoa.m` 的 `Ui3View` 只实现了 `mouseMoved:`，而 Cocoa 在**按住鼠标拖动时发的是 `mouseDragged:` 而非 `mouseMoved:`**，代码缺 `mouseDragged:` 方法 → 拖 thumb 期间没有任何 `UI3_EVENT_POINTER_MOVE` 送达 PHP 侧 → `onPointerDrag` 永远收不到 MOVE → 滚动偏移不更新。X11(`ButtonMotion`/MotionNotify)与 win32(`WM_MOUSEMOVE` 拖拽时也发送)本就正常，唯独 Cocoa 缺。
- 修复（`ext/cocoa.m`）：抽 `forwardMove:` 共用转发逻辑，`mouseMoved:` 与新增的 `mouseDragged:` 都调用它；并 `setAcceptsMouseMovedEvents:YES` 让无按键 hover 也能触发滚动条淡出显示。PHP 层(P9.5 的 `onPointerDrag` scrollbar 分支)无需改动。
- Files: `ext/cocoa.m`（新增 `mouseDragged:` + `forwardMove:` 重构 + 开启 hover 跟踪）
- Verification: `ext/build.sh` 编译通过（`build/libui3.dylib` ok）；`composer test` 全量 **129 passed / 425 assertions / 1 skipped**（0 failed，PHP 逻辑无回归）。真实窗口拖拽手感需在带显示的 Mac 上手动确认。

### P10: 给 list 控件补 overlay 滚动条 + thumb 拖拽 (2026-07-29)
- **Status:** done
- 背景：用户选了"给 list 控件补滚动条+拖拽"（A 方向，非字面 NSScrollView）。
- 根因：`list` 控件（`items` 属性 + `virtual`）之前**不能真正滚动显示**——`drawNode case 'list'` 从 0 开始画全部行（忽略滚动偏移、不裁剪），而 `placeList` 虚拟模式又创建了窗口化的 `list_item` 节点 → 重复绘制 + 偏移丢失。且 `Layout::compute` 把 Canvas 的 `scrollOverrides`（**像素**）赋给 `self::$overrides`，但 `placeList` 虚拟模式却把它当 **item index** 用，导致 list 几乎不能滚。
- 修复（`src/Canvas/Layout.php` + `src/Backends/Canvas.php`，跨 scroll 容器与 list 一致）：
  - `Layout::placeList` 虚拟模式：消费**像素**偏移（与 scroll 容器统一），`start = round(offPx / LISTITEM)` 并钳制到 `[0, count - vh]`；给每行标注 `_sel`（选中）、`_listId`（供键盘高亮）。初始 `scroll` prop（item index）在首帧 ×LISTITEM 转 px。
  - `Canvas::scrollOffset`：list 的回退值按像素返回（`item index × LISTITEM`）。
  - `Canvas::scrollContainerAt`：识别虚拟 `list` 作为可滚动容器（供滚轮/thumb 拖拽命中）。
  - `Canvas::drawNode case 'list'`：虚拟列表不再自己画行（交给窗口化 `list_item` 节点，消除重复/偏移错误）；非虚拟 items 列表保持旧行为不退化；children-based 列表（`listView` 基线）不变。
  - `Canvas::drawListItem`：按 `_sel`/`_listId`+`highlights` 画选中/高亮（children-based 基线 `selected=-1` 不受影响）。
  - 抽出 `paintScrollbar($cr, Node, contentH, offPx)`，scroll 容器（`scroll_end` sentinel）与虚拟列表（主循环后**置顶**绘制，避免被行覆盖）共用；`drawScrollbar(sc)` 改为其薄包装。
- Files: `src/Canvas/Layout.php`（placeList 虚拟偏移换算 + 行标注）、`src/Backends/Canvas.php`（scrollOffset/scrollContainerAt/drawNode/drawListItem/paintScrollbar/后置绘制）、`tests/ListScrollTest.php`（新增 3 测试）
- Verification: `composer test` 全量 **132 passed / 430 assertions / 1 skipped**（0 failed，含 `list` 基线 ReferenceRenderTest 无回归）；新增 `virtual list control draws an overlay scrollbar after interaction`（像素验证滚动条画出）、`dragging the list scrollbar thumb scrolls the list`（拖到轨道中点→offset=maxOff/2）、`clicking the list scrollbar track jumps the list there`（轨道跳转，off-by-one 已用 `(int)` 截断对齐）。
- 已知限制：**水平滚动仍待实现**；非虚拟 items 列表（`virtual` 缺省）仍走旧的全部行渲染（双击+偏移由 list_item 节点，行为同前，未改）；list 内嵌于 scroll 容器时其滚动条可能越出外层裁剪（罕见，未处理）。

### P0.1: 文本编辑原语（caret/选区/undo/右键菜单）（2026-07-29）
- **Status:** done
- 背景：对比 native SDK，最缺的是"真正的文本编辑原语"——原生控件自带 caret、选区、undo/redo、右键菜单。本仓库是 libui-free 单 Cairo 表面，所有控件在 PHP 层手绘，故这些原语须在 PHP 编辑缓冲里自己实现。
- Actions:
  - `Canvas` 编辑缓冲升级为 `['text','cursor','sel','undo','redo']`；`editText()` 支持 Ctrl+a/c/x/v/z/y、Shift+方向键(选区扩展)、Home/End、Delete、Backspace（修正 `"\b"` 与 `"\x08"` 不匹配的 bug）、可打印字符插入（含选区替换）。
  - 新增 `pushUndo`/`doUndo`/`doRedo`/`deleteSelection`/`deleteAt`/`replaceSelection`/`emitInput`/`caretVisible`/`drawFieldText`/`fieldCaretXY`/`drawFieldSelection`/`fieldSelectionRange`，绘制 caret 与选区高亮。
  - `common.c` 增加 Home/End/Delete + Shift+方向键令牌；`cocoa.m` 增加 Ctrl 修饰符（发 `"Ctrl+<key>"`）；`win32.c` 增加 VK_HOME/END/DELETE；`x11.c` 增加 XK_Home/End/Delete。
  - `App` 增加 `backend()`/`clipboard()`/`setClipboard()` 委托；`LibUi3.php` 与 `libui3.h` 同步 cdef。
- Files: `src/Backends/Canvas.php`, `src/App.php`, `src/FFI/LibUi3.php`, `ext/libui3.h`, `ext/common.c`, `ext/cocoa.m`, `ext/win32.c`, `ext/x11.c`, `tests/TextEditTest.php`
- Verification: `composer test` 全量 **138 passed / 446 assertions / 1 skipped**（0 failed）；6 个 P0.1 编辑测试（Shift+选区替换、Home/End、Delete、Ctrl+Z/Y、Ctrl+A+X+V、Ctrl+C）通过。

### P0.2: 原生剪切板（2026-07-29）
- **Status:** done
- Actions:
  - `Canvas::setClipboard/clipboard` 接线到 FFI `ui3_host_set/get_clipboard_text`；仅在真实窗口（`!isHeadless()`）调用原生，headless 退化到内存镜像（保持测试稳定）。`copy`/`cut` 现在同时写入原生剪切板，`paste` 从原生（或内存）读取。
  - `App::clipboard/setClipboard` 委托到 Canvas 后端。
  - 三平台宿主实现：`cocoa.m`（NSPasteboard）、`win32.c`（Win32 Clipboard API）、`x11.c`（popen 到 `xclip`/`xsel`，零构建依赖，helper 缺失时静默降级）。
- Files: `src/Backends/Canvas.php`, `src/App.php`, `ext/cocoa.m`, `ext/win32.c`, `ext/x11.c`, `tests/ClipboardDialogTest.php`
- Verification: 新增 `ClipboardDialogTest` 6 测试（set/get round-trip、App 委托、copy/cut 落剪切板、paste 插入）headless 全绿；`composer test` 全量 **144 passed / 454 assertions / 1 skipped**（0 failed）；`bash ext/build.sh` 重建成功（macOS）。
- 已知限制：原生剪切板路径无法在 headless 下单测，需在带显示环境手动验证一次（macOS/win32 走系统 API，x11 走 xclip/xsel）；x11 `set_clipboard` 经临时文件喂给 xclip/xsel 以规避 shell 转义。

### P0.3: 文件打开/保存对话框（2026-07-29）
- **Status:** done
- Actions:
  - `Canvas::openFile(?filters)/saveFile(?defext)` 接线到 FFI `ui3_host_open/save_file`；headless 或无 host 时返回 `null`（不弹窗、不阻塞）。`App::openFile/saveFile` 委托到 Canvas。
  - 三平台宿主实现：`cocoa.m`（NSOpenPanel/NSSavePanel）、`win32.c`（GetOpenFileName/GetSaveFileName）、`x11.c`（popen 到 `zenity --file-selection` 或 `kdialog`，零构建依赖，helper 缺失时返回 NULL）。
- Files: `src/Backends/Canvas.php`, `src/App.php`, `src/FFI/LibUi3.php`, `ext/libui3.h`, `ext/cocoa.m`, `ext/win32.c`, `ext/x11.c`, `tests/ClipboardDialogTest.php`
- Verification: `openFile/saveFile` 在 headless 下返回 `null` 的护栏测试通过；`composer test` 全量 **144 passed / 454 assertions / 1 skipped**（0 failed）；`bash ext/build.sh` 重建成功（macOS，仅 `setAllowedFileTypes:` 弃用告警，无害）。
- 已知限制：真实对话框无法在 headless 下自动化；x11 的 `filters`/`defext` 参数当前未映射给 GTK chooser 的过滤语法（留待后续）；macOS 保存面板已换 `allowedContentTypes`（见 P0.4）。

### P0.4: 文本编辑右键上下文菜单 + x11 切到 GTK + macOS 保存面板弃用修复（2026-07-29）
- **Status:** done
- 背景：P0.1 完成了 caret/选区/undo，但"右键上下文菜单"这一项 native SDK 标配能力仍缺；同时用户要求 x11 走 GTK、并修掉 macOS 保存面板 `setAllowedFileTypes:` 弃用告警。
- Actions:
  - **右键上下文菜单（文本字段）**：三平台宿主把鼠标右键归一化为 `button=2`（cocoa `rightMouseDown:`、win32 `WM_RBUTTONDOWN`、x11 鼠标键 3）；`inject_pointer` 增加 `button` 参数（`common.c`/`libui3.h`/`LibUi3.php` cdef 同步）。PHP `onEvent` 增加 `handleContextMenuPointer`：右键命中 input/textarea/search 时先 `applyFocus` 再打开内置编辑菜单（Undo/Redo/Cut/Copy/Paste/Select All），命中带 `contextMenu` prop 的元素时打开其自定义菜单；菜单在 `paint()` 顶层手绘（`drawContextMenu`）；点击菜单项经 `hitContextMenu`→`runContextMenuItem`（`action` 映射到 `undoEdit/redoEdit/cut/copy/paste/selectAll`，`msg` 则 `dispatch`）；点击菜单外或 `Escape` 关闭菜单。
  - **Escape 终接通路**：`ui3_key_text` 原先缺 keycode 53 分支，导致 `\x1b` 令牌从未产生；补 `case 53: return strdup("\x1b")`，使菜单/下拉的 Esc 关闭真正生效（三平台统一）。
  - **x11 切到 GTK**：`x11.c` 的剪切板/文件对话框改为 GTK 3 实现（`GtkClipboard` / `GtkFileChooserDialog`），`gtk_init_check` 懒初始化、失败静默降级；原 xclip/zenity 方案移除。`ext/build.sh` Linux 分支经 `pkg-config` 引入 `gtk+-3.0` cflags/libs。
  - **macOS 保存面板弃用修复**：`cocoa.m` 用 `UTType typeWithFilenameExtension:` + `setAllowedContentTypes:` 替换 `setAllowedFileTypes:`；`ext/build.sh` macOS分支链接 `-framework UniformTypeIdentifiers`。
- Files: `src/Backends/Canvas.php`, `src/FFI/LibUi3.php`, `ext/libui3.h`, `ext/common.c`, `ext/cocoa.m`, `ext/win32.c`, `ext/x11.c`, `ext/build.sh`, `tests/ContextMenuTest.php`
- Verification: 新增 `ContextMenuTest` 4 测试（右键打开内置菜单、点击 Copy 复制选区、Escape 关闭、自定义 `contextMenu` prop 右键打开）headless 全绿；`composer test` 全量 **148 passed / 469 assertions / 1 skipped**（0 failed）；`bash ext/build.sh` 重建成功（macOS，无告警）。
- 已知限制：GTK/x11 路径无法在本机（macOS）编译验证，需在 Linux + GTK 环境构建一次；右键菜单项 hover 高亮、次级菜单、剪切板内容预览等增强未做；`filters`/`defext` 仍仅原样透传给 GTK chooser（未做平台特定过滤语法）。

### P0.5: 右键菜单增强（hover 高亮 / 次级菜单 / 剪切板预览）+ 文件对话框平台过滤语法（2026-07-29）
- **Status:** done
- 背景：P0.4 交付了右键上下文菜单，但用户要求补三项成熟度增强——hover 高亮、次级菜单、剪切板内容预览；并要求把文件对话框的 `filters`/`defext` 从"仅原样透传"升级为各平台特定过滤语法。
- Actions:
  - **hover 高亮**：`openContextMenu` 记录 `hover` 索引；`onEvent` 的 `POINTER_MOVE` 在菜单打开时改走 `updateMenuHover`，对命中行用 `accentSoft` 填充高亮；移出菜单清零。
  - **次级菜单**：菜单项支持 `['submenu' => [...]]`；hover 到带 `submenu` 的父行时 `openSubmenu` 在其右侧（越界则翻到左侧）展开子菜单，子菜单同样可 hover 高亮；点击子项经 `hitContextMenu`（`sub=>true`）→`runContextMenuItem` 执行并关闭整个菜单；点击父行/预览行保持打开。
  - **剪切板内容预览**：菜单项支持 `['preview' => 'clipboard']`，绘制时读取实时 clipboard 文本（空时显示 `(empty)`，超长截断 40 字并加省略号），以 muted 色渲染在标题之后；该行为非命令，点击不关闭菜单。内置编辑菜单新增首行 `Clipboard` 预览行。
  - **文件对话框平台过滤语法**：`internal.h` 新增 `ui3_filter_group` 与共享解析器 `ui3_parse_filters`（规格 `"png,jpg"` 或 `"Images:png,jpg;Text:txt,md"`；扩展名可带或不带前导点；每条自动追加 `All Files`）。各平台接入：
    - GTK (`x11.c`)：按组创建 `GtkFileFilter`（`*.ext` pattern）+ `All Files`；保存对话框用 `defext` 建过滤器并 `set_current_name("untitled.<defext>")`。
    - macOS (`cocoa.m`)：open 把各组扩展名映射为 `UTType` 设 `allowedContentTypes`；save 用 `defext` 设 `allowedContentTypes` 并 `setNameFieldStringValue("untitled.<defext>")`。
    - Win32 (`win32.c`)：open 拼 `lpstrFilter`（双 NUL 终止 `"Label\0*.a;*.b\0..."`）+ `All Files`；save 用 `defext` 建过滤器、`lpstrDefExt`、默认文件名 `untitled.<defext>`。
  - **测试/文档**：`ContextMenuTest` 新增 3 测试（hover 高亮、次级菜单打开并选中、剪切板预览内容）；`App.php`/`Canvas.php` 的 `openFile`/`saveFile` 注释补充过滤语法；`openFile` 文档增加规格说明。
- Files: `src/Backends/Canvas.php`, `src/App.php`, `ext/internal.h`, `ext/common.c`, `ext/cocoa.m`, `ext/win32.c`, `ext/x11.c`, `tests/ContextMenuTest.php`
- Verification: `composer test` 全量 **151 passed / 481 assertions / 1 skipped**（0 failed，较 P0.4 +3）；`bash ext/build.sh` 重建成功（macOS，无告警）。
- 已知限制：GTK (`x11.c`)/Win32 (`win32.c`) 路径无法在本机（macOS）编译验证，需在 Linux + GTK / Windows 环境各构建一次确认。

### P0.6: 右键菜单深度扩展（多级嵌套子菜单 / 图标 / 勾选态）（2026-07-29）
- **Status:** done
- 背景：P0.5 交付了单层次级菜单与剪切板预览，但用户要求继续扩展——支持**多级（递归）嵌套子菜单**、菜单项**图标**、以及**勾选态**。
- Actions:
  - **多级嵌套子菜单**：把原先单层的 `submenu` 键改为 `submenus` 面板列表（根菜单 + 任意层级的子菜单面板）。`updateMenuHover` 改为在指针命中的**最深面板**上做悬停追踪：hover 到带 `submenu` 的父行时，在该行右侧（越界翻左、纵向夹取）展开下一级子菜单；若已是对的行则保留、否则以新面板替换其下所有层级；hover 到无子菜单的行或根菜单其它行则关闭其下所有层级。`hitContextMenu`/`runContextMenuItem`/`handleContextMenuPointer`/`drawContextMenu` 全部改用 `depth` 定位面板，支持任意层级命中/点击/绘制。
  - **图标**：菜单项支持 `['icon' => '<glyph>']`，`contextMenuSize` 在任一菜单项含 `icon`/`checked` 时预留 22px 左栏（`gutter`），`drawMenu` 在该栏绘制 glyph（沿用图标文字绘制通道）。
  - **勾选态**：菜单项支持 `['checked' => bool]`，左栏在 `checked` 为真时绘制 `✓`（accent 文本色）、为假时留空保持对齐；勾选态不影响点击行为（点击仍派发 `msg`，由应用侧决定状态）。
  - **测试/文档**：`ContextMenuTest` 新增 3 测试（多级嵌套展开、点击最深层项派发并关闭、icon+checked 数据透传且菜单因左栏变宽）；`Ui::contextMenu` 补条目结构 docblock（含 `submenu`/`icon`/`checked`/`preview`/`action`/`msg`）。新增公共访问器 `contextSubmenuDepth`/`contextSubmenuLevelItems`/`contextSubmenuLevelRect`。
- Files: `src/Backends/Canvas.php`, `src/Ui.php`, `tests/ContextMenuTest.php`
- Verification: `composer test` 全量 **154 passed / 498 assertions / 1 skipped**（0 failed，较 P0.5 +3）；ContextMenuTest 10 passed / 44 assertions。
- 已知限制：GTK/Win32 原生路径仍无法在本机编译验证（本次仅改 PHP 后端，未触碰 `ext/`）；图标以文本 glyph 渲染，依赖运行环境字体对相应字形（如 emoji）的支持。

## 5-Question Reboot Check
| Question | Answer |
|----------|--------|
| Where am I? | Phase 1-10 + P0-P3 maturity + P-Native P0 (多窗口/窗口管理) + P-Native P1 (菜单栏/DnD/手势/对话框/通知/无障碍树) 全部完成 |
| Where am I going? | P-Native P2 (富文本/剪贴板多格式/性能/Wayland/WebView) — 按优先级推进 |
| What's the goal? | 十个方向 + 原生 SDK 集成深度对齐 |
| What have I learned? | P-Native 分层架构：C ABI 声明 → common.c 共享逻辑 → platform hooks → PHP FFI 代理；无障碍树用 tab-delimited 文本协议避免 FFI 结构体生命周期问题 |
| What have I done? | P-Native P0+P1 共 10 个相位落地（多窗口/窗口管理/move/fullscreen/acceptClose/菜单栏/DnD/手势/对话框/通知/无障碍树），全量测试 ~197 passed，无障碍树桥接 P1 最后一个 pending 项解决 |

## P-Native P0: 多窗口 / 窗口管理 (2026-07-30)
- **Status:** done
- `ui3_host_create` 多实例 + `App::openWindow` 真正开第二个 OS 窗口
- `Canvas` 新增 `extraHosts` 数组 + `createExtraHost/destroyExtraHost/extraHostCount`，`step()`/`quit()` 遍历全部 hosts
- `App::openWindow()`/`closeWindow()` 通过 `Canvas` 代理，创建/销毁真实 OS 窗口
- 9 headless 测试：`MultiWindowTest.php`
- Verification: **192 passed / 0 failed / 1 skipped (602 assertions)**

## P-Native P0 (续): 窗口管理 move/fullscreen/acceptClose (2026-07-30)
- **Status:** done
- `internal.h`: `x,y,fullscreen,close_cb,close_ctx` + `ui3_plat_move`/`ui3_plat_fullscreen` hook
- `libui3.h`: `ui3_host_move/`fullscreen/`set_close_cb` + `x/y/fullscreen_state` getters
- `common.c`: wrappers + null-check pattern
- `cocoa.m`: `setFrameOrigin:`/`toggleFullScreen:`/`windowShouldClose:` delegate
- `win32.c`: `SetWindowPos`/`ShowWindow`/`WM_CLOSE` handler
- `x11.c`: `XMoveWindow`/`_NET_WM_STATE_FULLSCREEN`/`WM_DELETE_WINDOW` close_cb
- FFI + `Canvas` + `App` PHP 层完整落地
- `WindowMoveFullscreenTest.php` ×5 headless 测试
- Verification: **182 passed / 0 failed / 1 skipped (585 assertions)**

## P-Native P1: OS 手势 (2026-07-30)
- **Status:** done
- Cocoa: `magnifyWithEvent:`/`rotateWithEvent:`/`swipeWithEvent:`/`scrollWheel:` momentum phase
- Win32: `WM_GESTURE` (RegisterTouchWindow + GetGestureInfo: GID_ZOOM→pinch/GID_ROTATE/GID_PAN/GID_TWOFINGERTAP→swipe)
- X11: XI2 (XQueryExtension/XIQueryVersion/XISelectEvents + GenericEvent dispatch + touch tracking with 2-finger pinch distance/angle math + pan detection)
- `build.sh`: Linux 加 `-lXi`
- `GestureTest.php` ×4 headless 测试 (pinch/rotate/empty/pan)
- Verification: **192 passed / 0 failed / 1 skipped (602 assertions)**

## P-Native P1: 原生无障碍树桥接 (2026-07-30)
- **Status:** done（macOS 完整桥接；Win32/X11 deferred stubs）
- `libui3.h`: `ui3_a11y_node` 树结构 + `set_a11y_tree`(deep copy) + `set_a11y_text`(text→tree 解析) + `last_a11y`
- `internal.h`: `last_a11y_tree`(headless 序列化缓存) + `plat_a11y`(native 深拷贝树) + `ui3_plat_accessibility` hook
- `common.c`: `a11y_copy_node`/`a11y_free_tree`/`a11y_serialize`/`a11y_to_text` + `ui3_host_set_a11y_text` 13 字段 tab-delimited 解析 + 深度 parent stack
- `cocoa.m`: `Ui3A11yElement`(NSAccessibilityElement: accessibilityChildren/Label/Description/Focus) + `Ui3View`(accessibilityChildren/Label/Description)
- `win32.c`/`x11.c`: `ui3_plat_accessibility` stub（UIA/ATK 完整桥接 deferred）
- `FFI/LibUi3.php`: `ui3_a11y_node` FFI struct + `set_a11y_tree`/`set_a11y_text`/`last_a11y`
- `Canvas.php`: `flattenA11yTree`(Element→tab-delimited text) + `roleForType`(语义映射) + `boundsForElement`(Layout 坐标) + `buildA11yTree`(从 `update()` 自动调用) + `lastA11y()`
- `tests/AccessibilityTest.php` ×5 headless 测试
- Files: `ext/internal.h`, `ext/libui3.h`, `ext/common.c`, `ext/cocoa.m`, `ext/win32.c`, `ext/x11.c`, `ext/build.sh`, `src/FFI/LibUi3.php`, `src/Backends/Canvas.php`, `tests/AccessibilityTest.php`, `task_plan.md`
- Verification: `tests/AccessibilityTest.php` **5 passed (13 assertions)**；相关子集（Accessibility+Gesture+MultiWindow）**18 passed (33 assertions)**；全量测试 **197 passed / 0 failed / 1 skipped**
- Known bugs fixed: `$node->element`→`$node->el`（Node 属性名）；`__a11y_label`→`label`（Ui::accessible prop 名）；sscanf `[^%s]`→手动 tab 分割（空字段）；Canvas mount 必须调用；`$app->start()` 必须调用
