<?php
declare(strict_types=1);

namespace wise\agent\test;

use PHPUnit\Framework\TestCase;
use wise\agent\menu\MenuActionMeta;
use wise\agent\menu\MenuActionSchemaResolver;
use wise\agent\security\CapabilityAccess;
use wise\form\schema\Form;
use wise\form\schema\field\Datetime;
use wise\form\schema\field\Number;
use wise\form\schema\field\Select;
use wise\form\schema\field\SwitchField;
use wise\form\schema\field\Text;
use wise\form\schema\field\Textarea;
use think\Model;

/**
 * A1-1 回归测试：动作元数据 + 参数 Schema 推导（三级回退链）
 *
 * 覆盖：
 *   · MenuActionMeta：来源识别 / 权限标识 / 工具名 / 动作语义表 / 确认策略 / 序列化 round-trip
 *   · MenuActionSchemaResolver 第1级（defineForm 反射，零副作用）
 *   · MenuActionSchemaResolver 第2级（crudSchema 反射，需实例化）
 *   · MenuActionSchemaResolver 第3级（DB 表结构：类型映射 / enum() / 注释枚举）
 *   · getList / save / update / status / sort / delete 六类动作 Schema
 *   · 敏感字段剔除、字段过多退化、HTML 动作不生成 Schema
 *
 * 约束：测试不引导 ThinkPHP 应用（与 wise-agent 既有测试一致），
 * 与容器/DB 交互的三个动作由 `TestableResolver` 覆写，保证可离线断言。
 */
class MenuActionTest extends TestCase
{
    protected function setUp(): void
    {
        MenuActionSchemaResolver::clearCache();
    }


    public function testDetectSourceFromControllerClass(): void
    {
        $this->assertSame('plugin:smartstock', MenuActionMeta::detectSource('plugin\\smartstock\\controller\\Product'));
        $this->assertSame(MenuActionMeta::SOURCE_HOST, MenuActionMeta::detectSource('app\\admin\\controller\\Menu'));
        $this->assertSame(MenuActionMeta::SOURCE_HOST, MenuActionMeta::detectSource('plugin\\smartstock'));
    }

    public function testMetaIdentityAndNaming(): void
    {
        $plugin = MenuActionMeta::forAction('plugin\\smartstock\\controller\\ProductCategory', 'getList');

        $this->assertTrue($plugin->isPlugin());
        $this->assertSame('smartstock', $plugin->getIdent());
        $this->assertSame('product_category', $plugin->getControllerName());
        $this->assertSame('menu.action.smartstock.product_category.getList', $plugin->getPermission());
        $this->assertSame('ma_smartstock_product_category_getlist', $plugin->getToolName());

        $host = MenuActionMeta::forAction('app\\admin\\controller\\Menu', 'delete');

        $this->assertFalse($host->isPlugin());
        $this->assertSame('host', $host->getIdent());
        $this->assertSame('menu', $host->getControllerName());
        $this->assertSame('menu.action.host.menu.delete', $host->getPermission());
        $this->assertSame('ma_host_menu_delete', $host->getToolName());
    }

