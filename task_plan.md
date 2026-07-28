# Task Plan — PHP FFI 跨平台 GUI 库（参考 native）

## Goal
参考 `/Volumes/data/git/web/native`（Elm 架构 + 跨平台后端 + 自动化），用 PHP FFI 实现一个跨平台 GUI 库 `Yangweijie\Ui3`，包含：
1. 一个 C 写的跨平台原生库 `libui3`（macOS Cocoa / Windows Win32 / Linux X11 + 无头 null 后端，用于 CI）。
2. PHP FFI 绑定层（基于 `kingbes/phpc` 的 `SafeCall`/`Library`/`Callback`）。
3. Elm 风格应用运行时（Model/ Msg / update / view）。
4. 一个可运行的示例（Counter）。
5. Pest 测试（纯逻辑 + FFI 冒烟 + 无头后端驱动）。
6. 自动化：GitHub Actions CI（构建 C 库 + 跑 Pest）+ 构建脚本。

## 设计要点（来自 findings.md）
- native 的核心：状态只在 `update(model, msg)` 变化；视图绑定 model、派发 msg。
- native 有 `NullPlatform` 无头后端专门用于 CI/测试 —— 我们对应做 C 的 null 后端。
- native 的 automation：快照/驱动控件/断言状态 —— 我们用 HeadlessBackend + App::dispatch 模拟。
- phpc 调用规则：所有 FFI 走 `SafeCall::invoke`；加载前 `Library::permit`；闭包→C 函数指针用 `Phpc::callback`。

## Phases
- [x] P1 编写 C 库 `ext/`（libui3.h + common + null + cocoa/win32/x11 + build.sh）
- [x] P2 编写 PHP 绑定 `src/FFI/LibUi3.php`
- [x] P3 编写运行时与声明式 UI `src/App.php` + `src/Ui.php` + `src/Backend.php`(+Backends)
- [x] P4 示例 `examples/counter.php`
- [x] P5 Pest 测试 `tests/`
- [x] P6 自动化：composer 加 pest + 脚本；`.github/workflows/ci.yml`
- [x] P7 验证：build.sh 编译、composer install、pest 运行、CI 语法检查（全部通过）

## 续：Headless → automation server（snapshot / 控件驱动 / record-replay）
- [x] A1 `Ui` 增加控件 `id` 参数（window/column/row/label/button）
- [x] A2 `src/Automation/Snapshot.php`（快照 + findById/findByText/findByRole + 轻量布局）
- [x] A3 `src/Automation/Script.php`（动作脚本 JSON 存取）
- [x] A4 `src/Automation/Recorder.php`（边操作边记录，可存盘）
- [x] A5 `src/Automation/Automation.php`（server：start/snapshot/clickById/clickText/dispatch/step/recorder/replay）
- [x] A6 示例 `examples/automation.php` + 测试 `tests/AutomationTest.php`（6 项）
- [x] A7 composer `automation` 脚本 + CI 步骤
- [x] A8 验证：修复 App::backend 挂载导致 snapshot 陈旧的问题；全部 12 测试通过

## 验证标准
- `bash ext/build.sh` 在 macOS产出 `build/libui3.dylib`。
- `php -d ffi.enable=true vendor/bin/pest` 全绿（12 passed）。
- `composer automation` 无头跑通快照/驱动/录制重放。
- FFI 冒烟测试在无头后端下不崩溃、返回非空指针。
- CI yaml 语法合法。

## 续：Automation 接 Canvas 无头（#1，已完成）
- [x] B1 `Canvas` 新增 `root()` / `layout()`；headless `run()` 改为 present 一次即返回（不阻塞自动化）
- [x] B2 `Snapshot::capture()` 接受真实布局 `$nodes`，bounds 来自 `Layout::compute`
- [x] B3 `Automation` 构造类型 `Headless` → `Canvas`；`snapshot()` 传 `$backend->layout()`
- [x] B4 修跨 FFI scope 的 `cairo_t*` 桥接（`cast`）+ `cairo_text_extents` 指针传参 + `cairo_text_extents_t` 具名 tag
- [x] B5 `ui3_host_present` 补进 PHP 侧 HEADER 副本
- [x] B6 测试/示例迁移到 `new Canvas(headless: true)`；清理多余 import
- [x] B7 验证：16 passed (65 assertions)，CANVAS DRAW ERR=0，快照带真实坐标

