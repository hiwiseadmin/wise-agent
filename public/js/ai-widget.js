/**
 * ai-widget.js — AI 挂件注入（ESM 功能模块，D3-2）
 *
 * 由 wise-agent 包通过 ExtPoints::ADMIN_FRONTEND_MODULE 注册（优先级排在
 * agent-chat 之后），宿主 AdminTrait 注入 wsFeatureModules，wise-bootstrap.js
 * 按序 import 并调用 init()。
 *
 * 职责：按「页面类型 + 后端挂件配置」注入四类挂件，前端 0 行代码：
 *   - assistant      AI 助手入口（顶栏机器人图标 → 居中弹窗；**复用 agent-chat.js 的 agentChat.init()**，不重复实现聊天壳，验收 10）；
 *   - list_filter    列表页「自然语言筛选」输入框（对接 A3 `lq_query`）；
 *   - form_fill      表单页「AI 智能填充」按钮（对接 A2 `form_fill`）；
 *   - detail_explain 详情页「AI 解读这条数据」按钮（对接自动 Skill）。
 *
 * 挂件只负责「挂载入口 + 触发」；触发后的能力调用由既有 A2/A3 工具承担，
 * 本模块不重复实现对话/查询逻辑。
 */
'use strict';

const CONFIG_URL = '/admin/ai/widget/config';

/** 页面类型检测：优先 body 数据属性，退回 DOM 特征 */
function detectPageType() {
    const body = document.body;
    if (body.dataset.wisePageType) {
        return body.dataset.wisePageType; // 'list' | 'form' | 'detail'
    }

    const hasList = !!document.querySelector('.bootstrap-table, table#table, table.table');
    const hasForm = !!document.querySelector('form');

    if (hasForm && !hasList) {
        return 'form';
    }
    if (hasList) {
        return 'list';
    }
    if (document.querySelector('[data-detail], .detail-view, .view-detail')) {
        return 'detail';
    }
    return null;
}

/** 拉取后端挂件配置（全局开关 + 插件级声明） */
async function fetchConfig() {
    try {
        const res  = await fetch(CONFIG_URL, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const data = await res.json();
        return data && data.code === 0 ? (data.data || {}) : {};
    } catch (e) {
        return {};
    }
}

/**
 * 给动态 import 的 URL 追加框架全局版本号（?v= 包版本 / &_v= 全局版本）
 *
 * 相对路径 import 解析出的 URL **不带版本号**，对浏览器是另一个模块 URL ——
 * 既可能命中旧缓存，又会加载出第二个模块实例（两个实例各绑一次弹窗头部按钮，
 * 点击就变成「触发两次」）。所有动态加载都必须走框架 helper。
 */
function versionedUrl(url) {
    const util = window.Wise && window.Wise.util;
    if (util && typeof util.asset === 'function') {
        return util.asset(url);
    }
    return url;
}

/**
 * AI 助手入口（顶栏图标 → 居中弹窗）：复用 agent-chat 的 init，不重复实现聊天壳
 *
 * 优先使用框架已注册的实例（`window.Wise.agentChat`，URL 带版本号）。
 * 只有在框架没注册实例时才自行 import —— 此时不存在「第二个实例」冲突，
 * 但 URL 仍必须走 versionedUrl()，否则会加载到浏览器缓存里的旧文件。
 */
function mountAssistant() {
    const existing = window.Wise && window.Wise.agentChat;
    if (existing && typeof existing.init === 'function') {
        try {
            return Promise.resolve(existing.init());
        } catch (e) {
            return Promise.resolve();
        }
    }

    return import(/* @vite-ignore */ versionedUrl('./agent-chat.js'))
        .then(({ agentChat }) => agentChat.init())
        .catch(() => { /* agent-chat 加载失败时静默，不影响其它挂件 */ });
}

/** 列表页「自然语言筛选」输入框 */
function mountListFilter() {
    const toolbar = document.querySelector('.toolbar, #toolbar, .card-tools');
    if (!toolbar) {
        return;
    }
    if (toolbar.querySelector('.wa-nl-filter')) {
        return; // 已注入
    }

    const input = document.createElement('input');
    input.type        = 'text';
    input.placeholder = '用一句话筛选（如：上月华东区的退货单）';
    input.className   = 'form-control form-control-sm wa-nl-filter';
    input.style.width = '220px';

    toolbar.appendChild(input);

    // 触发 A3 `lq_query`：把自然语言交给 AI 转成 getList 查询参数。
    // 具体交互（回车 → 调 agent-chat 发起 lq_query → 回填表格筛选）在
    // agent-chat 的对话管线内完成，本处仅挂载入口。
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && input.value.trim()) {
            window.dispatchEvent(new CustomEvent('wise:ai:nl-filter', {
                detail: { query: input.value.trim() },
            }));
        }
    });
}

/** 表单页「AI 智能填充」按钮 */
function mountFormFill() {
    const form = document.querySelector('form');
    if (!form || form.querySelector('.wa-fill-btn')) {
        return;
    }

    const btn = document.createElement('button');
    btn.type      = 'button';
    btn.className = 'btn btn-sm btn-outline-primary wa-fill-btn ms-2';
    btn.textContent = 'AI 智能填充';

    // 触发 A2 `form_fill`：由 agent-chat 对话管线消费当前表单 schema。
    btn.addEventListener('click', () => {
        window.dispatchEvent(new CustomEvent('wise:ai:form-fill', { detail: { form } }));
    });

    const submitRow = form.querySelector('.card-footer, .form-footer, [type="submit"]')?.parentElement
        ?? form.querySelector('[type="submit"]')?.parentElement
        ?? form;

    submitRow.appendChild(btn);
}

/** 详情页「AI 解读这条数据」按钮 */
function mountDetailExplain() {
    const body = document.body;
    if (body.querySelector('.wa-explain-btn')) {
        return;
    }

    const btn = document.createElement('button');
    btn.type      = 'button';
    btn.className = 'btn btn-sm btn-outline-secondary wa-explain-btn';
    btn.textContent = 'AI 解读这条数据';

    btn.addEventListener('click', () => {
        window.dispatchEvent(new CustomEvent('wise:ai:detail-explain', {
            detail: { href: location.href },
        }));
    });

    const container = document.querySelector('.card-header, .content-header, .page-header');
    (container ?? body).appendChild(btn);
}

async function init() {
    const pageType = detectPageType();
    const config   = await fetchConfig();

    if (!config.enabled) {
        return;
    }

    // 悬浮入口：全局挂载（复用 agentChat.init）
    if (config.assistant) {
        await mountAssistant();
    }

    // 页面类型挂载对应入口
    if (pageType === 'list' && config.list_filter) {
        mountListFilter();
    }
    if (pageType === 'form' && config.form_fill) {
        mountFormFill();
    }
    if (pageType === 'detail' && config.detail_explain) {
        mountDetailExplain();
    }
}

export { init };
export default { init };
