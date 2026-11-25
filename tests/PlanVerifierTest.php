<?php
declare(strict_types=1);

namespace wise\agent\test;

use PHPUnit\Framework\TestCase;
use wise\agent\pipeline\PipelineEngine;
use wise\agent\pipeline\PipelineRegistry;
use wise\agent\plan\PlanApprovalSignal;
use wise\agent\plan\PlanStep;
use wise\agent\plan\PlanValueResolver;
use wise\agent\plan\PlanVerifier;
use wise\agent\skill\SkillManager;

/**
 * B1-3 回归：断言执行（闭集）+ 值解析（与 PipelineEngine 对拍）+ 审批信号
 *
 * 全部离线。
 */
class PlanVerifierTest extends TestCase
{
    private function verifier(array $options = []): PlanVerifier
    {
        return new PlanVerifier($options);
    }

    private function step(array $verify, string $id = 's1'): PlanStep
    {
        return PlanStep::fromArray([
            'id'     => $id,
            'kind'   => 'tool',
            'target' => 'lq_query',
            'verify' => $verify,
        ]);
    }

    /**
     * @return string
     */
    private function ok(string $data = '{"list":[1,2,3]}', int $code = 0): string
    {
        return (string) json_encode(['code' => $code, 'msg' => 'ok', 'data' => json_decode($data, true)], JSON_UNESCAPED_UNICODE);
    }


    public function testToolOkPassesAndReportsBusinessFailure(): void
    {
        $step = $this->step([['type' => 'tool_ok']]);

        $this->assertTrue($this->verifier()->verify($step, $this->ok())->passed);

        $failed = $this->verifier()->verify($step, '{"code":1,"msg":"字段缺失"}');

        $this->assertFalse($failed->passed);
        $this->assertStringContainsString('字段缺失', $failed->firstFailure());
        $this->assertStringContainsString('code=1', $failed->firstFailure());

        $notEnvelope = $this->verifier()->verify($step, '纯文本结果');

        $this->assertFalse($notEnvelope->passed);
        $this->assertStringContainsString('同核契约', $notEnvelope->firstFailure());
    }

    public function testNotEmptyExistsEqualsContains(): void
    {
        $output = (string) json_encode([
            'code' => 0,
            'data' => ['total' => 3, 'list' => [['id' => 1], ['id' => 2]], 'note' => '已同步到采购系统'],
        ], JSON_UNESCAPED_UNICODE);

        $pass = $this->verifier()->verify($this->step([
            ['type' => 'not_empty', 'path' => 'data.list'],
            ['type' => 'exists', 'path' => 'data.total'],
            ['type' => 'equals', 'path' => 'data.total', 'value' => 3],
            ['type' => 'contains', 'path' => 'data.note', 'value' => '采购'],
        ]), $output);

        $this->assertTrue($pass->passed, implode(' | ', $pass->failureMessages()));
        $this->assertSame(4, $pass->checked);
        $this->assertSame(['not_empty', 'exists', 'equals', 'contains'], $pass->checkedTypes);

        $fail = $this->verifier()->verify($this->step([
            ['type' => 'not_empty', 'path' => 'data.missing'],
            ['type' => 'exists', 'path' => 'data.missing'],
            ['type' => 'equals', 'path' => 'data.total', 'value' => 9],
            ['type' => 'contains', 'path' => 'data.note', 'value' => '仓库'],
        ]), $output);

        $this->assertFalse($fail->passed);
        $this->assertCount(4, $fail->failures);
    }

    public function testCountGteCountsArraysNumbersAndMissing(): void
    {
        $output = '{"code":0,"data":{"list":[1,2,3],"total":5,"empty":[],"label":"x"}}';

        $this->assertTrue($this->verifier()->verify($this->step([
            ['type' => 'count_gte', 'path' => 'data.list', 'value' => 3],
        ]), $output)->passed, '数组按元素个数计');

        $this->assertTrue($this->verifier()->verify($this->step([
            ['type' => 'count_gte', 'path' => 'data.total', 'value' => 5],
        ]), $output)->passed, '数值按数值本身计（可直接断言 data.total）');

        $failed = $this->verifier()->verify($this->step([
            ['type' => 'count_gte', 'path' => 'data.missing', 'value' => 1],
        ]), $output);

        $this->assertFalse($failed->passed, '取不到 ⇒ 计数 0 ⇒ 失败');
        $this->assertStringContainsString('计数 0 < 期望 1', $failed->firstFailure());
    }

    public function testNumericComparisonRefusesMissingValueInsteadOfTreatingAsZero(): void
    {
        $output = '{"code":0,"data":{"amount":"abc"}}';

        $failed = $this->verifier()->verify($this->step([
            ['type' => 'gte', 'path' => 'data.missing', 'value' => 0],
        ]), $output);

        $this->assertFalse($failed->passed, '取不到值不得静默当 0 通过');
        $this->assertStringContainsString('取不到可比较的数值', $failed->firstFailure());

        $nonNumeric = $this->verifier()->verify($this->step([
            ['type' => 'lte', 'path' => 'data.amount', 'value' => 10],
        ]), $output);

        $this->assertFalse($nonNumeric->passed);
    }