## 续：真实窗口事件回路（#2，已完成）
- [x] C1 Cocoa 后端：窗口关闭 → `ui3_host_quit` + `[NSApp stop]`，真实事件循环能正常退出
- [x] C2 `Automation` 指针点击改走真实事件路径：`injectPointer` + `step` → `onEvent` → `hitTest` → `fire` → `dispatch`（与真实窗口点击同一代码路径），由快照坐标驱动命中测试
- [x] C3 新增 `EventLoopTest`：真实坐标点击驱动 model + 快照、空白区域点击为 no-op（证明走命中测试而非直接 dispatch）
- [x] C4 验证：全测试通过（19 passed / 69 assertions），无头可复现真实窗口事件路由

## 续：真实窗口键盘焦点（#3，已完成）
- [x] D1 `Canvas::onEvent` KEY 分支改为按 `focusId` 路由到聚焦字段的 `onInput`（非全局 dispatch）
- [x] D2 点击 input/textarea 自动设 `focusId`；`Automation` 新增 `focus($id)` / `type($text)`
- [x] D3 `input($id,$value)` 改为 `focus + type`（真实 KEY 路径），删除直接 dispatch 绕过
- [x] D4 `ui3_host_inject_key` 补声明（libui3.h + LibUi3.php HEADER）；`Canvas` 新增 `injectKey`/`focus`/`focusedId`
- [x] D5 修 FFI 回调 `char*`→`FFI\CData` 桥接（`FFI::string`），修复启用 KEY 注入后暴露的 `TypeError`
- [x] D6 验证：`tests/FocusTest.php` 4 条通过，全量 23 passed / 79 assertions

## 续：真实窗口键盘焦点（#4，已完成）
- [x] E1 `Canvas::onEvent` KEY 分支识别 `Tab` → `moveFocus()`（布局顺序循环、wrap）
- [x] E2 `moveFocus()` 收集 FOCUSABLE 控件 id 顺序，聚焦下移（无聚焦则从首个开始）
- [x] E3 `drawNode` 对聚焦控件绘制 accent 焦点环（可见反馈）
- [x] E4 `Automation` 新增 `tab()` / `pressKey()` / `focusedId()` 透传
- [x] E5 清理重复 `ui3_host_inject_key` 声明（libui3.h + LibUi3.php）
- [x] E6 验证：`tests/TabNavTest.php` 4 条通过，全量 27 passed / 92 assertions

## 续：真实文本编辑（#5a，已完成）
- [x] F1 `Canvas::onKey` 维护 per-field buffer（text+cursor），逐字符插入 + dispatch 完整新值
- [x] F2 `type()` 改为逐字符注入（mb_str_split + 每字符 injectKey+step），走真实 KEY 路径
- [x] F3 `seedField`（初始化 buffer）、`resetField`、`fieldText`/`fieldCursor`；`input()` 先清空再逐字符
- [x] F4 `Automation` 新增 `backspace()`/`cursorLeft()`/`cursorRight()`/`fieldText()`/`fieldCursor()` 透传
- [x] F5 非文本字段聚焦时按键被 `onKey` 忽略（无编辑 buffer）
- [x] F6 验证：`tests/TextEditTest.php` 4 条通过，全量 31 passed / 104 assertions

## 续：键盘可达性（#5b，已完成）
- [x] G1 `onKey` 识别 `Shift+Tab` → `moveFocus(-1)`（反向）；`moveFocus` 带方向参数，到头 wrap 到尾
- [x] G2 `navigate()`：聚焦 list/select 时方向键移动高亮；list 的 Enter 提交 `onSelect`，select 箭头即提交 `onChange`
- [x] G3 `Canvas` 新增 `NAVIGABLE` 常量、`highlights` buffer、`seedHighlight()`、`navigate()`；list 高亮行画焦点环；点击同步 `highlights`
- [x] G4 `Automation` 新增 `shiftTab()`/`arrowUp()`/`arrowDown()`/`enter()`/`highlightIndex()`；`focus()` 放宽到任意可聚焦控件
- [x] G5 文本控件忽略上下方向键（不插入控制字符）
- [x] G6 验证：`tests/KeyboardNavTest.php` 5 条通过，全量 36 passed / 124 assertions

