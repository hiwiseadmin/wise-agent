<?php
declare(strict_types=1);

namespace wise\agent\form;

use wise\agent\menu\MenuActionResult;
use wise\agent\query\exception\QueryParamException;
use wise\agent\security\CapabilityAccess;
use wise\agent\tool\BaseTool;

/**
 * `form_validate` —— 表单校验预检（A2-2）
 *
 * 纯只读、**纯计算**：不写数据库、不改表单声明、不调用任何控制器动作。
 * 落库永远归 A1 的 `save`/`update` 工具＼�。
 *
 * ## 响应契约（与 A1/A3 同核）
 *
 * ```
 * { code:0, msg:"…", structured:true, data:{ passed, errors[], accepted, coerced[],
 *                                          dropped_schema[], dropped_whitelist[], not_writable[],
 *                                          enforced, enforced_note, schema_source, notes[] } }
 * ```
 *
 * - `code:0` —— 预检完成（**`data.passed` 才是通过与否**，`code` 只表示「这次调用是否成功」）；
 * - `code:1` —— 调用失败（未知目标、目标无表单、参数非法）；
 * - `data.enforced=false` —— **真实提交当前不执行 Schema 校验**（见 {@see FormValidateReport} 类注释）。
 *
 * ## 只读断言
 * `getAccess()=READ`、`getRiskLevel()=LOW`、`requireConfirmation()=false`；
 * 默认 `getPermission()=''`（deny-only：不产生人工没有的权限面）。
 *
 * ## 可测试性
 * `bridge()` / `introspector()` 是 `protected` 接缝，故本类**不加 `final`**。
 */
class FormValidateTool extends BaseTool
{
    use FormAnchorAware;

    public const NAME = 'form_validate';

    /** @var array<string, mixed> */
    private array $options;

    private FormSchemaIntrospector $introspector;

    private FormValidationBridge $bridge;

    /**
     * @param array<string, mixed> $options `form_schema.*` 覆盖项
     */
    public function __construct(
        array $options = [],
        ?FormSchemaIntrospector $introspector = null,
        ?FormValidationBridge $bridge = null
    ) {
        $this->options    = $options;
        $this->introspector = $introspector ?? new FormSchemaIntrospector($options);
        $this->bridge     = $bridge ?? new FormValidationBridge($options);
    }

    public function getDescription(): string
    {
        return '预检一条待提交的表单数据是否合规（只读、不落库）。'
            . '返回逐字段错误、实际会被写入的字段（含类型转换与默认值补齐）、以及被丢弃的字段。'
            . '注意：若返回 enforced=false，表示该控制器的真实提交当前不执行 Schema 校验，'
            . '预检通过不等于提交会被系统校验。字段名与可选值请先用 form_fields 获取，不要编造。';
    }