    public function testToolNameIsLlmSafe(): void
    {
        $meta = MenuActionMeta::forAction('plugin\\my_shop\\controller\\OrderItem', 'forceDelete');

        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9_]+$/', $meta->getToolName());
        $this->assertSame('ma_my_shop_order_item_forcedelete', $meta->getToolName());
    }

    public function testActionSemanticsTable(): void
    {
        $this->assertSame(CapabilityAccess::READ, MenuActionMeta::accessForAction('getList'));
        $this->assertSame(CapabilityAccess::RISK_LOW, MenuActionMeta::riskForAction('getList'));

        $this->assertSame(CapabilityAccess::WRITE, MenuActionMeta::accessForAction('delete'));
        $this->assertSame(CapabilityAccess::RISK_HIGH, MenuActionMeta::riskForAction('delete'));

        $this->assertSame(CapabilityAccess::ADMIN, MenuActionMeta::accessForAction('forceDelete'));
        $this->assertSame(CapabilityAccess::RISK_HIGH, MenuActionMeta::riskForAction('forceDelete'));

        $this->assertSame(CapabilityAccess::WRITE, MenuActionMeta::accessForAction('unknownAction'));
        $this->assertSame(CapabilityAccess::RISK_MEDIUM, MenuActionMeta::riskForAction('unknownAction'));
    }

    public function testExposedActionsExcludeHtmlActions(): void
    {
        foreach (MenuActionMeta::EXPOSED_ACTIONS as $action) {
            $this->assertTrue(MenuActionMeta::isExposedAction($action), $action . ' 应暴露');
            $this->assertFalse(MenuActionMeta::isHtmlAction($action), $action . ' 不应是 HTML 动作');
        }

        foreach (['index', 'add', 'edit', 'preview'] as $action) {
            $this->assertTrue(MenuActionMeta::isHtmlAction($action));
            $this->assertFalse(MenuActionMeta::isExposedAction($action), $action . ' 不应暴露为工具');
        }

        $this->assertCount(8, MenuActionMeta::EXPOSED_ACTIONS);
        $this->assertCount(12, MenuActionMeta::allActions());
    }

    public function testConfirmationPolicy(): void
    {
        $read = MenuActionMeta::forAction('plugin\\smartstock\\controller\\Product', 'getList');
        $this->assertTrue($read->isReadOnly());
        $this->assertFalse($read->requireConfirmation(), '读动作无需确认');

        $admin = MenuActionMeta::forAction('plugin\\smartstock\\controller\\Product', 'forceDelete');
        $this->assertFalse($admin->isReadOnly());
        $this->assertTrue($admin->requireConfirmation(), 'admin/high 恒需确认');

        $highRisk = MenuActionMeta::forAction('plugin\\smartstock\\controller\\Product', 'delete');
        $this->assertTrue($highRisk->requireConfirmation(), 'high 风险恒需确认');

        $write = MenuActionMeta::forAction('plugin\\smartstock\\controller\\Product', 'save');
        $this->assertIsBool($write->requireConfirmation());
    }

    public function testMetaRoundTrip(): void
    {
        $meta = MenuActionMeta::forAction('plugin\\smartstock\\controller\\Product', 'update', [
            'menu_path'  => '/plugin/smartstock/product',
            'menu_name'  => '商品管理',
            'menu_ident' => 'plugin.smartstock.product',
            'parameters' => ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]],
            'enabled'    => true,
        ]);

        $restored = MenuActionMeta::fromArray($meta->toArray());

        $this->assertSame($meta->controller, $restored->controller);
        $this->assertSame($meta->action, $restored->action);
        $this->assertSame($meta->source, $restored->source);
        $this->assertSame($meta->menuPath, $restored->menuPath);
        $this->assertSame($meta->menuIdent, $restored->menuIdent);
        $this->assertSame($meta->getPermission(), $restored->getPermission());
        $this->assertSame($meta->parameters, $restored->parameters);
        $this->assertTrue($restored->enabled);
    }

    public function testWithAnchorKeepsIdentity(): void
    {
        $meta = MenuActionMeta::forAction('plugin\\smartstock\\controller\\Product', 'getList');
        $meta->withParameters(['type' => 'object']);

        $anchored = $meta->withAnchor('/plugin/smartstock/product', '商品管理', 'plugin.smartstock.product');

        $this->assertSame($meta->controller, $anchored->controller);
        $this->assertSame($meta->action, $anchored->action);
        $this->assertSame($meta->parameters, $anchored->parameters);
        $this->assertSame('/plugin/smartstock/product', $anchored->menuPath);
        $this->assertSame('plugin.smartstock.product', $anchored->menuIdent);
        $this->assertSame('商品管理', $anchored->menuName);
    }


    public function testSchemaFromDefineFormWithoutConstructor(): void
    {
        $resolver = new MenuActionSchemaResolver();
        $class    = FakeArticleCrudController::class;

        $this->assertSame(MenuActionSchemaResolver::SOURCE_DEFINE_FORM, $resolver->schemaSource($class));

        $fields = $resolver->fields($class);

        $this->assertArrayHasKey('title', $fields);
        $this->assertSame('标题', $fields['title']['label']);
        $this->assertSame(MenuActionSchemaResolver::TYPE_STRING, $fields['title']['type']);
        $this->assertTrue($fields['title']['required']);

        $this->assertSame(MenuActionSchemaResolver::TYPE_ENUM, $fields['status']['type']);
        $this->assertSame(['1', '0'], $fields['status']['enum']);
        $this->assertSame(['正常', '停用'], $fields['status']['enum_labels']);

        $this->assertSame(MenuActionSchemaResolver::TYPE_NUMBER, $fields['amount']['type']);
        $this->assertSame(MenuActionSchemaResolver::TYPE_DATETIME, $fields['publish_time']['type']);
        $this->assertSame(MenuActionSchemaResolver::TYPE_BOOLEAN, $fields['is_top']['type']);

        $this->assertSame(MenuActionSchemaResolver::TYPE_TEXT, $fields['content']['type']);
        $this->assertFalse($fields['content']['sortable'], '长文本不应作为排序字段');
        $this->assertTrue($fields['title']['sortable']);
        $this->assertTrue($fields['title']['filterable']);
    }

    public function testFormSchemaIsCachedAndIsolatedPerController(): void
    {
        $resolver = new MenuActionSchemaResolver();

        $first  = $resolver->formSchema(FakeArticleCrudController::class);
        $second = $resolver->formSchema(FakeArticleCrudController::class);

        $this->assertSame($first, $second);
        $this->assertArrayHasKey('fields', $first);
        $this->assertSame('fake_article_crud_controller', $first['form']);

        $this->assertSame([], $resolver->formSchema(FakeDbController::class));
    }


    public function testGetListSchemaContainsQuerySkeletonAndFieldFilters(): void
    {
        $resolver = new MenuActionSchemaResolver(['max_limit' => 200, 'default_limit' => 20]);
        $schema   = $resolver->resolve(FakeArticleCrudController::class, 'getList');

        $this->assertSame('object', $schema['type']);

        $this->assertSame('integer', $schema['properties']['page']['type']);
        $this->assertSame(1, $schema['properties']['page']['default']);
        $this->assertSame(20, $schema['properties']['limit']['default']);
        $this->assertSame(200, $schema['properties']['limit']['maximum']);
        $this->assertSame('string', $schema['properties']['order']['type']);
        $this->assertStringContainsString('create_time desc', $schema['properties']['order']['description']);
        $this->assertSame('string', $schema['properties']['field']['type']);

        $this->assertSame(['1', '0'], $schema['properties']['status']['enum']);
        $this->assertStringContainsString('正常(1)', $schema['properties']['status']['description']);
        $this->assertArrayHasKey('amount__gte', $schema['properties']);
        $this->assertArrayHasKey('amount__between', $schema['properties']);
        $this->assertStringContainsString('区间', $schema['properties']['amount__between']['description']);
        $this->assertArrayHasKey('title__like', $schema['properties']);
        $this->assertArrayHasKey('status__in', $schema['properties']);
        $this->assertSame('array', $schema['properties']['status__in']['type']);
        $this->assertSame(['1', '0'], $schema['properties']['status__in']['items']['enum']);
        $this->assertArrayHasKey('publish_time__gte', $schema['properties']);

        $this->assertArrayNotHasKey('status__like', $schema['properties']);
        $this->assertArrayNotHasKey('status__gt', $schema['properties']);
    }

    public function testGetListSchemaNeverExposesExistsOperator(): void
    {
        $resolver = new MenuActionSchemaResolver();
        $schema   = $resolver->resolve(FakeArticleCrudController::class, 'getList');

        foreach (array_keys($schema['properties']) as $key) {
            $this->assertStringNotContainsString('__exists', (string) $key);
            $this->assertStringNotContainsString('__notexists', (string) $key);
            $this->assertStringNotContainsString('__null', (string) $key);
        }
    }

    public function testGetListSchemaFallsBackToGenericFiltersWhenTooManyFields(): void
    {
        $resolver = new MenuActionSchemaResolver(['max_filter_fields' => 20]);
        $schema   = $resolver->resolve(FakeWideController::class, 'getList');

        $this->assertArrayHasKey('filters', $schema['properties']);
        $this->assertSame('array', $schema['properties']['filters']['type']);
        $this->assertStringContainsString('字段__操作符=值', $schema['properties']['filters']['description']);
        $this->assertStringContainsString('f_1', $schema['properties']['filters']['description']);

        $this->assertArrayNotHasKey('f_1__like', $schema['properties']);
    }

    public function testGetListSchemaRespectsMaxFilterProperties(): void
    {
        $resolver = new MenuActionSchemaResolver(['max_filter_properties' => 12]);
        $schema   = $resolver->resolve(FakeArticleCrudController::class, 'getList');

        $this->assertArrayHasKey('filters', $schema['properties']);
        $this->assertLessThanOrEqual(12, count($schema['properties']));
    }

    public function testSensitiveFieldsAreExcluded(): void
    {
        $resolver = new MenuActionSchemaResolver();
        $fields   = $resolver->fields(FakeSensitiveController::class);

        $this->assertArrayHasKey('title', $fields);
        $this->assertArrayNotHasKey('password', $fields, 'password 必须剔除');
        $this->assertArrayNotHasKey('api_secret', $fields, 'api_secret 必须剔除');
        $this->assertArrayNotHasKey('token_hash', $fields, '_hash 后缀必须剔除');

        $schema = $resolver->resolve(FakeSensitiveController::class, 'save');
        $this->assertArrayNotHasKey('password', $schema['properties']);
        $this->assertArrayNotHasKey('api_secret', $schema['properties']);
    }


    public function testSaveSchemaUsesFormFieldsWithoutId(): void
    {
        $resolver = new MenuActionSchemaResolver();
        $schema   = $resolver->resolve(FakeArticleCrudController::class, 'save');

        $this->assertSame('object', $schema['type']);
        $this->assertArrayNotHasKey('id', $schema['properties'], 'save 不应暴露 id（CrudAction 会 unset）');
        $this->assertArrayNotHasKey('ids', $schema['properties']);
        $this->assertArrayHasKey('title', $schema['properties']);
        $this->assertArrayHasKey('status', $schema['properties']);
        $this->assertSame(['1', '0'], $schema['properties']['status']['enum']);
        $this->assertSame('标题', $schema['properties']['title']['description']);
        $this->assertContains('title', $schema['required']);

        $this->assertSame('number', $schema['properties']['amount']['type']);
        $this->assertSame('string', $schema['properties']['publish_time']['type']);
        $this->assertSame('date-time', $schema['properties']['publish_time']['format']);
        $this->assertSame('boolean', $schema['properties']['is_top']['type']);
    }

    public function testUpdateSchemaAddsIdAndIds(): void
    {
        $resolver = new MenuActionSchemaResolver();
        $schema   = $resolver->resolve(FakeArticleCrudController::class, 'update');

        $this->assertSame('integer', $schema['properties']['id']['type']);
        $this->assertSame('array', $schema['properties']['ids']['type']);
        $this->assertSame('integer', $schema['properties']['ids']['items']['type']);
        $this->assertStringContainsString('至少提供一个', $schema['description']);
        $this->assertArrayHasKey('title', $schema['properties']);
    }

    public function testStatusSchemaRequiresStatusAndUsesEnum(): void
    {
        $resolver = new MenuActionSchemaResolver();
        $schema   = $resolver->resolve(FakeArticleCrudController::class, 'status');

        $this->assertSame(['status'], $schema['required']);
        $this->assertSame('integer', $schema['properties']['status']['type']);
        $this->assertSame([1, 0], $schema['properties']['status']['enum']);
        $this->assertStringContainsString('正常(1)', $schema['properties']['status']['description']);
        $this->assertArrayHasKey('id', $schema['properties']);
        $this->assertArrayHasKey('ids', $schema['properties']);
    }

    public function testSortSchemaEnumMatchesCrudActionWhitelist(): void
    {
        $resolver = new MenuActionSchemaResolver();
        $schema   = $resolver->resolve(FakeArticleCrudController::class, 'sort');

        $this->assertSame(['sort'], $schema['required']);
        $this->assertSame(['sort', 'weigh', 'order_weight'], $schema['properties']['sort_field']['enum']);
        $this->assertSame('sort', $schema['properties']['sort_field']['default']);
        $this->assertSame('integer', $schema['properties']['sort']['type']);
        $this->assertSame('integer', $schema['properties']['id']['type']);
    }

    public function testIdsSchemaForDestructiveActions(): void
    {
        $resolver = new MenuActionSchemaResolver();

        foreach (['delete', 'restore', 'forceDelete'] as $action) {
            $schema = $resolver->resolve(FakeArticleCrudController::class, $action);

            $this->assertSame(['id', 'ids'], array_keys($schema['properties']), $action . ' 只应有 id/ids');
            $this->assertSame('integer', $schema['properties']['id']['type']);
            $this->assertSame('array', $schema['properties']['ids']['type']);
        }
    }

    public function testHtmlActionsProduceNoSchema(): void
    {
        $resolver = new MenuActionSchemaResolver();

        foreach (['index', 'add', 'edit', 'preview'] as $action) {
            $this->assertSame([], $resolver->resolve(FakeArticleCrudController::class, $action), $action . ' 不应生成工具');
        }

        $this->assertSame([], $resolver->resolve(FakeArticleCrudController::class, 'notAnAction'));

        $steps = implode('|', array_map(static fn(array $s): string => $s['step'], $resolver->trace()));
        $this->assertStringContainsString('skip', $steps);
    }

    public function testResolveForFillsMetaParameters(): void
    {
        $resolver = new MenuActionSchemaResolver();
        $meta     = MenuActionMeta::forAction(FakeArticleCrudController::class, 'getList');

        $this->assertSame([], $meta->parameters);

        $filled = $resolver->resolveFor($meta);

        $this->assertArrayHasKey('page', $filled->parameters['properties']);
        $this->assertSame($meta->getPermission(), $filled->getPermission());
    }


    public function testLevel2CrudSchemaWhenDefineFormNeedsConstructor(): void
    {
        $resolver = new TestableResolver(instantiateWith: static fn(string $class): ?object => new $class());
        $class    = FakeLevel2Controller::class;

        $this->assertSame(MenuActionSchemaResolver::SOURCE_CRUD_SCHEMA, $resolver->schemaSource($class));

        $fields = $resolver->fields($class);

        $this->assertArrayHasKey('title', $fields);
        $this->assertSame('标题（构造后）', $fields['title']['label']);

        $schema = $resolver->resolve($class, 'save');
        $this->assertArrayHasKey('title', $schema['properties']);
    }


    public function testLevel3DbSchemaTypeMappingAndCommentEnum(): void
    {
        $resolver = $this->dbResolver();

        $this->assertSame(MenuActionSchemaResolver::SOURCE_DB, $resolver->schemaSource(FakeDbController::class));

        $fields = $resolver->fields(FakeDbController::class);

        $this->assertSame(MenuActionSchemaResolver::TYPE_INT, $fields['id']['type']);
        $this->assertSame(MenuActionSchemaResolver::TYPE_BOOLEAN, $fields['is_top']['type'], 'tinyint(1) → boolean');
        $this->assertSame(MenuActionSchemaResolver::TYPE_NUMBER, $fields['amount']['type']);
        $this->assertSame(MenuActionSchemaResolver::TYPE_DATETIME, $fields['create_time']['type']);
        $this->assertSame(MenuActionSchemaResolver::TYPE_DATE, $fields['publish_date']['type']);
        $this->assertSame(MenuActionSchemaResolver::TYPE_TEXT, $fields['content']['type']);
        $this->assertSame(MenuActionSchemaResolver::TYPE_ENUM, $fields['level']['type'], "enum('a','b') → enum");
        $this->assertSame(['gold', 'silver'], $fields['level']['enum'], 'DB enum(...) 候选取值');

        $this->assertSame('状态', $fields['status']['label']);
        $this->assertSame(['1', '0'], $fields['status']['enum']);
        $this->assertSame(['启用', '禁用'], $fields['status']['enum_labels']);
    }

    public function testLevel3GetListSchemaAndWriteSchema(): void
    {
        $resolver = $this->dbResolver();

        $list = $resolver->resolve(FakeDbController::class, 'getList');
        $this->assertArrayHasKey('status', $list['properties']);
        $this->assertSame(['1', '0'], $list['properties']['status']['enum']);
        $this->assertArrayHasKey('amount__gte', $list['properties']);
        $this->assertArrayHasKey('create_time__between', $list['properties']);
        $this->assertArrayHasKey('title__like', $list['properties']);

        $save = $resolver->resolve(FakeDbController::class, 'save');
        $this->assertArrayNotHasKey('id', $save['properties'], '自动列不应出现在 save');
        $this->assertArrayNotHasKey('create_time', $save['properties']);
        $this->assertArrayNotHasKey('update_time', $save['properties']);
        $this->assertArrayHasKey('title', $save['properties']);
        $this->assertSame('boolean', $save['properties']['is_top']['type']);
        $this->assertStringContainsString('状态', $save['properties']['status']['description']);
        $this->assertStringContainsString('启用(1)', $save['properties']['status']['description'], 'tinyint 状态列的注释枚举应进入描述');
    }


    public function testAllLevelsFailGracefully(): void
    {
        $resolver = new MenuActionSchemaResolver();
        $class    = FakeNoSchemaController::class;

        $this->assertSame([], $resolver->formSchema($class));
        $this->assertSame([], $resolver->fields($class));
        $this->assertSame(MenuActionSchemaResolver::SOURCE_NONE, $resolver->schemaSource($class));

        $getList = $resolver->resolve($class, 'getList');
        $this->assertSame('object', $getList['type']);
        $this->assertArrayHasKey('page', $getList['properties']);
        $this->assertArrayHasKey('filters', $getList['properties']);

        $delete = $resolver->resolve($class, 'delete');
        $this->assertSame(['id', 'ids'], array_keys($delete['properties']));

        $save = $resolver->resolve($class, 'save');
        $this->assertSame([], $save['properties']);
    }

    public function testUnknownControllerClassIsSafe(): void
    {
        $resolver = new MenuActionSchemaResolver();

        $this->assertSame([], $resolver->formSchema('App\\Nope\\NotAController'));
        $this->assertSame([], $resolver->fields('App\\Nope\\NotAController'));

        $schema = $resolver->resolve('App\\Nope\\NotAController', 'getList');

        $this->assertSame('object', $schema['type']);
        $this->assertArrayHasKey('page', $schema['properties']);
        $this->assertArrayHasKey('filters', $schema['properties'], '无字段目录时退化为通用 filters');
    }

    /**
     * 构造一个「第3级」用的 resolver（覆写容器 / 表名 / 表字段三个接缝）
     */
    private function dbResolver(): MenuActionSchemaResolver
    {
        return new TestableResolver(
            instantiateWith: static fn(string $class): ?object => new $class(),
            modelTable: 'fake_articles',
            tableFields: [
                'id'           => ['name' => 'id', 'type' => 'int(11)', 'notnull' => 1, 'pk' => true, 'autoinc' => true, 'comment' => 'ID'],
                'title'        => ['name' => 'title', 'type' => 'varchar(100)', 'notnull' => 1, 'comment' => '标题'],
                'amount'       => ['name' => 'amount', 'type' => 'decimal(10,2)', 'notnull' => 0, 'comment' => '金额'],
                'status'       => ['name' => 'status', 'type' => 'tinyint(2)', 'notnull' => 1, 'default' => '1', 'comment' => '状态 1=启用 0=禁用'],
                'is_top'       => ['name' => 'is_top', 'type' => 'tinyint(1)', 'notnull' => 0, 'default' => '0', 'comment' => '置顶'],
                'level'        => ['name' => 'level', 'type' => "enum('gold','silver')", 'notnull' => 0, 'comment' => '等级'],
                'content'      => ['name' => 'content', 'type' => 'text', 'notnull' => 0, 'comment' => '内容'],
                'publish_date' => ['name' => 'publish_date', 'type' => 'date', 'notnull' => 0, 'comment' => '发布日期'],
                'create_time'  => ['name' => 'create_time', 'type' => 'datetime', 'notnull' => 0, 'comment' => '创建时间'],
                'update_time'  => ['name' => 'update_time', 'type' => 'datetime', 'notnull' => 0, 'comment' => '更新时间'],
            ],
        );
    }
}


