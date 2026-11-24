<?php
declare(strict_types=1);

namespace wise\agent\schema\attribute;

use Attribute;

/**
 * 标记可被 AI 表单填充的字段
 *
 * 声明在模型类上（类级注解，可重复），指定哪些字段可被 AI
 * 根据自然语言描述自动推断填充，并可附带提示词辅助推断。
 *
 * 用法：
 *   #[AiFillable(['name', 'selling_price'], prompt: '根据商品描述推断')]
 *   class Product extends BaseModel { ... }
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class AiFillable
{
    /** @var string[] 可被 AI 表单填充的字段名 */
    public array $fields;

    /** @var string|null 填充提示词 */
    public ?string $prompt;

    public function __construct(array $fields, ?string $prompt = null)
    {
        $this->fields = $fields;
        $this->prompt = $prompt;
    }
}