    /**
     * @return array<string, mixed>
     */
    public function getParameters(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'target' => [
                    'type'        => 'string',
                    'description' => '表单目标，与列表查询目标同一命名，如 host.permission / smartstock.product'
                        . $this->targetHint(),
                ],
                'data'   => [
                    'type'        => 'object',
                    'description' => '待提交的数据（字段名 → 值）。字段名必须来自 form_fields，未知字段会被丢弃并回传。',
                ],
                'is_new' => [
                    'type'        => 'boolean',
                    'description' => '是否新建（默认 true；编辑场景传 false）',
                ],
            ],
            'required'   => ['target', 'data'],
        ];
    }

    public function getAccess(): string
    {
        return CapabilityAccess::READ;
    }

    public function getRiskLevel(): string
    {
        return CapabilityAccess::RISK_LOW;
    }

    public function requireConfirmation(): bool
    {
        return false;
    }

    /**
     * deny-only：默认不暴露权限面（同 A1-5 / A3）
     */
    public function getPermission(): string
    {
        if (!(bool) $this->opt('require_ai_permission', false)) {
            return '';
        }

        return 'form.validate';
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function execute(array $arguments): string
    {
        $targetName = trim((string) ($arguments['target'] ?? ''));
        $data       = $arguments['data'] ?? [];

        if ($targetName === '') {
            return $this->encode(MenuActionResult::failure('缺少 target：请先用 form_fields 获取可用的表单目标', QueryParamException::CODE_INVALID, [
                'stage' => MenuActionResult::STAGE_ARGUMENTS,
            ]));
        }

        if (!is_array($data)) {
            return $this->encode(MenuActionResult::failure('data 必须是「字段名 → 值」的对象', QueryParamException::CODE_INVALID, [
                'target' => $targetName,
                'stage'  => MenuActionResult::STAGE_ARGUMENTS,
            ]));
        }

        $maxDataChars = (int) $this->opt('max_data_chars', 20000);
        if ($maxDataChars > 0) {
            $size = mb_strlen((string) json_encode($data, JSON_UNESCAPED_UNICODE), 'UTF-8');

            if ($size > $maxDataChars) {
                return $this->encode(MenuActionResult::failure(
                    sprintf('data 体量超限：%d > max_data_chars(%d)，请拆分后分批预检', $size, $maxDataChars),
                    QueryParamException::CODE_INVALID,
                    [
                        'target' => $targetName,
                        'size'   => $size,
                        'limit'  => $maxDataChars,
                        'stage'  => MenuActionResult::STAGE_ARGUMENTS,
                    ]
                ));
            }
        }

        try {
            $target = $this->introspector()->resolveTarget($targetName);

            if ($target === null) {
                return $this->encode(MenuActionResult::failure(
                    sprintf('未知表单目标 %s', $targetName),
                    QueryParamException::CODE_INVALID,
                    [
                        'target'            => $targetName,
                        'available_targets' => $this->introspector()->availableTargets(),
                        'stage'             => MenuActionResult::STAGE_ARGUMENTS,
                    ]
                ));
            }

            if (($blocked = $this->anchorBlocked($target, $targetName)) !== null) {
                return $this->encode($blocked);
            }

            $schema = $this->introspector()->forController($target);

            if ($schema->isEmpty()) {
                return $this->encode(MenuActionResult::failure(
                    sprintf('目标 %s 没有可用的表单声明，无法预检', $targetName),
                    QueryParamException::CODE_INVALID,
                    [
                        'target'        => $targetName,
                        'schema_source' => $schema->source,
                        'notes'         => $schema->notes(),
                        'hint'          => '该控制器未声明 defineForm，且没有可用的 CRUD Schema / 表结构。',
                        'stage'         => MenuActionResult::STAGE_ARGUMENTS,
                    ]
                ));
            }

            $report    = $this->bridge()->check($schema, $data, (bool) ($arguments['is_new'] ?? true));
            $reportArr = $report->toArray();

            if (!(bool) $this->opt('report_accepted_fields', true)) {
                $reportArr['accepted'] = array_keys((array) $reportArr['accepted']);
                $reportArr['notes'][]  = [
                    'code'    => 'accepted.names_only',
                    'message' => '配置 report_accepted_fields=false：accepted 仅给出字段名，未回传字段值。',
                ];
            }

            return $this->encode(MenuActionResult::normalize([
                'code' => 0,
                'msg'  => $report->summary(),
                'data' => [
                    'target'   => $targetName,
                    'readonly' => true,
                ] + $reportArr,
            ]));
        } catch (\Throwable $e) {
            return $this->encode(MenuActionResult::fromException($e, [
                'target' => $targetName,
                'stage'  => MenuActionResult::STAGE_EXECUTION,
            ]));
        }
    }

    /**
     * 把「目标名 → 控制器」的解析交给 A2 的内省器
     *
     * 目标名与 A1/A3 共用 `MenuActionMeta::targetNameOf()`，因此这里只需要在 A2 侧维护一张
     * 「可解释的表单目标」清单（由宿主在 `form_schema.targets` 声明，或从 A1 内省结果推导）。
     */
    protected function introspector(): FormSchemaIntrospector
    {
        return $this->introspector;
    }

    protected function bridge(): FormValidationBridge
    {
        return $this->bridge;
    }

    private function targetHint(): string
    {
        $targets = $this->introspector()->availableTargets();

        if ($targets === []) {
            return '（当前没有可用的表单目标）';
        }

        $shown = array_slice($targets, 0, 8);

        return '；可用目标：' . implode(', ', $shown) . (count($targets) > 8 ? ' 等，用 form_fields 查看全部' : '');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function encode(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '{"code":1,"msg":"json encode failed"}';
    }

    private function opt(string $key, mixed $default): mixed
    {
        if (array_key_exists($key, $this->options)) {
            return $this->options[$key];
        }

        if (!function_exists('config')) {
            return $default;
        }

        try {
            return config('wise-agent.form_schema.' . $key, $default) ?? $default;
        } catch (\Throwable) {
            return $default;
        }
    }
}