/**
 * 可测试的 resolver：把与容器 / DB 交互的三个 protected 动作换成注入实现
 */
class TestableResolver extends MenuActionSchemaResolver
{
    /** @var callable|null */
    private $instantiateWith;

    private ?string $modelTable;
    private array $tableFields;

    public function __construct(
        ?callable $instantiateWith = null,
        ?string $modelTable = null,
        array $tableFields = [],
        array $options = []
    ) {
        parent::__construct($options);
        $this->instantiateWith = $instantiateWith;
        $this->modelTable      = $modelTable;
        $this->tableFields     = $tableFields;
    }

    protected function instantiate(string $controllerClass): ?object
    {
        if ($this->instantiateWith !== null) {
            return ($this->instantiateWith)($controllerClass);
        }

        return parent::instantiate($controllerClass);
    }

    protected function modelTableOf(object $instance): ?string
    {
        if ($this->modelTable !== null) {
            return $this->modelTable;
        }

        return parent::modelTableOf($instance);
    }

    protected function tableFieldsOf(string $table): array
    {
        if ($this->tableFields !== []) {
            return $this->tableFields;
        }

        return parent::tableFieldsOf($table);
    }
}


/**
 * 生成式 CRUD 控制器的典型形态：defineForm() 纯声明式（第1级应命中）
 */
