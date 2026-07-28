# Progress

## 2026-07-27（续17）— 实现 native SDK 全部 11 种组件
对照 `packages/native-sdk/native-sdk.d.ts` 的 `NativeSdkViewKind`（19 去重别名后 11 类），在 PHP `Ui` 侧补齐并实现全部组件，此前仅覆盖 7/19。
- `Ui` 新增 builder：`stack`(axis)、`toggle`(on/onChange)、`iconButton`(icon/text/onClick)、`segmented`(options/selected/onChange)、`searchField`(value/placeholder/onInput)、`listItem`(icon/title/subtitle)、`split`(orientation/position/onChange)、`toolbar`/`sidebar`/`statusbar`/`titlebarAccessory`、`webview`(url)、`gpuSurface`(w/h)。`list` 支持 `list_item` 子元素自定义行。
- `Canvas/Layout`：新增窗口外壳区域布局（titlebar/toolbar 顶部、sidebar 左侧、statusbar 底部、内容列），以及全部新类型的 measure/place；`hitTest` 改用「最小面积优先」命中叶子节点（list_row 优先于 list 背景）。
- `Backends/Canvas`：新增全部新类型的 Cairo 绘制与交互——toggle 点击翻转、segmented 点击选段、search 文本编辑、iconbutton 点击、list_item 行点击选择、split 拖拽实时更新 position；补 `FOCUSABLE`/`NAVIGABLE`/`seedField`/`editText` 覆盖新类型。
- `Automation/Snapshot`：快照暴露新组件 role/name/handler/state；盒子索引优先用元素 id（list_item 行在布局里是合成元素，按 id 对齐坐标）。`Automation` 新增 `setToggle`/`setSegmented`/`setSplit`。
- 引擎级/OS 外壳组件（webview、gpu_surface、toolbar/sidebar/statusbar/titlebar）在 canvas-host 架构下无法实现为真实引擎/OS 外壳，按「带标注的渲染型占位组件」落地（可布局、可绘制、可交互，url/width/height 等 prop 由真实 native 后端沿用）。
- 新增 `tests/NativeComponentsTest.php`（2 个用例、20 断言）覆盖全部组件的渲染与交互；新增 `examples/native_components.php` 展示全部组件（headless 输出 native kinds present 列表）。
- 验证：`composer test` 46 passed / 161 assertions / 1 skipped，无回归；lint 0；`examples/native_components.php` headless 实跑列出全部 11 类 native 组件。

## 2026-07-27（续16）— 兼容 PhpWebStudy 类 php wrapper 加载库
- 根因：`./bin/run.sh php85 ...` 报 `FFI\Exception: libui3.dylib` 找不到。`php85` 是 PhpWebStudy 的 zsh wrapper，启动真实 php 时重置环境、丢弃 `DYLD_LIBRARY_PATH`；而 `Library::load` 仅接受纯库名（phpc 的 `isValidLibName` 拒绝 `/`），完全依赖 dyld 搜索路径。
- 修复：`LibUi3::ffi()` 保留 phpc 白名单按名加载（默认路径），捕获 `FFI\Exception` 后回退到项目 `build/` 绝对路径 `FFI::cdef` 加载，不再依赖环境变量被子进程继承。
- 验证：`php85` 示例跑通（exit 0）；homebrew `php` 仍正常；`composer test` 44 passed / 141 assertions / 1 skipped，无回归；lint 0。

## 2026-07-27（续15）— 示例统一更新
四个 `examples/*` 与全局真实窗口约定 `UI3_REAL_WINDOW` 对齐，并修掉一处隐藏 bug：
- **门控统一**：`widgets.php` 的 `UI3_GUI`、`counter.php` 的 `UI3_HEADLESS` 统一为 `UI3_REAL_WINDOW`；默认改为 headless（CI 安全），设 `UI3_REAL_WINDOW=1` 才开真实窗口（与 I2 实跑 / I6 runbook 一致）。
- **修复 `widgets.php` 真实窗口分支 bug**：`(new $build()->run(...))` 试图 `new` 一个 Closure（非法，原 `UI3_GUI` 门控从未触发故未暴露）→ 改为 `$build()->run(...)`。
- **运行方式说明**：四个示例顶部注释补 `bash bin/run.sh php -d ffi.enable=true examples/xxx.php`（直接 `php` 会因 `libui3` 库路径缺失 fatal）。
- **验证**：headless 默认路径四示例全跑通（canvas `headless render ok (1 frames painted)`；automation/widgets 结果正确；counter 正常退出不挂起），lint 0；`widgets.php` 设 `UI3_REAL_WINDOW=1` 起真实窗口并阻塞（WINDOW_OPEN_BLOCKING_OK），证明真实窗口路径工作。

