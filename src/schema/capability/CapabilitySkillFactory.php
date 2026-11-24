<?php
declare(strict_types=1);

namespace wise\agent\schema\capability;

use wise\agent\schema\dto\PluginSchemaDTO;
use wise\agent\skill\AutoSkill;

/**
 * 业务能力 → 自动 Skill 工厂
 *
 * 根据插件 Schema（表结构 + 字段注释）自动生成一组开箱即用的 Skill，
 * 让插件"声明 business_capabilities 即送"智能分析能力，无需手写 prompt。
 *
 * 每个插件默认获得：
 *   - {plugin}.insight         —— 针对插件数据的经营洞察/分析
 *   - {plugin}.explain_record  —— 解读某条记录
 *   - {plugin}.auto_fill       —— 根据自然语言描述生成可写入字段值（表单填充）
 */
class CapabilitySkillFactory
{
    /**
     * 为插件生成自动 Skill
     *
     * @param PluginSchemaDTO $schema       插件 Schema
     * @param array           $capabilities 已解析的能力结果（key 为能力标签）
     * @return AutoSkill[]
     */
    public function generate(PluginSchemaDTO $schema, array $capabilities = []): array
    {
        $ident  = $schema->pluginIdent;
        $source = 'auto:plugin:' . $ident;

        return [
            $this->makeInsightSkill($schema, $source),
            $this->makeExplainRecordSkill($schema, $source),
            $this->makeAutoFillSkill($schema, $source),
        ];
    }

    /**
     * 经营洞察 Skill
     *
     * @param PluginSchemaDTO $schema
     * @param string          $source
     * @return AutoSkill
     */
    private function makeInsightSkill(PluginSchemaDTO $schema, string $source): AutoSkill
    {
        $ident = $schema->pluginIdent;
        $name  = $ident . '.insight';

        $prompt = "你是插件 [{$ident}] 的智能数据分析助手。\n"
            . "你的任务是基于插件的业务数据，回答用户的经营分析问题，并给出洞察与可执行建议。\n\n"
            . "分析时：\n"
            . "1. 优先使用提供的查询/统计工具获取真实数据，不要凭空编造数字；\n"
            . "2. 结合数据给出趋势判断、异常发现、以及可执行的改进建议；\n"
            . "3. 用 Markdown 输出结论，分点清晰，关键数字用表格呈现。\n\n"
            . "以下是该插件的数据模型结构，供你理解可用数据：\n\n"
            . $schema->toContextPrompt();

        return new AutoSkill(
            $name,
            "针对插件 [{$ident}] 数据的经营分析与洞察",
            'data',
            $prompt,
            [
                'type'       => 'object',
                'properties' => [
                    'question' => ['type' => 'string', 'description' => '要分析的业务问题'],
                ],
                'required'   => ['question'],
            ],
            'markdown',
            $this->defaultQueryTools($schema),
            $source
        );
    }

    /**
     * 记录解读 Skill
     *
     * @param PluginSchemaDTO $schema
     * @param string          $source
     * @return AutoSkill
     */
    private function makeExplainRecordSkill(PluginSchemaDTO $schema, string $source): AutoSkill
    {
        $ident = $schema->pluginIdent;
        $name  = $ident . '.explain_record';

        $prompt = "你是插件 [{$ident}] 的数据解读助手。\n"
            . "用户会提供一条业务记录的 JSON 内容，请用通俗易懂的语言解释这条记录的含义、\n"
            . "关键字段的取值意义，并结合上下文指出值得注意的要点。\n\n"
            . "以下是该插件的数据模型结构：\n\n"
            . $schema->toContextPrompt();

        return new AutoSkill(
            $name,
            "解读插件 [{$ident}] 中的某条业务记录",
            'data',
            $prompt,
            [
                'type'       => 'object',
                'properties' => [
                    'record' => ['type' => 'object', 'description' => '要解读的记录数据（JSON 对象）'],
                ],
                'required'   => ['record'],
            ],
            'markdown',
            [],
            $source
        );
    }

    /**
     * 表单填充 Skill
     *
     * @param PluginSchemaDTO $schema
     * @param string          $source
     * @return AutoSkill
     */
    private function makeAutoFillSkill(PluginSchemaDTO $schema, string $source): AutoSkill
    {
        $ident = $schema->pluginIdent;
        $name  = $ident . '.auto_fill';

        $prompt = "你是插件 [{$ident}] 的表单智能填充助手。\n"
            . "用户会用自然语言描述想填写的内容，你需要根据下面的数据模型，\n"
            . "推断出可写入字段及其取值，并以 JSON 对象返回。\n\n"
            . "要求：\n"
            . "1. 只输出 JSON，不要输出解释文本；\n"
            . "2. 字段名必须与数据模型中的字段名完全一致；\n"
            . "3. 忽略无法确定或不在模型中的字段；\n"
            . "4. 枚举字段的取值必须符合字段注释中的约定。\n\n"
            . "数据模型结构：\n\n"
            . $schema->toContextPrompt();

        return new AutoSkill(
            $name,
            "根据自然语言描述为插件 [{$ident}] 生成表单字段值",
            'data',
            $prompt,
            [
                'type'       => 'object',
                'properties' => [
                    'description' => ['type' => 'string', 'description' => '要填充内容的自然语言描述'],
                ],
                'required'   => ['description'],
            ],
            'json',
            [],
            $source
        );
    }

    /**
     * 构建默认查询工具名列表（与 ParamBuilder 命名约定保持一致）
     *
     * @param PluginSchemaDTO $schema
     * @return string[]
     */
    private function defaultQueryTools(PluginSchemaDTO $schema): array
    {
        $prefix = $this->pluginPrefix($schema->pluginIdent);
        $tools  = [];

        foreach ($schema->tables as $table) {
            $tools[] = $prefix . '_list_' . $table->name;

            if (!empty($table->getNumericFields())) {
                $tools[] = $prefix . '_stats_' . $table->name;
            }
        }

        return $tools;
    }

    /**
     * 提取插件短名（与 ParamBuilder 保持一致）
     *
     * @param string $pluginIdent
     * @return string
     */
    private function pluginPrefix(string $pluginIdent): string
    {
        $parts = explode('/', $pluginIdent);
        return end($parts);
    }
}