class FakeArticleCrudController
{
    use \app\common\controller\traits\CrudTrait;
    use \app\common\controller\traits\CrudSchemaTrait;

    protected function getModel(): Model
    {
        throw new \RuntimeException('本测试不访问模型');
    }

    protected function defineForm(Form $form): void
    {
        $form->add(Text::make('title')->label('标题')->required());
        $form->add(Select::make('status')->label('状态')->options(['1' => '正常', '0' => '停用'])->default('1'));
        $form->add(Number::make('amount')->label('金额'));
        $form->add(Datetime::make('publish_time')->label('发布时间'));
        $form->add(SwitchField::make('is_top')->label('置顶'));
        $form->add(Textarea::make('content')->label('内容'));
    }
}

/**
 * defineForm() 依赖「仅由构造函数初始化」的属性 → 第1级失败、第2级命中
 */
class FakeLevel2Controller
{
    use \app\common\controller\traits\CrudTrait;
    use \app\common\controller\traits\CrudSchemaTrait;

    /** 刻意不给默认值：newInstanceWithoutConstructor() 下访问会抛 Error */
    private array $labels;

    public function __construct()
    {
        $this->labels = ['title' => '标题（构造后）'];
    }

    protected function crudSchemaName(): string
    {
        return 'fake_level2';
    }

