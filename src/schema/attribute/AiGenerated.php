<?php
declare(strict_types=1);

namespace wise\agent\schema\attribute;

use Attribute;

/**
 * 标记可由某 Skill 自动生成的字段
 *
 * 声明在模型类上（类级注解，可重复），指定某字段可由指定的
 * Skill 自动生成（如 seo_keyword 由 content.seo_optimize 生成）。
 *
 * 用法：
 *   #[AiGenerated('seo_keyword', skill: 'content.seo_optimize')]
 *   class Article extends BaseModel { ... }
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class AiGenerated
{
    /** @var string 字段名 */
    public string $field;

    /** @var string 生成该字段的 Skill 名称 */
    public string $skill;

    public function __construct(string $field, string $skill)
    {
        $this->field = $field;
        $this->skill = $skill;
    }
}