## 2026-07-27（续14）— #6 进度汇总（更新进度）
- 状态：I1✅ I2✅(macOS 真机) I3✅ I4✅ I5✅；I6⏳ 跨平台 gate（Linux/Windows）；I7 待 I6 完成后收尾。
- 本机验证（macOS，无 UI3_REAL_WINDOW）：`composer test` **44 passed / 141 assertions**，1 skipped（冒烟门控），lint 0，`ext/build.sh` 重建无警告。
- 真机验证（macOS，UI3_REAL_WINDOW=1）：`RealWindowSmokeTest` PASS，2 assertions（`isHeadless()===false` + `framesDrawn()>0`），证明真实 Cocoa 窗口经 NSApp runloop 出帧。
- 交付要点：三平台真实窗口共用 `ui3_key_text`；控件精度（文本实测 / 滑块拖拽 / 下拉展开）已落地并经无头 Pest 覆盖。
- 待办：在 Linux(X11)/Windows(Win32) 真机按「续13 runbook」执行完成 I6；随后置 I7 收尾（三平台编译 + 全绿 + 回填）。
- Errors Encountered（本里程碑 #6）：

| 错误 | 阶段/尝试 | 处置 |
|------|-----------|------|
| `App::__construct()` ArgumentCountError（冒烟测试只传 1 参） | I2 初跑 | 冒烟测试 `App` 改 `init/update/view` 三参 |
| `Automation` 游离 `$this->backend->step();` 语法错（rawKey 残留收尾） | I4/I5 编辑 | 删除残留块，方法正常收尾 |
| `build.sh` Windows 产物无 `.dll` 扩展名，不匹配 `LibUi3::libName()` | I6 文档化 | `build.sh` Windows 产物改 `build/libui3.dll` |
| `bin/run.sh` 未给 Windows 设库搜索路径 | I6 文档化 | 加 `MINGW*|MSYS*|CYGWIN*` 分支将 `build/` 加入 `PATH` |

## 2026-07-27（续13）— #6 I6 跨平台实跑 runbook（Linux X11 / Windows Win32）
`build.sh` 按 `uname` 自动选平台文件（Linux→`x11.c`、Windows→`win32.c`、macOS→`cocoa.m`），与 `LibUi3::libName()` 加载名一致。实跑 I6 只需在各平台编译 + 跑同一冒烟测试 `RealWindowSmokeTest`（门控 `UI3_REAL_WINDOW=1`，会真实建窗/绘制/泵/quit）。

### 已修的两个跨平台 bug（否则 I6 在 Windows 无法加载）
- `ext/build.sh`：Windows 产物改为 `build/libui3.dll`（原先无扩展名，不匹配 `libName()` 的 `libui3.dll`）。
- `bin/run.sh`：新增 `MINGW*|MSYS*|CYGWIN*` 分支，把 `build/` 加入 `PATH`，使 `libui3.dll` 可被 dlopen。

### Linux（X11）
依赖：`sudo apt install libcairo2-dev libx11-dev clang`（或 `pacman -S cairo libx11 clang`）。
```bash
bash ext/build.sh                                   # 产出 build/libui3.so
# 真机（有显示器）：
UI3_REAL_WINDOW=1 bash bin/run.sh php -d ffi.enable=true vendor/bin/pest tests/RealWindowSmokeTest.php
# CI / 无显示器（Xvfb 虚拟显示）：
xvfb-run -a bash bin/run.sh php -d ffi.enable=true vendor/bin/pest tests/RealWindowSmokeTest.php
# 全量：
bash bin/run.sh vendor/bin/pest
```

