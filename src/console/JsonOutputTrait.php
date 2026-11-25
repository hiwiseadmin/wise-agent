<?php
declare(strict_types=1);

namespace wise\agent\console;

use think\console\Input;
use think\console\Output;

/**
 * 控制台命令 JSON 输出辅助＼�
 *
 * 约定：命令声明 `--json` 选项后，在 JSON 模式下 **stdout 只能有 JSON**，
 * 且与框架 HTTP 的 `wise\support\traits\JsonResponse` 保持同构：
 *
 * ```json
 * { "code": 0, "msg": "ok", "data": { ... }, "request_id": "cli-xxxx", "timestamp": 1760000000 }
 * ```
 *
 * 这样 AI 调用 A1（HTTP 动作）与 A4（CLI 命令）时观察到的结构完全一致，
 * Agent 的「观察」处理逻辑无需分支、Prompt 更简单。
 *
 * 用法：
 * ```php
 * class MyCommand extends Command
 * {
 *     use JsonOutputTrait;
 *
 *     protected function configure()
 *     {
 *         $this->addOption('json', null, Option::VALUE_NONE, '以 JSON 格式输出结果');
 *     }
 *
 *     protected function execute(Input $input, Output $output): int
 *     {
 *         try {
 *             $data = $this->doWork();
 *         } catch (\Throwable $e) {
 *             // 异常也必须吐 JSON（否则 AI 拿到未格式化的错误栈）
 *             $this->outputJson($output, 1, $e->getMessage());
 *             return 1;
 *         }
 *
 *         if ($this->isJsonMode($input)) {
 *             $this->outputJson($output, 0, 'ok', $data);
 *         } else {
 *             $output->writeln('done');
 *         }
 *
 *         return 0;
 *     }
 * }
 * ```
 */
trait JsonOutputTrait
{
    /**
     * 当前是否处于 JSON 输出模式
     *
     * 优先级＼�：`--quiet` 优先——静默模式下只保留退出码，不输出 JSON。
     */
    protected function isJsonMode(Input $input): bool
    {
        if ($input->getOption('quiet')) {
            return false;
        }

        return (bool) $input->getOption('json');
    }

    /**
     * 输出同构 JSON 结果
     *
     * @param Output $output
     * @param int    $code   0 成功；非 0 失败（与 HTTP 响应 code 同义）
     * @param string $msg    摘要
     * @param mixed  $data   业务数据
     */
    protected function outputJson(Output $output, int $code = 0, string $msg = 'ok', mixed $data = null): void
    {
        $payload = [
            'code'       => $code,
            'msg'        => $msg,
            'data'       => $data,
            'request_id' => 'cli-' . str_replace('.', '', uniqid('', true)),
            'timestamp'  => time(),
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        try {
            $output->setDecorated(false);
        } catch (\Throwable) {
        }

        $output->writeln($json ?? '{"code":1,"msg":"json encode failed"}', Output::OUTPUT_RAW);
    }
}
