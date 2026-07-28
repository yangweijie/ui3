# Findings

## 参考项目 native（/Volumes/data/git/web/native）
- Zig 写的跨平台 GUI SDK，Elm 架构：Model / Msg / update / view。
- 跨平台后端：macOS(Cocoa/Metal)、Linux(X11/软件渲染)、Windows(Win32)、NullPlatform(无头, CI 用)。
- automation server：快照、控件驱动、record/replay、确定性截图。
- 关键文件：`examples/hello/src/main.zig`（App 结构）、`examples/hello/src/runner.zig`（按平台分派 runMacos/runLinux/runWindows/runNull）。

## 工具链（本机）
- PHP 8.5.7，composer 可用，zig 可用，clang(llvm@16) 可用，macOS arm64。
- GUI 在 CI 无显示器无法验证渲染，因此必须有无头后端用于测试。

## phpc API（vendor/kingbes/phpc）
- `Library::permit('libui3')` 后才能 `Library::load('libui3', $header)`（`FFI::cdef`）。
- 所有 C 调用走 `SafeCall::invoke($ffi, $func, $args)`；非空指针用 `expectNotNull`；返回 0 用 `expectZero`。
- 闭包→C 函数指针：`Phpc::callback($ffi, $closure, 'void(*)(void*)')` 返回对象，`->raw()` 取指针。
- `Phpc::wrap`/CData 做 RAII；`Pointer::isNull` 判断空指针。

## 设计决策
- C ABI：`ui3_app_create/destroy`, `ui3_label_create`, `ui3_button_create`, `ui3_widget_set_text`, `ui3_button_on_click(btn, cb, userdata)`, `ui3_app_set_root`, `ui3_app_run`, `ui3_app_step`, `ui3_app_quit`, `ui3_set_backend("auto"|"null")`。
- Elm 运行时 `App`：`model`/`update`/`view`，`dispatch(msg)` 更新 model 并 `update()` 推给后端；`render()` 返回视图树（可单测）。
- 后端接口 `Backend`：`mount(tree, dispatch)`, `update(tree)`, `run()`, `quit()`。
  - `NativeBackend`：FFI 调用 libui3；按钮点击经 C→PHP 闭包调 dispatch。
  - `HeadlessBackend`：纯 PHP，记录挂载/文本/派发，可模拟点击——让 Pest 逻辑测试不依赖 C 构建。
- 无头后端（C null）也供 FFI 冒烟测试使用，避免打开真实窗口。