    protected function getModel(): Model
    {
        throw new \RuntimeException('本测试不访问模型');
    }

    protected function defineForm(Form $form): void
    {
        $form->add(Text::make('title')->label($this->labels['title']));
    }
}

/**
 * 无 Schema 声明（只有 CrudTrait）→ 第1/2级失败，走第3级 DB（由 resolver 接缝注入）
 */
class FakeDbController
{
    use \app\common\controller\traits\CrudTrait;

    protected function getModel(): Model
    {
        throw new \RuntimeException('本测试不访问模型');
    }
}

/**
 * 大量字段 → 触发 getList 的通用 filters 退化
 */
class FakeWideController
{
    use \app\common\controller\traits\CrudTrait;
    use \app\common\controller\traits\CrudSchemaTrait;

    protected function getModel(): Model
    {
        throw new \RuntimeException('本测试不访问模型');
    }

    protected function defineForm(Form $form): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $form->add(Text::make('f_' . $i)->label('字段' . $i));
        }
    }
}

/**
 * 敏感字段剔除用例
 */
class FakeSensitiveController
{
    use \app\common\controller\traits\CrudTrait;
    use \app\common\controller\traits\CrudSchemaTrait;

    protected function getModel(): Model
    {
        throw new \RuntimeException('本测试不访问模型');
    }

    protected function defineForm(Form $form): void
    {
        $form->add(Text::make('title')->label('标题'));
        $form->add(Text::make('password')->label('口令'));
        $form->add(Text::make('api_secret')->label('密钥'));
        $form->add(Text::make('token_hash')->label('令牌散列'));
    }
}

/**
 * 完全无 Schema 来源的控制器
 */
class FakeNoSchemaController
{
    protected function getModel(): Model
    {
        throw new \RuntimeException('本测试不访问模型');
    }
}
