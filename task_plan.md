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

## All phases complete
十个方向全部补齐：主题/令牌、布局引擎、缺失组件、动画、事件/输入、状态/响应式、无障碍、多窗口、Web 目标、其他系统。

## Key Decisions
- 每方向三层(DSL+Canvas+自动化)最小实现，可被 MCP 观测。
- 主题令牌驱动所有绘制；自定义绘制经 Ui::canvas 闭包。
- 布局用固定常量 + flex grow + grid + 绝对定位 + 虚拟列表。