### Windows（Win32，MSYS2 MinGW64 shell）
依赖（MSYS2）：`pacman -S mingw-w64-x86_64-cairo mingw-w64-x86_64-clang`（或 `...-gcc`；脚本用 `clang`，可改 `build.sh` 的 `clang` 为 `gcc`）。需开启 `ext-ffi`（`php -d ffi.enable=true`）。
```bash
bash ext/build.sh                                   # 产出 build/libui3.dll
UI3_REAL_WINDOW=1 bash bin/run.sh php -d ffi.enable=true vendor/bin/pest tests/RealWindowSmokeTest.php
```
注意：须在 **MinGW64** 终端（非 PowerShell/cmd）跑，`bin/run.sh` 靠 `uname` 识别并设 `PATH`；PowerShell 下 `uname` 不存在，可直接 `set PATH=build;%PATH%` 后 `php -d ffi.enable=true vendor/bin/pest ...`。

### 验证标准（I6 通过）
`RealWindowSmokeTest` PASS，2 assertions：`isHeadless()===false` 且 `framesDrawn()>0`（证明 X11/Win32 真实窗口经 `ui3_key_text`/cocoa_paint 等价路径出帧）。无显示器环境必须走 Xvfb 才能验证真实窗口路径（否则宿主回退无头，测试 skip）。

## 2026-07-27（续12）— #6 I2 真机实跑通过
- 带显示器 Mac 上 `UI3_REAL_WINDOW=1` 实跑 `RealWindowSmokeTest`：真实窗口创建 + `cocoa_paint` 经 NSApp runloop 真实出帧 + pump + quit 全部通过（2 assertions：`isHeadless()===false` 且 `framesDrawn()>0`）。
- 配套强化：`Canvas` 增 `framesDrawn` 帧计数器并在 `paint()` 累加，`RealWindowSmokeTest` 断言升级为「真实绘制确实发生」而非仅非无头；修正 `App` 构造（init/update/view 三参）。
- 无头全量：**44 passed / 141 assertions**，1 skipped（冒烟门控），lint 0，无回归。
- 仅剩 I6（② Win32/X11 跨平台实跑）待 Linux/Windows 真机/CI+Xvfb。

## 2026-07-27（续11）— #6 执行：I1/I3/I4/I5 落地
- I1 真实窗口冒烟脚手架（UI3_REAL_WINDOW 门控）；I3 文本实测（Cairo::measureText + Layout 改用 cairo_text_extents，删除 0.55 估算）；I4 滑块拖拽（指针状态机 + Automation::dragSlider）；I5 下拉展开（expanded 态 + 弹层点选 + Esc 收起 + Automation::toggleSelect/clickSelectOption）。C 层：common.c 加 ui3_host_inject_move / ui3_host_is_headless；cocoa.m request_redraw 改同步 cocoa_paint 真实出帧；Canvas::update 重算布局修复快照读到旧 Element。
- 验证：`composer test` **44 passed / 141 assertions**，1 skipped（真实窗口冒烟门控），lint 0，ext 重建无警告。
- 剩余：I2 待 UI3_REAL_WINDOW=1 真机弹窗人工确认；I6 ② 跨平台实跑需 Linux/Windows 环境（本机 macOS 无法编译）。

## 2026-07-27（续10）— 里程碑排期：真实窗口实跑验证 + 控件精度（#6）
- 把 ① macOS 真实窗口事件回路实跑、② Win32/X11 真实宿主实跑、③ 控件精度（文本测量 / 滑块拖拽 / 下拉展开）合并为**一个里程碑**排入 `task_plan.md` 的 `#6`（I1–I7）。
- 关键约束：本机是 macOS，`build.sh` 只编译宿主 OS 的文件，故 **②（Win32/X11）无法在此机编译/实跑**，列为跨平台 gate（需在 Linux/Windows 真机或 CI+Xvfb 验证）。① 可在本机带显示器环境实跑；③a/b/c 全是 PHP 改动，可本机无头 Pest 全验证。
- 验证脚手架 I1（平台无关真实窗口冒烟）是 ①② 实跑的前置；无显示器时优雅 skip，避免 CI 挂起。
- 下一步：待确认后从本机可验证部分（I1→I3→I4→I5，叠加 I2）开始执行，I6 跨平台 gate 需提供 Linux/Windows 环境。