    public function testCrossStepReferenceIsResolved(): void
    {
        $stepsOutput = ['s1' => '{"code":0,"data":{"total":3}}'];

        $pass = $this->verifier()->verify(
            $this->step([['type' => 'gte', 'path' => '$steps.s1.data.total', 'value' => 3]], 's2'),
            '{"code":0}',
            [],
            $stepsOutput
        );

        $this->assertTrue($pass->passed, implode(' | ', $pass->failureMessages()));

        $fail = $this->verifier()->verify(
            $this->step([['type' => 'gte', 'path' => '$steps.s1.data.total', 'value' => 9]], 's2'),
            '{"code":0}',
            [],
            $stepsOutput
        );

        $this->assertFalse($fail->passed);
    }

    public function testNoVerifyMeansSkipped(): void
    {
        $result = $this->verifier()->verify($this->step([]), '{"code":0}');

        $this->assertTrue($result->passed);
        $this->assertSame(0, $result->checked);
    }

    public function testUnsupportedAssertionFailsAtRuntimeInsteadOfPassingSilently(): void
    {
        $result = $this->verifier()->verify($this->step([['type' => 'llm_judge']]), '{"code":0}');

        $this->assertFalse($result->passed, '闭集之外的断言在执行期必须判失败（不静默放过）');
        $this->assertStringContainsString('不支持的断言类型', $result->firstFailure());
    }

    public function testSchemaValidNeedsSchemaAndHonoursConfig(): void
    {
        $missing = $this->verifier()->verify($this->step([['type' => 'schema_valid']]), '{"code":0}');
        $this->assertFalse($missing->passed);
        $this->assertStringContainsString('缺少 schema', $missing->firstFailure());

        $disabled = $this->verifier(['verify' => ['allow_schema_valid' => false]])
            ->verify($this->step([['type' => 'schema_valid', 'schema' => ['fields' => []]]]), '{"code":0}');
        $this->assertFalse($disabled->passed);
        $this->assertStringContainsString('已被配置关闭', $disabled->firstFailure());
    }

    public function testVerifyResultSerialisesExpectedAndActual(): void
    {
        $result = $this->verifier()->verify(
            $this->step([['type' => 'equals', 'path' => 'data.total', 'value' => 9]]),
            '{"code":0,"data":{"total":3}}'
        );

        $array = $result->toArray();

        $this->assertFalse($array['passed']);
        $this->assertSame(9, $array['failures'][0]['expected']);
        $this->assertSame(3, $array['failures'][0]['actual']);
        $this->assertSame('equals', $array['failures'][0]['type']);
    }


    public function testValueResolverMatchesProbedSemantics(): void
    {
        $resolver = new PlanValueResolver();
        $input    = ['name' => '张三', 'page' => 2, 'nil' => null];
        $steps    = ['s1' => '{"data":{"list":[{"id":7}],"total":3}}', 'plain' => '纯文本'];

        $this->assertSame('张三', $resolver->resolve('$input.name', $input, $steps));
        $this->assertSame(2, $resolver->resolve('$input.page', $input, $steps));
        $this->assertSame(7, $resolver->resolve('$steps.s1.data.list.0.id', $input, $steps), 'JSON 字符串先解码再走路径');

        $this->assertNull($resolver->resolve('$input.nope', $input, $steps));
        $this->assertNull($resolver->resolve('$input.nil', $input, $steps));
        $this->assertSame('$steps.nope.data', $resolver->resolve('$steps.nope.data', $input, $steps));
        $this->assertSame('$steps.s1', $resolver->resolve('$steps.s1', $input, $steps));
        $this->assertSame(' $input.name ', $resolver->resolve(' $input.name ', $input, $steps));
        $this->assertNull($resolver->resolve('$steps.s1.data.nope', $input, $steps));

        $this->assertSame('纯文本', $resolver->resolve('$steps.plain.x', $input, $steps));

        $this->assertSame('你好 张三', $resolver->resolve('你好 {$input.name}', $input, $steps));
        $this->assertSame('x{$steps.s1.data.total}y', $resolver->resolve('x{$steps.s1.data.total}y', $input, $steps));
        $this->assertSame('xy', $resolver->resolve('x{$input.nope}y', $input, $steps));
        $this->assertSame(
            'x{"data":{"list":[{"id":7}],"total":3}}y',
            $resolver->resolve('x{$steps.s1}y', $input, $steps)
        );

        $this->assertSame(
            ['name' => '张三', 'nested' => ['id' => 7]],
            $resolver->resolveMapping(['name' => '$input.name', 'nested' => ['id' => '$steps.s1.data.list.0.id']], $input, $steps)
        );
    }

