<?php
declare(strict_types=1);

namespace wise\agent\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Config;
use wise\agent\agent\SimpleAgent;
use wise\agent\client\FakeClient;
use wise\agent\tool\FakeEchoTool;

/**
 * 本地测试脚手架：用 FakeClient 或真实 LLM 跑通 agent→tool 全流程
 *
 * 默认（无 --real）：使用 FakeClient，不依赖 API key / 联网 / 数据库，
 * 用于验证流程编排（Run Loop、工具分发与执行、事件/追踪容错）。
 *
 * 带 --real：不注入 Client，由 AgentManager 按 config('wise-agent.default')
 * 用真实配置的 Provider（OpenAI / DeepSeek）发起真实 LLM 调用。
 *
 * 运行：
 *   cd src
 *   composer dump-autoload
 *   php think wise:agent:fake-demo            # 假链路，零依赖
 *   php think wise:agent:fake-demo --real     # 真实 LLM（需配置 .env 的 API key 且可联网）
 */
class FakeClientDemo extends Command
{
    protected function configure()
    {
        $this->setName('wise:agent:fake-demo')
            ->setDescription('Run the agent→tool flow locally. Default uses a fake LLM (no API/network/DB); use --real for a real LLM call.')
            ->addOption('real', 'r', Option::VALUE_NONE, 'Use the real configured LLM provider instead of FakeClient');
    }

    protected function execute(Input $input, Output $output): int
    {
        Config::set(['agent' => ['enable_memory' => false]], 'wise-agent');

        $real       = (bool) $input->getOption('real');
        $fakeClient = null;

        $agent = new SimpleAgent();
        $agent->withMemory(false);
        $agent->getTools()->register(new FakeEchoTool(), 'echo_tool');

        if ($real) {
            $provider = Config::get('wise-agent.default', 'deepseek');
            $output->writeln("<info>Running with REAL LLM provider (config wise-agent.default = '{$provider}')...</info>");
        } else {
            $fakeClient = new FakeClient();
            $agent->setClient($fakeClient);
            $output->writeln('<info>Running with FakeClient (no real LLM)...</info>');
        }

        $output->writeln('<comment>Task: "please echo a greeting"</comment>');
        $output->writeln('');

        $result = $agent->run('please echo a greeting');

        $output->writeln('<info>Agent final result:</info> ' . $result);
        if ($fakeClient !== null) {
            $output->writeln('<info>FakeClient LLM calls:</info> ' . $fakeClient->getCallCount());
        }

        if ($real) {
            $output->writeln('');
            $output->writeln('<info>✓ Real LLM call completed.</info>');
            return 0;
        }

        if ($fakeClient && $fakeClient->getCallCount() === 2 && str_contains($result, 'completed')) {
            $output->writeln('');
            $output->writeln('<info>✓ Scaffold OK: agent→tool loop ran end-to-end without external dependencies.</info>');
            return 0;
        }

        $output->writeln('');
        $output->writeln('<error>✗ Unexpected flow: LLM calls=' . ($fakeClient ? $fakeClient->getCallCount() : 'n/a') . '</error>');
        return 1;
    }
}
