# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

## [1.0.1] - 2026-11-24

### Added
- 控制台命令「两段式工具面」（`wise-agent.console.two_stage`，默认关闭，可用 `AI_AGENT_TWO_STAGE=1` 临时开启）：
  只下发 `cmd_search` + `cmd_run` 两个工具，具体 `cmd_*` 以 hidden 方式留在 ToolRegistry
  （不下发但仍可被 ToolSecurityCheck 按 `ai.cmd.{name}` 校验）。实测单步工具 schema 由
  4,382 token 降至 1,638（−63%），一次 6 步执行约省 16k input token。
- `ToolRegistry::hide()/show()/isHidden()`：工具可「注册但不下发」，仅影响 `getToolSchemas()`。
- `wise:agent:chat --dry-run`：打印将发送的 messages / tools / token 估算（含逐工具 token），不调用模型；
  `--dry-run --raw` 输出纯 JSON（可 diff / 管道）。
- `TokenEstimator::countTools()`：工具 schema 的 token 估算（此前把 schema 当消息计数，低估约 15 倍）。
- `wise:agent:chat --file`、`--interactive`；Windows 控制台默认拦截交互模式并给出替代用法。

### Changed
- AI 助手前端入口收敛到顶栏：点击顶栏机器人图标直接打开居中弹窗（原为打开右侧抽屉），
  Shift+A 不变；**移除右下角浮动按钮**（常驻右下角会遮挡分页器 / 表格末行 / 批量操作条）。
  右侧抽屉形态同步下线，`agentChat.toggleDrawer()` 保留为「打开弹窗」的兼容别名。

- AI 助手前端模块注册优先级由 100 调整为 9999：常驻浮层刻意排在 `admin.frontend.module` 清单末尾，避免顶栏入口类模块（如 wise-health-check=100）被挤到后面；该优先级同时决定前端挂载次序

- 后台菜单改为「挂靠容器」声明：`MenuItemProvider::parent()` → `containers()`，`anchor = '插件管理'`
  （挂靠框架既有目录，不创建、不拥有、不改其排序；AI 能力审批菜单位置不变）

### Fixed
- 弹窗头部「历史会话」按钮点击无效（toggle 一次点击触发两次，开→关相抵）：三个入口都会调
  `agentChat.init()` —— wise-bootstrap、后台首页控制器，以及 ai-widget 里**不带 ?v=** 的
  `import('./agent-chat.js')`（同一文件被解析成第二个模块实例，旧缓存也由此进入）。
  修法：`init()` 用 window 级标记幂等化（跨实例生效）；ai-widget 改为复用已注册的
  `window.Wise.agentChat`，不再二次加载。同因「新建会话」此前一次点击会建出两个会话。

## [1.0.0] - 2024-01-01

### Added
- Initial release

[1.0.0]: https://github.com/hiwiseadmin/wise-agent/releases/tag/v1.0.0

[Unreleased]: https://github.com/hiwiseadmin/wise-agent/compare/v1.0.1...HEAD
[1.0.1]: https://github.com/hiwiseadmin/wise-agent/releases/tag/v1.0.1
