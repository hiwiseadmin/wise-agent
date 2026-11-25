<?php
declare(strict_types=1);

namespace wise\agent\closure;

/**
 * 闭环载荷脱敏＼�
 *
 * ## 为什么在**归一化阶段**就脱敏（而不是只在出站时脱敏）
 *
 * `ai_closure_events.payload` 是**落库**的：一旦入库就把明文留在了数据库里，
 * 再"出站时脱敏"已经晚了。因此 B2 采用**双保险**：
 *
 * 1. 归一化时（本类，入库前）按 `closure.mask_fields` 递归脱敏；
 * 2. 出站时（`AuditConnector` / `WebhookConnector` / `NotificationConnector`）各自再过一遍
 *    —— 因为连接器可能从 `payload` 之外拿到敏感字段（如 webhook 密钥、SMTP 配置）。
 *
 * ## 匹配规则（刻意保守）
 *
 * - **键名包含匹配、忽略大小写**：`user_password` / `access_token` / `X-Api-Key` 都能命中；
 * - 命中即整值替换为 `***`（不做"部分打码"，避免留下可推断的残片）；
 * - 递归深度上限 `maxDepth`（默认 4）：防深嵌套把 CPU 吃满；超深度的子树整体替换为 `***`；
 * - 数组列表（数字键）也递归处理（如 `items[0].password`）。
 */
final class PayloadMasker
{
    public const MASK = '***';

    /**
     * 默认脱敏字段（键名包含匹配）
     *
     * @var string[]
     */
    public const DEFAULT_FIELDS = [
        'password', 'passwd', 'pwd',
        'token', 'access_token', 'refresh_token', 'api_key', 'apikey', 'secret', 'app_secret', 'private_key',
        'id_card', 'idcard', 'bank_card', 'phone', 'mobile', 'email',
        'authorization', 'credential', 'signature', 'session_key',
    ];

    /**
     * 精确豁免清单（命中即**不**脱敏）
     *
     * ⚠️ 子串匹配会误伤「看似敏感、实为计数」的键：`total_tokens` 含 `token`、
     * `prompt_tokens` / `completion_tokens` 同理（B2-1 实测：`AgentComplete.usage.total_tokens`
     * 被脱敏成 `***`，导致"用了多少 token"这类**度量信息**从闭环轨迹里消失）。
     *
     * 处置：**先精确豁免，再子串匹配** —— 豁免清单是显式的、可读的；
     * 真正的凭据字段（`token` / `access_token`）仍然照脱不误。
     *
     * @var string[]
     */
    public const SAFE_FIELDS = [
        'total_tokens', 'prompt_tokens', 'completion_tokens',
        'tokens', 'max_tokens', 'token_count', 'token_usage',
    ];

    /**
     * @param string[] $fields   脱敏字段（键名包含匹配）
     * @param int      $maxDepth 递归深度上限
     */
    public function __construct(
        private array $fields = self::DEFAULT_FIELDS,
        private int $maxDepth = 4
    ) {
    }

    /**
     * 按 `closure.mask_fields` 构造（配置为空时回落默认清单）
     *
     * @param array<string, mixed> $options
     */
    public static function fromOptions(array $options = []): self
    {
        $fields = $options['mask_fields'] ?? null;

        if (!is_array($fields) || $fields === []) {
            $fields = self::DEFAULT_FIELDS;
        }

        return new self(array_values(array_map('strval', $fields)), max(1, (int) ($options['mask_depth'] ?? 4)));
    }

    /**
     * @return string[]
     */
    public function fields(): array
    {
        return $this->fields;
    }

    /**
     * 递归脱敏
     */
    public function mask(mixed $value, int $depth = 0): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if ($depth >= $this->maxDepth) {
            return $value === [] ? [] : [self::MASK => 'depth_limited'];
        }

        $masked = [];

        foreach ($value as $key => $item) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $masked[$key] = self::MASK;

                continue;
            }

            $masked[$key] = $this->mask($item, $depth + 1);
        }

        return $masked;
    }

    /**
     * 键名是否敏感（**先精确豁免，再子串匹配**，见 {@see self::SAFE_FIELDS}）
     */
    public function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);

        if (in_array($normalized, self::SAFE_FIELDS, true)) {
            return false;
        }

        foreach ($this->fields as $field) {
            if ($field !== '' && stripos($key, $field) !== false) {
                return true;
            }
        }

        return false;
    }
}