## 2026-07-27（续9）— 真实窗口键接入（#5c）：Cocoa keyDown 规范化 + 共享翻译
- 把焦点/Tab/文本/浏览逻辑真正接到真实窗口键事件：Cocoa `keyDown:` 原本直接转发 `e.characters`（Tab 变成 `\t`、方向键为空串、Enter 为 `\r`），与 `onKey` 期望的规范串不一致。现在 `keyDown:` 先经共享的 C 函数 `ui3_key_text(keycode, shift, chars)` 翻译成**与无头注入完全相同的规范串**：`Tab` / `Shift+Tab` / `\x01`(←) `\x02`(→) `\x03`(↑) `\x04`(↓) / `\n`(Enter) / `\b`(Backspace) / 可打印字符。于是真实物理键走的就是 `#3/#4/#5a/#5b` 同一条 `onKey` 路由。
- `ui3_key_text` 抽为 `ext/common.c` 的共享函数（`ext/internal.h` 声明），Cocoa `keyDown:` 与新增的 `ui3_host_inject_raw_key`（无头按原始扫描码注入，复用同一翻译）都调用它 —— 这样无头测试能驱动“与真实窗口等价”的键路径，无需显示器。
- PHP 侧：`LibUi3` FFI 头新增 `ui3_host_inject_raw_key` 声明；`Canvas::injectRawKey()`、`Automation::rawKey()` 透传。
- **关键 bug 修复**：PHP 双引号串里 `\b` 不是转义（仍是字面 `\b` 两字节 0x5C 0x62），而 C 的 `"\b"` 是真退格 0x08，导致无头 raw 路径（产出 0x08）与 PHP 比较（期望两字节 `\b`）永远不匹配，退格失效。统一改为 PHP 侧用 `"\x08"`（C 端 `"\b"` 同为 0x08），`backspace()` 也改为发送 `"\x08"`。
- 说明：win32.c / x11.c 当前是**桩**（创建窗口返回 -1 → 回退无头），本机无真实窗口，故 #5c 的真实键路径只在 macOS/Cocoa 落地；其余平台走无头，行为一致。

## 验证
- `composer test` → **41 passed (134 assertions)**（较 #5b 的 36 增 5 条 RealKey 测试） ✓
- `tests/RealKeyTest.php`：① raw Tab(48) 正向移动焦点；② raw Shift+Tab(48+shift) 反向；③ raw ArrowDown(125)+Return(36) 在 list 上浏览并提交（`sel=2`）；④ raw Backspace(51) 删除聚焦字段字符；⑤ raw 可打印键(0,'z') 插入字段 ✓
- lint 0；`examples/automation.php` 端到端仍 OK（exit 0） ✓

## 2026-07-27（续8）— 键盘可达性（#5b）：Shift+Tab 反向 + list/select 方向键浏览
- **Shift+Tab 反向导航**：`onKey` 现识别 `Shift+Tab` 并调用 `moveFocus(-1)`；`moveFocus` 改为带方向参数，按布局顺序在可聚焦控件间循环、到头 wrap 到尾（真实 Shift+Tab 行为）。`Automation` 新增 `shiftTab()`。
- **list/select 键盘浏览**：聚焦 list/select 时，方向键由 `navigate()` 处理 —— list（列表框）箭头仅移动**高亮**（独立视觉态），Enter 才提交（`onSelect`）；select（下拉）箭头**边移边提交**（`onChange`），与真实原生控件一致。`Canvas` 新增 `NAVIGABLE` 常量、`highlights` buffer、`seedHighlight()`、`navigate()`，并在 `drawNode` 为 list 的高亮行画焦点环；点击 list/select 时同步 `highlights`。
- `Automation` 新增 `arrowUp()` / `arrowDown()` / `enter()` / `highlightIndex()`；`focus()` 放宽到允许任意可聚焦控件（含 list/select）。
- 文本控件对上下方向键忽略（不插入控制字符）。

