<?php
declare(strict_types=1);

namespace wise\agent\schema\attribute;

use Attribute;

/**
 * 标记参与 AI 自然语言搜索的字段
 *
 * 声明在模型类上（类级注解，可重复），指定哪些字段应被
 * 自动生成的 search 工具纳入模糊搜索，即使字段类型非 string。
 *
 * 用法：
 *   #[AiSearchable('name', 'sku_code')]
 *   class Product extends BaseModel { ... }
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class AiSearchable
{
    /** @var string[] 参与自然语言搜索的字段名 */
    public array $fields;

    public function __construct(string ...$fields)
    {
        $this->fields = $fields;
    }
}
