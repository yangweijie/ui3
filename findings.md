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
| 缺像素级回归，"看起来对"无法证明 | 新增 `Backends/Reference.php` 纯 PHP 参考渲染器（GD 栅格化，零 FFI），`Layout::textWidth` 可注入纯 PHP 测量；`tests/ReferenceRenderTest.php` 做确定性 + 基线哈希回归（`UI3_UPDATE_BASELINE` 重建）。等价 native 的 NullPlatform + 像素对照，但仅在自身基线内回归（非跨渲染器对拍） |
| 基线文件需提交到仓库，CI 才能断言 | `tests/baselines/*.hash` 随代码提交；首次运行缺失会 markTestSkipped 并生成，二次运行断言一致 |
| 动画插值逻辑焊死在 Canvas::drawNode，headless 后端无法渲染动画帧 | 抽离 `Animation::frame()` 后端无关纯函数；`Reference` 加 `setClock`/帧渲染（opacity 经 `imagecopymerge` 与背景混合的确定性近似）；`Ticker` 提供常驻帧时钟 |
| 纯 PHP 文本测量对 CJK/IME 不精确（`mb_strlen*0.6` 单一系数） | `pureTextWidth` 改按字符类别累加（半角 0.6em / 全角 CJK 1.0em / 组合变音与 IME 组合符号 0 宽），保持零字体依赖、确定性；不引入 `imagettfbbox` 以免破坏跨机器基线 |
| PHP 8.5 弃用 `imagedestroy()`（8.0 起无效果） | `Reference::applyAlpha` 移除 `imagedestroy`，消除 deprecation 警告 |
| 实现变更后个别像素基线需刷新（basic_window） | 重建受影响的 baseline（`UI3_UPDATE_BASELINE`），controls/list 未受文本测量影响故保持不变；已验证新基线确定性 |
| headless 后端（Reference/Html）无原生事件循环，App::run() 是 no-op | `App::headless()` + `App::run()` 检测 `isHeadless()` 后改用 `Ticker` 驱动逐帧 `setClock`+`update`；复用 P1 的动画渲染能力，动画在纯 PHP 下也能整帧渲染 |
| IME 组合输入在 headless/纯 PHP 下无法体现（仅 P1 做了宽度测量） | `Ui::input` 加 `onComposition` 钩子；`Reference::composition` 存运行时状态并绘制待定文本+下划线预览；`App::composition` 注入 headless 后端并重绘 |
| Reference 渲染器对 scroll/table 不绘制内容（落入 default 边框） | 新增 `case 'scroll'`（视口背景填充）与 `case 'table'`（列头+行），对齐 Canvas 绘制 |
| Html 后端有 `anim` 属性却无任何动画驱动 | `Html::renderNode` 检测 `anim` prop，纯 PHP 生成 CSS `@keyframes`+`animation` style，浏览器驱动、零 JS/FFI |
| `Reference::applyAlpha` 用 `imagecopymerge` 后像素不变（跨图像颜色索引无效） | `applyAlpha` 在 bg 图像本地分配不透明背景色，使混合生效 |
| `Ui::input` 加 `onComposition` 参数时误置于 `id` 之前，破坏既有 4 位置调用 | 修正参数顺序（`onComposition` 置于 `id` 之后），并同步修正 ImeTest 的调用 |
| Canvas 原生后端完全不绘制 IME 组合预览（仅 Reference headless 有） | `Canvas` 增加 `$composition` 状态与 `composition()` 方法，`input`/`textarea`/`drawSearch` 绘制待定文本（accent 色）+ 下划线，与 Reference 同构；`App::composition` 已能路由到原生后端 |
| Html 后端动画时长默认 `1000` 会覆盖更短的 `duration` | `Html::renderNode` 的 `$dur` 初值改为 `0.0` 取 `max`，使 600ms 等更短动画正确生效 |
| P2 三块新能力（headless 循环 / IME / Html 动画）缺可运行示例 | 新增 `examples/headless_loop.php` / `examples/ime.php` / `examples/html_anim.php`，均零 FFI 可跑 |
| `scroll` 内容溢出无裁剪矩形（P2 文档化的已知限制），溢出内容会画到视口外 | 原生 host 用 Cairo（`cairo_save`/`cairo_clip`/`cairo_restore` 原生支持），故实现裁剪：`Layout::scroll` 子节点后追加 `scroll_end` 哨兵，`Canvas` 绘制循环用裁剪栈配对（LIFO 兼容嵌套 scroll）；`Cairo` FFI 新增上述函数；`Canvas::offscreenPixels()` 提供 headless 像素读回验证裁剪；`Layout::hitTest`/`Reference::draw` 跳过该哨兵以免误命中/改基线 |
| `scroll` 容器此前无法交互滚动（宿主 ABI 无滚轮事件，且 ↑/↓ 仅用于 list/select 浏览） | 新增 `UI3_EVENT_WHEEL = 5` 并实现三平台转发（Cocoa `scrollWheel:` / Win32 `WM_MOUSEWHEEL` / X11 Button4-5，符号统一 data>0=向下滚）；`Canvas` 加 `activeScrollId` + `scrollContainerAt()`，`POINTER_DOWN` 激活、`onKey` ↑/↓ 滚动、`onEvent` `WHEEL` 分支滚动；新增 `examples/scroll_window.php` 真实窗口示例 |
| `scroll` 容器能滚动但 Canvas 渲染无滚动条（仅有边框） | `Layout` 在 `scroll` 分支捕获 `placeColumn` 返回的内容高度缓存为 `scrollContent[id]` 并新增 `scrollContentHeight()` getter；`Canvas` 绘制循环在 `scroll_end` 弹 clip 前调用 `drawScrollbar()`；滚动条为**混合方案**（外观自绘 Design Tokens、滚动物理委托原生 WHEEL）；P8 在 `scrollBy` 按 `scrollContentHeight-视口高` 钳制上下限并产生橡皮筋弹性过冲，`paint` 每帧衰减回弹，`drawScrollbar` 实现覆盖式自动隐藏（滚动/悬停可见、闲置淡出），`onEvent` 悬停显示；`Layout::placeList` 三种 list 均缓存内容高度使 `scrollContentHeight` 对 scroll/list 通用 |

## Resources
- ui3: /Volumes/data/git/php/ui3/src/{Ui,App,Element,Backends/Canvas,Canvas/Layout,Canvas/Node,Automation,System}
- native: /Volumes/data/git/web/native/src/{primitives, runtime, platform, automation, window_state, extensions, security, assets, js, bridge}