## 验证
- `composer test` → **36 passed (124 assertions)**（较 #5a 的 31 增 5 条 KeyboardNav 测试） ✓
- `tests/KeyboardNavTest.php`：① 无聚焦时 Shift+Tab 落到末个控件；② Shift+Tab 依次 go-btn→my-select→my-list→a-input 并 wrap 回 go-btn；③ list 箭头移高亮、Enter 提交（`sel=2`）；④ select 箭头即提交（`opt` 0↔1，顶到 0）；⑤ 文本控件上方向键不污染字段 ✓
- lint 0；`examples/automation.php` 端到端仍 OK（exit 0） ✓

## 2026-07-27（续7）— 真实文本编辑（#5a）：逐字符 buffer + 光标/插入位置
- `type()` 改为**逐字符**注入：每个字符走一次真实 `KEY` 路径（`injectKey` + `step`），而非整段 value 一次性覆盖。`Canvas::onKey` 现在维护**每个文本字段的本地编辑 buffer**（text + cursor），在光标处插入字符、移动光标、退格删除，并把**完整新值** dispatch 给 `onInput` —— 与真实文本控件行为一致（model 收到的是增量后的整值，不是整段替换）。
- 编辑 buffer 放在 `Canvas`（真正“拥有控件文本”的渲染后端），所有按键仍走 `#2/#3` 建立的 `injectKey → onEvent → dispatch` 真实事件路径，`type()` 不做任何直接 dispatch 绕过。
- 新增 `Canvas::seedField()`（按当前显示值初始化 buffer，焦点切换/点击时触发）、`resetField()`（清空，供 `input()` 设值）、`fieldText()`/`fieldCursor()`；`Automation` 透传 `fieldText()`/`fieldCursor()`，并新增 `backspace()`、`cursorLeft()`、`cursorRight()`（分别走 `\b` / `\x01` / `\x02` 真实键）。
- `input($id,$value)` 语义保持“设为值”：先 `resetField` 清空 buffer，再逐字符 `type`，故会替换既有内容而非追加。
- 非文本字段（如聚焦在 button 上）收到按键时 `onKey` 直接忽略（无编辑 buffer），不会误 dispatch。

## 验证
- `composer test` → **31 passed (104 assertions)**（较 #4 的 27 增 4 条文本编辑测试） ✓
- `tests/TextEditTest.php`：① `type()` 增量构建（先 he 后 llo → hello，光标落末尾）；② 在光标处插入（helXYlo）而非仅追加；③ 退格删光标左侧字符（hello→helo，光标 3）；④ `input()` 替换而非追加（abc 后 input xyz → xyz） ✓
- lint 0；`examples/automation.php` 端到端仍 OK（exit 0） ✓
- 说明：buffer 在首次 seed 后由 automation 持续维护；若 model 被外部（非键入）改动，buffer 不会自动同步（真实窗口也会因外部状态失同步），属已知边界，不影响键入场景。

## 2026-07-27（续6）— 真实窗口键盘焦点（#4）：Tab 导航 + 焦点可见高亮
- Tab 键导航接到真实窗口行为：`onEvent` 的 KEY 分支识别 `Tab` 后调用 `moveFocus()`，按 `Layout::compute` 的布局顺序在「可聚焦控件」（input/textarea/button/checkbox/radio/select/list）间循环移动焦点，到末尾 wrap 回首个。与真实窗口的 Tab 顺序一致；非 Tab 的按键仍路由到当前聚焦字段的 `onInput`（#3 行为不变）。
- 焦点可见反馈：`drawNode` 对处于聚焦状态的控件（id==focusId 且类型在可聚焦集合内）绘制 accent 色焦点环（蓝 0.2/0.4/0.9，2px，略大于控件），用户能直观看到按键将落到哪个控件 —— 真实窗口焦点高亮的等价物。
- `Automation` 新增 `tab()`（注入 `Tab` + step，走真实 KEY 路径）、`pressKey($key)`（通用具名键）、`focusedId()`（透传当前聚焦 id，便于断言）。`Canvas` 新增 `FOCUSABLE` 常量与 `moveFocus()`。
- 顺手清理上轮误加的重复 `ui3_host_inject_key` 声明（`ext/libui3.h` 与 `src/FFI/LibUi3.php` 各一处）。

