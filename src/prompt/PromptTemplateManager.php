<?php
declare(strict_types=1);

namespace wise\agent\prompt;

use InvalidArgumentException;

/**
 * Prompt 模板管理器（B4-7）
 *
 * 职责：装载模板（内置 + `wise-agent.prompts` 配置 + 运行时注册）、
 * 按 id 渲染、列出全部模板。
 *
 * 为什么内置默认模板
 * ------------------
 * 空管理器会让"模板化"变成只有基础设施没有内容 —— 调用方仍然只能在代码里
 * 拼字符串。这里随包提供两条最常用的模板（对话 system、工具使用守则），
 * 既作为示例，也保证开箱即用。
 *
 * 覆盖顺序：内置 < 配置 < 运行时注册（后注册覆盖同名）。
 *
 * @package wise\agent\prompt
 */
final class PromptTemplateManager
{
    /** @var array<string, PromptTemplate> */
    private array $templates = [];

    /**
     * 内置模板（id => 定义）
     *
     * @var array<string, array<string, mixed>>
     */
    private const BUILTIN = [
        'chat.system' => [
            'name'        => '通用对话 System',
            'description' => '基础助手角色设定，可作为大多数对话入口的 system prompt。',
            'system'      => '你是 {{product}} 的智能助手。请使用{{language}}回答，回答要简洁、准确。'
                . '当你不确定时，明确说明不确定，不要编造。',
            'variables'   => [
                'product'  => ['required' => false, 'default' => '本系统', 'description' => '产品名称'],
                'language' => ['required' => false, 'default' => '简体中文', 'description' => '回答语言'],
            ],
        ],
        'chat.tool_guard' => [
            'name'        => '工具使用守则 System',
            'description' => '约束模型调用工具的行为：先确认、少调用、不臆造参数。',
            'messages'    => [
                [
                    'role'    => 'system',
                    'content' => "调用工具时必须遵守：\n"
                        . "1. 只使用提供的工具，不要臆造工具名；\n"
                        . "2. 参数必须来自用户明确提供或上下文可确认的信息，缺参数时先追问；\n"
                        . "3. 涉及写操作（新增/修改/删除）时，先向用户复述将要执行的动作；\n"
                        . "4. 工具返回错误时如实说明，不要假装成功。",
                ],
            ],
        ],
    ];

    /**
     * @param array<string, array<string, mixed>> $configured 来自 `wise-agent.prompts`
     */
    public function __construct(array $configured = [])
    {
        foreach (self::BUILTIN as $id => $definition) {
            $this->templates[$id] = PromptTemplate::fromArray($id, $definition);
        }

        foreach ($configured as $id => $definition) {
            if (is_array($definition)) {
                $this->templates[(string) $id] = PromptTemplate::fromArray((string) $id, $definition);
            }
        }
    }

    /**
     * 运行时注册（插件可通过此方法注入自己的模板）
     */
    public function register(PromptTemplate $template): void
    {
        $this->templates[$template->id] = $template;
    }

    public function has(string $id): bool
    {
        return isset($this->templates[$id]);
    }

    public function get(string $id): ?PromptTemplate
    {
        return $this->templates[$id] ?? null;
    }

    /**
     * @return array<string, PromptTemplate>
     */
    public function all(): array
    {
        return $this->templates;
    }

    /**
     * 渲染模板为消息数组
     *
     * @param array<string, mixed> $vars
     * @return array<int, array{role:string, content:string}>
     *
     * @throws InvalidArgumentException 模板不存在或必填变量缺失
     */
    public function render(string $id, array $vars = []): array
    {
        return $this->require($id)->render($vars);
    }

    /**
     * 渲染 system 内容
     *
     * @param array<string, mixed> $vars
     */
    public function renderSystem(string $id, array $vars = []): string
    {
        return $this->require($id)->renderSystem($vars);
    }

    /**
     * 模板清单（供后台列表 / 能力目录展示）
     *
     * @return array<int, array{id:string, name:string, description:string, variables:array, messages:int}>
     */
    public function catalog(): array
    {
        $items = [];

        foreach ($this->templates as $id => $template) {
            $items[] = [
                'id'          => $id,
                'name'        => $template->name,
                'description' => $template->description,
                'variables'   => $template->variables,
                'messages'    => count($template->messages),
            ];
        }

        usort($items, static fn(array $a, array $b) => strcmp($a['id'], $b['id']));

        return $items;
    }

    private function require(string $id): PromptTemplate
    {
        $template = $this->get($id);

        if ($template === null) {
            throw new InvalidArgumentException("Prompt template not found: {$id}");
        }

        return $template;
    }
}