    /**
     * 唯一一处刻意分歧：内联数组（`PipelineEngine` 会触发 `Array to string conversion` 警告并给出 `'Array'`）
     */
    public function testInlineArrayDivergesFromPipelineEngineOnPurpose(): void
    {
        $resolver = new PlanValueResolver();

        $this->assertSame(
            'x{"a":1}y',
            $resolver->resolve('x{$input.arr}y', ['arr' => ['a' => 1]], []),
            '本实现 JSON 化（可用信息），不复制 PipelineEngine 的警告 + Array'
        );
    }

    /**
     * 对拍：同一份 mapping 交给 `PipelineEngine` 与本解析器，结果必须一致
     *
     * 用例覆盖了探针实测出的全部边界（缺失、单段、纯文本输出、带空格、内联单段）。
     */
    public function testValueResolutionMatchesPipelineEngine(): void
    {
        $skillManager = $this->createMock(SkillManager::class);
        $engine       = new ExposedPipelineEngine(new PipelineRegistry(), $skillManager);

        $input = ['name' => '张三', 'page' => 2, 'flag' => true, 'nil' => null, 'zero' => 0];
        $steps = [
            's1'    => '{"data":{"list":[{"id":7}],"total":3}}',
            'plain' => '纯文本',
            'cn'    => '{"name":"张三"}',
        ];

        $mapping = [
            'literal'               => 'http://example.com',
            'trailing_space'        => ' $input.name ',
            'input_missing'         => '$input.nope',
            'input_empty_path'      => '$input.',
            'input_null'            => '$input.nil',
            'input_zero'            => '$input.zero',
            'from_input'            => '$input.name',
            'number'                => '$input.page',
            'bool'                  => '$input.flag',
            'nested_ref'            => '$steps.s1.data.list.0.id',
            'steps_missing_step'    => '$steps.nope.data',
            'steps_single_segment'  => '$steps.s1',
            'steps_path_missing'    => '$steps.s1.data.nope',
            'steps_deep_missing'    => '$steps.s1.a.b.c',
            'plain_text_step'       => '$steps.plain.x',
            'cn_payload'            => '$steps.cn.name',
            'inline_input'          => '前缀-{$input.name}',
            'inline_input_missing'  => 'x{$input.nope}y',
            'inline_steps_missing'  => 'x{$steps.nope}y',
            'inline_steps_whole'    => 'x{$steps.s1}y',
            'inline_steps_cn'       => 'x{$steps.cn}y',
            'nested_array'          => ['id' => '$steps.s1.data.list.0.id'],
            'plain_array'           => ['a', 'b'],
            'scalar_int'            => 5,
            'scalar_bool'           => false,
        ];

        $mine   = (new PlanValueResolver())->resolveMapping($mapping, $input, $steps);
        $theirs = $engine->exposeResolveInputMapping($mapping, $input, $steps);

        $this->assertSame(
            $theirs,
            $mine,
            '解析语义必须与 PipelineEngine 一致（否则物化后的流水线行为会漂移）'
        );
    }


    public function testApprovalSignalClassification(): void
    {
        $pending = (string) json_encode([
            'error'           => "Tool 'x' requires approval before execution",
            'required_action' => 'workflow',
            'approval_status' => 'pending',
            'approval_id'     => 42,
            'approval_ident'  => 'AP1',
            'reason'          => '写操作需审批',
        ], JSON_UNESCAPED_UNICODE);

        $signal = PlanApprovalSignal::of($pending);

        $this->assertSame(PlanApprovalSignal::KIND_PENDING, $signal['kind']);
        $this->assertSame(42, $signal['approval_id']);
        $this->assertSame('workflow', $signal['mode']);
        $this->assertTrue(PlanApprovalSignal::isPending($pending));

        $rejected = (string) json_encode([
            'required_action' => 'rejected',
            'approval_status' => 'rejected',
            'reason'          => '库存调整需线下确认',
        ], JSON_UNESCAPED_UNICODE);

        $this->assertSame(PlanApprovalSignal::KIND_REJECTED, PlanApprovalSignal::of($rejected)['kind']);

        $denied = (string) json_encode([
            'error'  => 'Permission denied: menu.action.host.stock.save',
            'tool'   => 'menu.action.host.stock.save',
            'reason' => 'no permission',
            'source' => 'rbac',
            'access' => 'write',
        ], JSON_UNESCAPED_UNICODE);

        $this->assertSame(PlanApprovalSignal::KIND_PERMISSION_DENIED, PlanApprovalSignal::of($denied)['kind']);

        $this->assertSame(PlanApprovalSignal::KIND_NONE, PlanApprovalSignal::of('{"code":0}')['kind']);
        $this->assertSame(PlanApprovalSignal::KIND_NONE, PlanApprovalSignal::of('普通文本')['kind']);
    }
}


/**
 * 把 `PipelineEngine` 的 protected 解析方法暴露出来，用于对拍
 */
class ExposedPipelineEngine extends PipelineEngine
{
    public function exposeResolveInputMapping(array $mapping, array $input, array $stepsOutput): array
    {
        return $this->resolveInputMapping($mapping, $input, $stepsOutput);
    }
}