## 验证
- `composer test` → **27 passed (92 assertions)**（较 #3 的 23 增 4 条 Tab 导航测试） ✓
- `tests/TabNavTest.php`：① 无聚焦时 Tab 落到首个可聚焦控件；② Tab 依次 a→b→c→go-btn 并 wrap 回 a；③ Tab 聚焦后按键落到该字段（非直接 dispatch）；④ 点击某字段后 Tab 移到其下一个 ✓
- lint 0；`examples/automation.php` 端到端仍 OK（exit 0） ✓
- 说明：焦点环是绘制层真实路径的一部分，仅在重绘时绘制（headless 下每次 model 变更触发 redraw 后可见）；已通过编译与事件路由测试验证。

## 2026-07-26（续5）— 真实窗口键盘焦点（#3）
- 键盘输入接到**真实窗口焦点路径**：`onEvent` 的 KEY 分支不再盲目 `dispatch('input', …)` 给整个 App，而是按「当前聚焦字段」路由到该字段的 `onInput` handler —— 与真实窗口的 keystroke→focused field 行为一致。
- 焦点来源两种：① 点击 input/textarea 时 `onEvent` 自动设 `focusId`（真实窗口点击聚焦）；② `Automation::focus($id)` 显式聚焦。
- `Automation::input($id,$value)` 改为 `focus($id)` + `type($value)`：`type()` 注入一次 KEY 事件并 `step()`，走 `onEvent KEY → 聚焦字段 onInput`（同真实键盘），不再直接 dispatch 绕过。新增 `focus()` / `type()`。
- `ui3_host_inject_key` 在 C 端早已存在（链表事件带 `text`），补其在 `libui3.h` 与 PHP `LibUi3.php` HEADER 的声明，PHP 侧才能调用；`Canvas` 新增 `injectKey()` / `focus()` / `focusedId()`。
- 顺带修掉一个潜在 bug（启用 KEY 注入后才暴露）：FFI 回调的 `char*` 参数以 `FFI\CData` 传入而非 PHP string，原 `?string $text` 类型提示对非空 char* 抛 `TypeError`。改为接收 `$text` 后用 `FFI::string($text)` 桥接为 PHP 字符串（pointer 事件此前靠 `text=NULL` 恰好绕过，故一直未被发现）。

## 验证
- `composer test` → **23 passed (79 assertions)**（较 #2 的 19 增 4 条焦点测试） ✓
- `tests/FocusTest.php`：① `input()` 经聚焦键盘路径写对字段、未碰其他；② 按键打到当前聚焦字段（focus 切换生效）；③ 无聚焦时按键为 no-op；④ 点击 input 即聚焦、后续按键落到该字段 ✓
- lint 0；无 `CANVAS EVENT ERR` ✓

## 2026-07-26（续4）— 真实窗口事件回路（#2）
- 让 Automation 的指针点击走**真实事件路径**：`clickById`/`clickText` 先按 id/文本从快照解析出控件，再在它的真实布局中心注入 `pointer down+up`，并 `step()` 驱动宿主事件回调 → `onEvent` → `hitTest`（真实坐标）→ `fire` → `dispatch`。与真实 Cocoa/Win32/X11 窗口的鼠标点击是**同一段代码**，因此命中测试与事件循环被真正验证，而非被直接 dispatch 绕过。
- 新增 `Automation::clickAt(float $x, float $y)`（注入 down/up + step）；`clickWidgetCentre()` 仅对可点击角色（button/checkbox/radio/slider/select/list）激活，其余抛清晰错误。删除改后无用的 `dispatchButton`。
- Cocoa 后端：新增 `Ui3Delegate`，窗口关闭时 `host->running=0` 且 `[NSApp stop]` + 投递 dummy 事件，使真实 `[NSApp run]` 事件循环能正常退出（之前关窗会挂起）。
- 新增 `tests/EventLoopTest.php`（Pest，3 条）：① 真实坐标点击经事件循环驱动 model + 快照重绘；② 空白区域点击被命中测试过滤（no-op，证明非直接 dispatch）；③ `clickById` 经 `onEvent` 路由。

