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

## 5-Question Reboot Check
| Question | Answer |
|----------|--------|
| Where am I? | 全部完成（Phase 1-10 done） |
| Where am I going? | — 十个方向已补齐 — |
| What's the goal? | 补齐十个方向(功能级最小实现) |
| What have I learned? | findings.md；headless 下 step() 仅在有挂起重绘时绘制；focusedId/openContextMenu/scrollBy 等 API 已存在同名方法需避免重复；Snapshot 默认 name 对自定义 role 需回退 text/title；多窗口用 Windows 状态管理而非真实多 surface；HtmlBackend 实现 Backend 接口即可被自动化/快照复用 |
| What have I done? | Phase 1-10 全部完成（主题/布局/组件/动画/事件输入/状态响应式/无障碍/多窗口/Web目标/其他系统） |

### Follow-up: 测试脚本 + 示例
- **Status:** done
- Actions taken:
  - `bin/run.sh`：缺失 `libui3` 时自动执行 `ext/build.sh`；新增 `UI3_SKIP_BUILD=1` 跳过开关（供 CI 独立构建步骤）；保留库路径注入与用法提示。
  - 新增 6 个示例，演示补齐的能力：`animation.php`(Animation/Ui::fadeIn)、`accessibility.php`(Ui::accessible + 快照 role/label)、`multi_window.php`(Windows 状态)、`web_target.php`(Html 后端语义 HTML)、`systems.php`(Extensions/Capabilities/Assets)、`state.php`(Signal/Reconcile/breakpoint)。
  - 示例默认 headless，`UI3_REAL_WINDOW=1` 可选真实窗口；web_target/systems 为纯 PHP（不依赖 libui3）。
- Files: `bin/run.sh`, `examples/{animation,accessibility,multi_window,web_target,systems,state}.php`
- Verification: 6 个示例全部运行通过（headless 经 `bin/run.sh` / 纯 PHP），exit 0；`bash -n` 通过。

## 5-Question Reboot Check
| Question | Answer |
|----------|--------|
| Where am I? | 全部完成（Phase 1-10 + 测试脚本/示例 follow-up） |
| Where am I going? | — 收尾 — |
| What's the goal? | 补齐十个方向(功能级最小实现) + 可运行示例与 CI 友好测试脚本 |
| What have I learned? | bin/run.sh 可自动构建 libui3（UI3_SKIP_BUILD 跳过）；Html/systems 示例可不依赖原生库纯 PHP 运行 |
| What have I done? | Phase 1-10 完成；测试脚本支持自动构建；新增 6 个示例覆盖各新能力 |