## 续：真实窗口键接入（#5c，已完成）
- [x] H1 `ext/common.c` 抽 `ui3_key_text(keycode,shift,chars)` 共享规范键映射；Cocoa `keyDown:` 改用它（真实键→与无头同款规范串）
- [x] H2 新增 `ui3_host_inject_raw_key`（按原始扫描码注入，复用同一翻译）；`ext/libui3.h` + `LibUi3` FFI 头声明
- [x] H3 `Canvas::injectRawKey()` / `Automation::rawKey()` 透传
- [x] H4 修复退格字面量：PHP `"\b"` 非转义（两字节 0x5C 0x62），统一用 `"\x08"`（与 C `"\b"`=0x08 一致）；`backspace()` 改发 `"\x08"`
- [x] H5 说明：win32/x11 为桩（无真实窗口），#5c 真实键路径仅在 macOS/Cocoa 落地
- [x] H6 验证：`tests/RealKeyTest.php` 5 条通过，全量 41 passed / 134 assertions；lint 0；示例无回归

## 续：真实窗口实跑验证 + 控件精度（#6，排期中）
目标：把 ① macOS 真实窗口事件回路、② Win32/X11 真实宿主、③ 控件精度（文本测量 / 滑块拖拽 / 下拉展开）合并为一个里程碑，统一验证「真实窗口」路径并补齐控件交互。
- [x] I1 验证脚手架 ✅ 已实现（UI3_REAL_WINDOW 门控，无显示器 skip）：新增平台无关「真实窗口冒烟」`tests/RealWindowSmokeTest.php` —— `headless:false` 创建窗口 + 设 draw_cb（置 `$drew=true`）+ `present()` + 多次 `step()` 触发真实绘制 + `quit()`/`destroy()`。若 `ui3_plat_create_window` 返回 -1（无显示器 → 回退无头）则 `markTestSkipped`。验证：本机 macOS 下 `framesDrawn()>0` 且 `isHeadless()===false`（draw_cb 经真实 Cocoa 路径出帧，而非 offscreen）。
- [x] I2 ① macOS 实跑：本机带显示器跑 I1 冒烟（`UI3_REAL_WINDOW=1`），确认 NSApp runloop 事件泵驱动真实窗口绘制与退出。验证：`RealWindowSmokeTest` PASS（2 assertions：isHeadless()===false 且 framesDrawn()>0，证明 cocoa_paint 经真实 Cocoa 路径出帧）。
- [x] I3 ③a 文本测量实测 ✅ ControlsTest 通过：`Cairo.php` 增加共享 scratch 表面+上下文（1×1 image surface）用于 `cairo_text_extents`；`Layout::measureText` 改用实测宽度（含换行高度），删除 `mb_strlen*$size*0.55` 估算。权衡：Layout 此前无 cairo 上下文，引入单例 scratch 表面（开销小、可控）。验证：Pest 断言已知字符串实测宽度≠估算且接近真值；长文本正确换行不溢出。
- [x] I4 ③b 滑块拖拽 ✅ ControlsTest 通过：`Canvas` 增加指针拖拽状态（`dragging` id/type）；`POINTER_DOWN` 命中 slider→起始拖拽并按 x 算值；`POINTER_MOVE` 拖拽中→更新 value+重绘+`dispatch onChange`；`POINTER_UP`→结束（点击两态同位置亦按 x 设值）。`Automation::dragSlider($id,$value)` 注入 down/move/up。验证：Pest 拖拽测试（值随 x 变化、onChange 触发）。
- [x] I5 ③c 下拉展开 ✅ ControlsTest 通过：`select` 节点增 `expanded` 态；`POINTER_DOWN` 折叠态→展开（弹出版列表）、展开态命中选项→设值+收起+`dispatch onChange`；点外部 / Esc→收起。绘制：展开时在下拉框下方渲染选项行（裁剪到窗口）。键盘方向键保持（`navigate` 提交）。`Automation` 增 `toggleSelect` / `clickSelectOption`。验证：Pest 测试展开 / 点选 / 收起。
- [ ] I6 ② 跨平台实跑（待 Linux/Windows 真机，本机 macOS 无法编译）：runbook 见 `progress.md` 续13。已修 Windows 两处加载 bug（`build.sh` 产物改 `libui3.dll`；`bin/run.sh` 加 MinGW `PATH`）。待在 Linux(X11)/Windows(Win32) `ext/build.sh` 编译 + `UI3_REAL_WINDOW=1` 跑 `RealWindowSmokeTest`，断言 `isHeadless()===false` 且 `framesDrawn()>0`。
- [ ] I7 收尾：`composer test` 全绿；`ext/build.sh` 三平台编译（本机仅 macOS）；lint 0；回填 progress.md 验证记录。

说明：③a/③b/③c 均为 PHP 改动（`Canvas`/`Layout`/`Cairo`/`Automation`），可本机无头 Pest 全量验证；①② 实跑需带显示器的环境（本机仅 macOS 可验 ①，② 需 Linux/Windows）。
