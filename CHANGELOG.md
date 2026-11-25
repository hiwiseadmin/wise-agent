# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased]

## [1.0.2] - 2026-11-25

### Added
- 新增两段式工具面（默认关闭）：只下发 `cmd_search` / `cmd_run`，降低工具 schema token 占用。
- 工具可注册但不下发；`wise:agent:chat` 新增 `--dry-run`、`--file`、`--interactive`。

### Changed
- AI 助手入口收敛到顶栏弹窗，移除右下角浮标；后台菜单改为挂靠已有目录。

### Fixed
- 修复历史会话/新建会话按钮一次点击触发两次的问题。

## [1.0.0] - 2024-01-01

- Initial release

[1.0.0]: https://github.com/hiwiseadmin/wise-agent/releases/tag/v1.0.0

[Unreleased]: https://github.com/hiwiseadmin/wise-agent/compare/v1.0.2...HEAD
[1.0.2]: https://github.com/hiwiseadmin/wise-agent/releases/tag/v1.0.2