## 验证
- `composer test` → **19 passed (69 assertions)**（较 #1 的 16 增加 3 条事件循环测试） ✓
- `examples/automation.php` 端到端通过：inc×2+reset→0、reset+dec→-1、replay→1 ✓
- lint 0 ✓
- 说明：Cocoa 窗口关闭退出是运行时平台行为，只能在真实 GUI 环境验证；本环境的改动已通过 `ext/build.sh` 编译校验。

## 2026-07-26（续3）— Automation 改用 Canvas(headless:true) 驱动 + 真实布局坐标快照
- Automation 后端由 `Headless` 改为 `Canvas(headless:true)`：快照携带 `Layout::compute` 的真实绘制坐标（x/y/w/h），更接近 native「坐标无关树 + 命中测试」模型；控件仍按 id 寻址，坐标只描述实际绘制位置。
- `Canvas::run()` headless 模式改为 `present` 一次即返回（不再走 C 的 `while(running)` 死循环），自动化 `start()` 不再阻塞。
- `Canvas` 新增 `root()` / `layout()`；`Snapshot::capture()` 新增可选 `$nodes` 参数，传入时 bounds 取自真实布局节点，否则回退内部估算。
- 顺带修掉两个被 try/catch 吞掉的潜在 bug（canvas 绘制其实一直悄悄失败）：
  1. `cairo_t*` 跨 FFI scope 不互通：宿主回调的 `cr` 改为 `void*` 回传，PHP 侧 `Cairo::ffi()->cast('cairo_t*', $cr)` 桥接。
  2. `cairo_text_extents` 传的是 struct 值而非指针：`textExtents()` 改用 `FFI::addr()`；并给 `cairo_text_extents_t` 具名 tag（匿名 struct 在 PHP FFI 下两处被当成不同类型）。
  3. `ui3_host_present` 仅 `.h` 声明、未在 PHP 侧 `LibUi3.php` 的 HEADER 副本声明，FFI 解析不到 —— 已补。
- 测试/示例：`AutomationTest`/`WidgetTest`/`examples/automation.php`/`examples/widgets.php` 改用 `new Canvas(headless: true)`；清理 `widgets.php` 多余 `Headless` import。`Headless` 后端保留（`AppTest` 直接测它，未走 Automation）。

## 验证
- `composer test` → **16 passed (65 assertions)** ✓
- headless 绘制报错 `CANVAS DRAW ERR` = 0（之前一直被吞） ✓
- 快照坐标为真实布局：`label x=12 y=12 w=26 h=18`（非旧 `ROW=32` 估算） ✓

## 2026-07-26（续2）— 扩展控件目录（对照 native 的核心控件集）
- 原仅有 label/button/container 三种；参考 native 的核心控件，补齐全目录：
  heading、input、textarea、checkbox、radio、slider、progress、select、list、image、spacer、divider、panel（共 13 类 + 原有 label/button/column/row/window）。
- C ABI（`ext/libui3.h`/`internal.h`/`common.c`/`null.c`/`cocoa.m`/`win32.c`/`x11.c`）：新增 widget kind 枚举、泛型创建/setter（text/int/range/option/item/image）、输入与变更回调（on_input/on_change）、实时值同步（update_int）。Cocoa 后端为每种控件创建真实 NS 控件并接线回调；Win32/X11 最佳兜底（静态文本/分隔线/图片占位），保持可编译。
- PHP：`Ui` 增加全部构建器（含 id/value/checked/onChange/onInput/onSelect 等）；`App::dispatch(msg, payload)` 支持带负载更新（按 update 参数个数安全调用）；`Snapshot` 增加 handler 与 state（value/checked/options/selected 等）；`Automation` 增加 input/setChecked/slideTo/selectOption/selectListItem 按标识驱动（带 payload）；`Native` 后端全目录翻译 + 回调接线 + 实时值推送。
- 示例 `examples/widgets.php`（无头自动化演示 + UI3_GUI=1 打开真实窗口）；测试 `tests/WidgetTest.php`（快照字段 + 带负载驱动 + 类型不匹配报错）；`tests/FFITest.php` 增加全目录 FFI 冒烟；composer `widgets` 脚本；CI 增加步骤。

