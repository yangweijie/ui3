# Findings & Decisions

## Requirements
- 按对比(`web/native` vs `ui3`)的十个缺失方向补齐，排除移动端。
- 每层：DSL(`Ui.php`) → 渲染(`Canvas.php`/`Layout.php`) → 自动化可见(`Snapshot`/`McpServer`)。

## Research Findings (来自对比探索)
- ui3 后端：`Backends/Canvas.php` 经 FFI 调 `ext/` 的 `libui3` C shim(`ui3_host_*`) 起原生窗口，Cairo 全树绘制。
- `App.php`：Elm 式 `dispatch` → `backend->update($render())` 整树重渲染；事件循环 `while($backend->step()) { $server->poll(); }`。
- `Canvas/Layout.php`：固定常量手写布局(PAD=12,ROW=32…)，仅 column/row/stack/split/list + 4 region(titlebar/toolbar/sidebar/statusbar)，无 flex/grid/绝对定位/虚拟化。
- `Element.php`：`readonly type/props/children`，`prop()` 取值。
- native 对照能力(已记录于对话)：组件含 grid/table/scroll_view/accordion/tabs/dialog/chart/tree…，布局为约束式 flexbox，渲染为 GPU canvas + design tokens，状态有 `canvas_widget_reconcile` 细粒度 diff，动画有 ticker/transition，事件含手势/滚轮/focus 遍历，a11y 有 role，自动化有 snapshot，多窗口有 window_state，另有 extensions/security/assets/embed/js-bridge。

## Technical Decisions
- 主题：新增 `src/Theme.php`，`App` 持有当前 theme，`Canvas` 从 theme 解析颜色；`Element` 可带 `style` 覆盖。
- 自定义绘制：`Ui::canvas(callable)` → 'custom' 元素，`drawNode` 调用闭包(cairo ctx+rect)。闭包不跨进程序列化，自动化快照仅取元信息。
- 布局增强：在 `Layout` 增加 grow/shrink、grid、positioned(绝对) 分支；虚拟化先做窗口化列表雏形。
- 动画：复用 `App::run` 事件循环推进 `now`；元素带 `animate` 描述，Canvas 在 paint 时按 `now` 插值。
- 多窗口：`App` 增加 `Window` 集合(windows State)，新增 `WindowManager`；headless 下可枚举/聚焦。
- Web：`Backends/Html.php` 将 Element 树渲染为 HTML+内联 JS(事件经 POST 回 PHP 同 DSL)。

## Issues Encountered
| Issue | Resolution |
|-------|------------|
| 测试需在已构建 libui3 的环境运行，CI 无构建时 Canvas 测试会崩 | `bin/run.sh` 缺失 `libui3` 时自动 `ext/build.sh`；`UI3_SKIP_BUILD=1` 可跳过（CI 独立构建步骤） |
| 原生窗口阻塞，无头环境无法跑 GUI 测试 | 示例默认 headless；`UI3_REAL_WINDOW=1` 才开真实窗口；`web_target`/`systems` 为纯 PHP 无需原生库 |
| (待填充) | |

## Resources
- ui3: /Volumes/data/git/php/ui3/src/{Ui,App,Element,Backends/Canvas,Canvas/Layout,Canvas/Node,Automation,System}
- native: /Volumes/data/git/web/native/src/{primitives, runtime, platform, automation, window_state, extensions, security, assets, js, bridge}