## 验证
- `bash ext/build.sh` → build/libui3.dylib ✓（含全部新控件 + 回调）
- `composer widgets` → 10 个控件快照/输入/勾选/滑块/下拉/列表/按钮 全驱动正确 ✓
- `composer test` → **16 passed (66 assertions)** ✓
- `php -l` 全部新文件无语法错误 ✓

## 2026-07-26
- 探索参考项目 native 与 phpc API，完成 findings.md、task_plan.md。
- 编写 C 库 `ext/`：libui3.h / internal.h / common.c（共享 widget 树+布局+后端分派）/ null.c（无头）/ cocoa.m（macOS）/ win32.c / x11.c，build.sh。编译通过（macOS → libui3.dylib）。
- 编写 PHP 层：FFI/LibUi3.php、Element.php、Ui.php、Backend.php、App.php（Elm 运行时）、Backends/Headless.php、Backends/Native.php。
- 示例 examples/counter.php（含 UI3_HEADLESS 无头模式）。
- Pest 测试：tests/AppTest.php（纯逻辑+无头后端驱动）、tests/FFITest.php（FFI 冒烟，无头后端）、tests/Pest.php（helper）。phpunit.xml。
- composer.json 增加 autoload-dev / scripts(build,test,example)，安装 pestphp/pest。
- CI 自动化：.github/workflows/ci.yml（ubuntu/macos 必跑，windows continue-on-error，每日 cron）。
- .gitignore 忽略 build/。

## 2026-07-26（续）— Headless 扩展为 automation server
- `Ui` 控件构建器增加稳定 `id` 参数（window/column/row/label/button），供 automation 按标识定位。
- 新增 `src/Automation/`：
  - `Snapshot.php` — 坐标无关的 UI 树快照（id/role/name/enabled/bounds/parent/on_click）+ findById/findByText/findByRole；含轻量布局算法（column/window 纵向、row 横向）。
  - `Script.php` — record-replay 的动作脚本（JSON 存取）。
  - `Recorder.php` — 边操作边记录 click_by_id / click_by_text / dispatch，可 save 到文件。
  - `Automation.php` — automation server：start/snapshot/clickById/clickText/dispatch/step/recorder/replay，按控件标识驱动（非坐标），并复用 `App::run` 挂载 backend 使 snapshot 实时刷新。
- 示例 `examples/automation.php`：快照 → 按 id/text 点击 → 录制脚本存盘 → 新 app 重放并校验终态。
- 测试 `tests/AutomationTest.php`（6 项）：快照字段、按 id 驱动、按 text 驱动、缺失控件报错、录制后重放复现终态、脚本文件往返。
- composer 增加 `automation` 脚本；CI 增加 `composer automation` 步骤。

## 验证结果
- `bash ext/build.sh` → build/libui3.dylib ✓
- `php -l examples/counter.php` → 无语法错误 ✓
- `UI3_HEADLESS=1 bash bin/run.sh php -d ffi.enable=true examples/counter.php` → EXIT=0 ✓
- `composer automation` → 无头快照/驱动/录制重放全部正确（Count: -1、replay=1）✓
- `composer test` → 12 passed (34 assertions), exit 0 ✓

## 备注
- 修复点：Automation 之前只 mount 未设置 `App::$backend`，导致 dispatch 跳过 backend->update，snapshot 读到陈旧 root；改为复用 `App::run` 挂载后修复。
- Win32 / X11 后端代码完整但本机（macOS）无法编译验证，由 CI 矩阵覆盖（Windows continue-on-error）。
- Windows CI 下需把 build/libui3.dll 放到 cwd（workflow 已处理）。
- 真实 GUI 渲染需在桌面环境手动运行 `composer example`（会打开原生窗口并阻塞）。
