/**
 * wise-agent-chat.js — AI 助手聊天模块
 *
 * 单一入口形态：居中弹窗（Bootstrap Modal），共用同一份会话状态与消息流。
 *   - 顶部导航栏机器人图标触发；Shift+A 快捷键同样唤起。
 *   - **不再**挂右下角浮动按钮：它常驻页面右下角、压在内容之上（分页器 / 表格末行
 *     / 批量操作条都可能被盖住），入口统一收敛到顶栏。
 *   - 原「右侧抽屉」形态已下线（顶栏图标原本触发抽屉），避免同一功能两套外壳并存。
 *
 * 后端对接 wise-agent 的 REST API：
 *   - POST /admin/ai/session/create   创建会话
 *   - GET  /admin/ai/session/detail   恢复会话消息
 *   - POST /admin/ai/chat/stream      SSE 流式聊天（XHR 增量解析）
 *   - POST /admin/ai/chat/stop        停止生成
 *
 * SSE 事件类型：token / tool_call / tool_result / usage / heartbeat / stopped / error / done
 *
 * 正文渲染：
 *   - AI 回复（assistant）交由框架组件 WsMarkdown 渲染（statics/libs/ws-markdown，
 *     随 wise-bundle.js 常驻）：先转义再解析，raw HTML 无法存活。
 *   - 用户消息与系统提示条保持纯文本转义：用户输入不应被解读成文档结构。
 *   - 流式期间由 WsMarkdown.stream() 负责节流重渲染（Markdown 全量渲染是 O(n)，
 *     逐 token 渲染退化成 O(n²)）；组件缺失（更早版本的框架）时降级为纯文本。
 *
 *
 * 弹窗形态支持拖拽与缩放，位置与尺寸持久化在 localStorage（wise.agent.modalGeom），
 * 下次打开沿用；视口变小时自动回夹到可见区域。
 *
 * **会话隔离**：会话 ID 存 `sessionStorage`（`wise.agent.session`）⇒ **每个标签页一个会话**，
 * 同一账号多开标签页互不干扰（在同一标签页内跳转/刷新仍保持上下文）。
 * 注意与弹窗几何的区别：几何是**偏好设置**、应当跨标签页共享（localStorage），
 * 会话是**对话上下文**、必须按标签页隔离（sessionStorage）。
 *
 * @module wise-agent-chat
 * @version 1.3.0
 */

const $ = window.jQuery;
const HotKey = window.HotKey;

/**
 * 入口资源版本号（取自后端注入的入口 URL ?v=）
 *
 * 入口模块 URL 上的查询串不会自动传递给同目录兄弟资源（静态 import、
 * 动态注入的 <link>），缺少版本号时浏览器会持续命中旧缓存，升级后出现
 * 「新 JS + 旧 CSS / 旧渲染器」的错配。故解析出来后显式透传。
 */
const ASSET_VERSION = new URL(import.meta.url).searchParams.get('v') || '';

// ============================================================
// 自注入样式：跟随本模块所在目录，适配任意发布路径，
// 宿主模板无需再单独引入 CSS。
// ============================================================
(function ensureCss() {
    if (document.getElementById('wise-agent-chat-style')) return;
    const link = document.createElement('link');
    link.id = 'wise-agent-chat-style';
    link.rel = 'stylesheet';
    const url = new URL('../css/agent-chat.css', import.meta.url);
    if (ASSET_VERSION) url.searchParams.set('v', ASSET_VERSION);
    link.href = url.href;
    document.head.appendChild(link);
})();

// ============================================================
// 状态
// ============================================================
const SESSION_KEY = 'wise.agent.session';
/** 弹窗几何（位置 + 尺寸）的本地持久化键（**应当跨标签页共享**，继续用 localStorage） */
const GEOM_KEY = 'wise.agent.modalGeom';

/**
 * 会话 ID 存储：**按标签页独立**（sessionStorage）
 *
 * ⚠️ 这里刻意**不用 localStorage**：`localStorage` 同源**跨标签页/窗口共享**，
 * 于是同一账号多开标签页会读到同一个 `session_id` —— 两个页面的消息互相可见，
 * 且任一方点「新建会话」都会在其它标签页刷新后把对方带过去。
 *
 * `sessionStorage` 是**每个标签页独立**的：一标签页一会话、互不干扰；
 * 同一标签页内跳转/刷新仍保持上下文（这正是"刷新后保持会话"的原意）。
 */
const sessionStore = {
    get() { try { return window.sessionStorage.getItem(SESSION_KEY) || ''; } catch (_) { return ''; } },
    // 隐私模式 / 配额满时静默降级：本次不记忆，功能不受影响（与弹窗几何同一口径）
    set(id) { try { window.sessionStorage.setItem(SESSION_KEY, id); } catch (_) { /* ignore */ } },
    clear() { try { window.sessionStorage.removeItem(SESSION_KEY); } catch (_) { /* ignore */ } },
};

/**
 * 一次性迁移：把升级前存在 `localStorage` 里的**共享会话**认领给**第一个打开的标签页**，
 * 随后删掉旧键 —— 否则它会一直残留成一个看不见的共享源，又把后续标签页绑回同一会话。
 * 认领之后其它标签页各自新建会话，即达成「一标签页一会话」。
 */
(function migrateLegacySessionKey() {
    if (sessionStore.get()) return;
    try {
        const legacy = window.localStorage.getItem(SESSION_KEY);
        if (legacy) {
            sessionStore.set(legacy);
            window.localStorage.removeItem(SESSION_KEY);
        }
    } catch (_) { /* localStorage 不可用：无从迁移，忽略 */ }
})();

const state = {
    sessionId: sessionStore.get(),
    sessionTitle: '',      // 当前会话标题（历史列表 / 创建 / 重命名时同步；仅供副标题显示）
    messages: [],          // [{ role: 'user'|'assistant'|'system', content, kind?, streaming?, done? }]
    streaming: false,      // 流式生成中（XHR 已发出）
    sending: false,        // 发送防重（ensureSession await 期间的并发保护，防重复提交）
    msgSeq: 0,             // 消息序号（restoreSession 异步竞态保护）
    draft: '',             // 当前流式生成的累积文本
    xhr: null,
    sseBuffer: '',         // SSE 增量解析缓冲（避免响应被截断导致 JSON 解析失败）
    sseLastIndex: 0,       // XHR responseText 已消费长度

    // ── 历史会话列表（惰性加载：入口常驻每个后台页，**不在 init 时请求**）──
    sessions: [],          // [{ id, title, count, updatedAt }]
    sessionsPage: 0,
    sessionsMore: false,   // 还有下一页
    sessionsLoading: false,
    renamingId: '',        // 正在内联重命名的会话（'' = 无）
    deletingId: '',        // 正在二次确认删除的会话（'' = 无）
};

/** 聊天面板容器（id 后缀命名）；抽屉形态已下线，只剩弹窗 */
const CONTAINERS = ['modal'];

/** DOM 缓存 */
const els = {};

/**
 * init() 幂等标记（挂在 window 上，而不是模块内变量）
 *
 * 本模块有三个可能的初始化来源：
 *   1. wise-bootstrap 的 `_loadFeatureModules()`（带 ?v= 的 URL，正常路径）；
 *   2. 后台首页控制器（statics/admin/index.js::Controller.index）为了兼容旧内核又显式调一次；
 *   3. ai-widget.js 的 `import('./agent-chat.js')` —— 该路径**不带** ?v=，
 *      对浏览器是另一个 URL，会解析出**第二个模块实例**。
 *
 * 外壳 DOM 的注入是幂等的（按 id 判重），但事件绑定不是：同一批 modal-header 按钮
 * 被 addEventListener 两次后，「历史会话」的 toggle 会一次点击触发两次（开→关），
 * 表现为「点了没反应」；「新建会话」则一次点出两个会话。
 *
 * 模块内变量只能拦住同一实例的重复调用（来源 1/2），拦不住来源 3 的第二实例，
 * 因此标记挂在 window 上 —— 同页面内所有实例共享。
 */
const INITED_FLAG = '__WS_AGENT_CHAT_INITED__';

/**
 * 框架 Markdown 组件（statics/libs/ws-markdown，随 wise-bundle.js 常驻）
 *
 * modules/ws/index.js 在 wise-bootstrap.js 顶部静态引入该组件，早于
 * _loadFeatureModules() 加载本模块，因此可同步取用，无加载时序问题。
 * 取不到（更早版本的框架）时本模块整体降级为纯文本渲染，不影响可用性。
 */
const Md = (window.Wise && window.Wise.ws && window.Wise.ws.WsMarkdown)
    || window.WsMarkdown
    || null;

/** 每个面板一个流式 writer，对应最后一条 AI 气泡（仅在流式会话期间存在） */
let writers = [];

/**
 * 取最后一条 AI 气泡元素
 *
 * 每次调用都重新查询 DOM：流式期间 tool_call / tool_result / 残留清理都会触发
 * 全量重建，缓存节点引用会指向已脱离文档的旧元素。
 *
 * @param {string} kind
 * @returns {HTMLElement|null}
 */
function lastAssistantBubble(kind) {
    const el = els[`messages_${kind}`];
    if (!el) return null;
    const nodes = el.querySelectorAll('.wa-msg.assistant .wa-msg-bubble');
    return nodes[nodes.length - 1] || null;
}

/**
 * 渲染 AI 回复正文：组件缺失或抛错时降级为纯文本转义
 * @param {string} text
 * @returns {string}
 */
function renderAssistantHTML(text) {
    if (Md) {
        try { return Md.render(text || ''); } catch (_) { /* 降级 */ }
    }
    return escapeHTML(text || '');
}

/**
 * 为两个面板挂载流式 writer
 *
 * 目标传「函数」而非元素：组件在每次绘制时重新解析目标，因此流式过程中
 * 即使发生全量重建（tool_call / tool_result / 残留清理），也无需重新挂载。
 *
 * @returns {boolean} 是否成功挂载（false = 组件缺失，调用方走纯文本降级）
 */
function attachWriters() {
    endWriters();
    if (!Md) return false;
    for (const kind of CONTAINERS) {
        const scroller = els[`messages_${kind}`];
        if (!scroller || !lastAssistantBubble(kind)) continue;
        writers.push(Md.stream(() => lastAssistantBubble(kind), {
            scrollContainer: scroller,
        }));
    }
    return writers.length > 0;
}

/** 结束并清理流式 writer（幂等） */
function endWriters() {
    for (const w of writers) w.destroy();
    writers = [];
}

/** 降级路径：无 Markdown 组件时按纯文本写入最后一条 AI 气泡 */
function writeDraftPlain() {
    for (const kind of CONTAINERS) {
        const bubble = lastAssistantBubble(kind);
        if (bubble) bubble.textContent = state.draft;
    }
}

/**
 * 代码块「复制」按钮事件委托
 *
 * 内容在流式/重建时会被整段替换，按钮节点存活期不固定，故委托到长期存在的
 * 消息容器上（该元素不会被 renderAll 替换，只换其 innerHTML）。
 * 具体处理逻辑由 WsMarkdown 提供，其 bindCopy() 幂等。
 *
 * @param {string} kind
 */
function bindCopyButtons(kind) {
    const container = els[`messages_${kind}`];
    if (container && Md) Md.bindCopy(container);
}

// ============================================================
// 工具函数
// ============================================================

/**
 * 读取 CSRF Token：优先 Cookie（服务端每次校验后会轮换，Cookie 始终最新），
 * 退回 meta 标签，再退回 window.csrfToken。
 *
 * Cookie 读取统一走框架实现（window.Cookie 随 wise-bundle.js 打包），
 * 本模块不再自建解析逻辑；宿主极旧、未提供 window.Cookie 时退回 meta 快照。
 *
 * @returns {string|null}
 */
function getCSRFToken() {
    const cookie = window.Cookie;
    if (cookie && typeof cookie.get === 'function') {
        const fromCookie = cookie.get('csrf_token');
        if (fromCookie) return fromCookie;
    }

    const meta = document.querySelector('meta[name="csrf-token"]');
    if (meta && meta.getAttribute('content')) return meta.getAttribute('content');
    return typeof window.csrfToken !== 'undefined' ? window.csrfToken : null;
}

/**
 * 通用 JSON POST（创建会话 / 停止生成等非流式请求）
 * @param {string} url
 * @param {Object} data
 * @returns {Promise<Object>}
 */
function postJSON(url, data) {
    const headers = { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    const token = getCSRFToken();
    if (token) headers['X-CSRF-TOKEN'] = token;
    return fetch(url, { method: 'POST', headers, body: JSON.stringify(data || {}), credentials: 'same-origin' })
        .then(r => r.json().catch(() => ({})));
}

/**
 * HTML 转义
 * @param {*} str
 * @returns {string}
 */
function escapeHTML(str) {
    return String(str == null ? '' : str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

// ============================================================
// 面板渲染
// ============================================================

/**
 * 渲染聊天面板（消息区 + 输入区）
 *
 * 面板只负责「消息流 + 输入」，标题栏由宿主的 modal-header 提供
 * （标题 / 历史会话 / 新建会话 / 关闭，见 ensureShell），不渲染内层 header。
 *
 * @param {string} kind - 容器后缀，目前只有 'modal'
 * @returns {boolean} 容器是否存在
 */
function renderPanel(kind) {
    const root = document.getElementById(`wise-agent-panel-${kind}`);
    if (!root) return false;

    root.innerHTML = `
        <div class="wise-agent-panel">
            <div class="wise-agent-sessions" id="wa-history-list-${kind}" hidden></div>
            <div class="wise-agent-messages" id="wa-messages-${kind}"></div>
            <div class="wise-agent-input">
                <div class="wa-input-row">
                    <textarea id="wa-input-${kind}" rows="1" placeholder="输入消息，Enter 发送，Shift+Enter 换行"></textarea>
                    <button type="button" class="wa-send-btn" id="wa-send-${kind}" title="发送"><i class="bi bi-send-fill"></i></button>
                </div>
                <div class="wa-input-hint">AI 生成内容仅供参考 · Shift+A 快速唤起</div>
            </div>
        </div>`;

    els[`messages_${kind}`] = document.getElementById(`wa-messages-${kind}`);
    els[`input_${kind}`] = document.getElementById(`wa-input-${kind}`);
    els[`send_${kind}`] = document.getElementById(`wa-send-${kind}`);
    // 副标题在 modal-header 上（id 形如 wise-agent-modal-subtitle），供 updateSubtitles 更新
    els[`subtitle_${kind}`] = document.getElementById(`wise-agent-${kind}-subtitle`);
    els[`historyList_${kind}`] = document.getElementById(`wa-history-list-${kind}`);

    bindPanelEvents(kind);
    bindCopyButtons(kind);
    return true;
}

/**
 * 绑定单个面板的事件（输入框 / 发送 / 新建会话）
 * @param {string} kind
 */
function bindPanelEvents(kind) {
    const input = els[`input_${kind}`];

    // Enter 发送，Shift+Enter 换行，中文输入法组合期间不触发
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
            e.preventDefault();
            submitInput(kind);
        }
    });

    // 输入框自动增高（上限 96px）
    input.addEventListener('input', () => {
        input.style.height = 'auto';
        input.style.height = `${Math.min(input.scrollHeight, 96)}px`;
    });

    els[`send_${kind}`].addEventListener('click', () => submitInput(kind));
    // 新建会话 / 历史会话按钮在 modal-header 上，由 init() 绑定（见 ensureShell），
    // 面板内不再重复提供按钮。历史列表行内动作走**事件委托**（每次只换 innerHTML，不重复绑事件）

    const box = els[`historyList_${kind}`];
    if (box) {
        box.addEventListener('click', onSessionListClick);
        box.addEventListener('keydown', onSessionListKeydown);
    }
}

/**
 * 空状态提示 HTML
 * @returns {string}
 */
function emptyStateHTML() {
    return `<div class="wise-agent-empty">
        <i class="bi bi-robot"></i>
        <p>你好，我是 AI 助手</p>
        <small>可以帮你分析数据、生成代码、执行后台任务<br>发送第一条消息开始吧</small>
    </div>`;
}

/**
 * 单条消息 HTML
 *
 * AI 回复走 WsMarkdown 渲染（组件缺失时降级为纯文本）；用户消息与系统提示保持
 * 纯文本转义 + pre-wrap —— 既避免用户输入被解读成文档结构，也让换行观感稳定。
 *
 * 「等待光标」只在尚无正文时显示：有内容后继续挂着会跟在最后一个块元素之后
 * 另起一行，且中途发生全量重建时还会重新出现，故绑定到「无内容」而非「流式中」。
 *
 * @param {Object} m
 * @returns {string}
 */
function renderMessageHTML(m) {
    if (m.role === 'system') {
        if (m.kind === 'tool') {
            const icon = m.done ? 'bi-check-circle' : 'bi-arrow-repeat';
            return `<div class="wa-tool-line${m.done ? ' ok' : ''}"><i class="bi ${icon}"></i>${escapeHTML(m.content)}</div>`;
        }
        return `<div class="wa-msg system"><div class="wa-msg-bubble">${escapeHTML(m.content)}</div></div>`;
    }
    const icon = m.role === 'user' ? 'bi-person-fill' : 'bi-robot';
    const typing = m.streaming && !m.content ? ' wa-msg-typing' : '';
    const isMarkdown = m.role === 'assistant' && !!Md;
    const content = isMarkdown
        ? renderAssistantHTML(m.content || '')
        : escapeHTML(m.content || '');
    return `<div class="wa-msg ${m.role}${typing}">
        <span class="wa-msg-avatar"><i class="bi ${icon}"></i></span>
        <div class="wa-msg-bubble${isMarkdown ? ' ws-md' : ''}">${content}</div>
    </div>`;
}

/**
 * 消息区是否已贴近底部
 *
 * 用于区分「跟随最新内容」与「用户正在上翻阅读」：长回答期间不应把
 * 用户的阅读位置反复拉回底部。
 *
 * @param {HTMLElement} el
 * @returns {boolean}
 */
function isNearBottom(el) {
    return el.scrollHeight - el.scrollTop - el.clientHeight < 48;
}

/**
 * 全量重建消息列表
 *
 * @param {boolean} [forceBottom=true] 是否强制滚到底部；传 false 时保持原阅读位置
 *        （仅当原本已贴底才继续跟随）
 */
function renderAll(forceBottom = true) {
    const isEmpty = state.messages.length === 0;
    for (const kind of CONTAINERS) {
        const el = els[`messages_${kind}`];
        if (!el) continue;
        const stick = forceBottom || isNearBottom(el);
        const prevTop = el.scrollTop;
        el.innerHTML = isEmpty ? emptyStateHTML() : state.messages.map(renderMessageHTML).join('');
        el.scrollTop = stick ? el.scrollHeight : prevTop;
    }
}

/**
 * 流式 token 追加：更新状态并交给各面板的 writer 渲染
 *
 * 节流不在此处实现：WsMarkdown.stream() 内部按 throttle 合并高频 token
 * （Markdown 全量渲染是 O(n)，逐 token 渲染会退化成 O(n²)）。
 * 组件缺失时退化为纯文本逐 token 写入（等价于引入组件前的行为）。
 *
 * @param {string} text
 */
function appendToken(text) {
    if (!text) return;
    state.draft += text;

    const last = state.messages[state.messages.length - 1];
    if (last && last.role === 'assistant' && last.streaming) {
        last.content = state.draft;
    }

    if (writers.length) {
        for (const w of writers) w.push(text);
    } else {
        writeDraftPlain();
    }
}

/**
 * 更新面板副标题（优先会话标题，缺失时退化成会话短 ID）
 */
function updateSubtitles() {
    const sid = state.sessionId;
    const short = sid ? sid.slice(0, 8) : '';
    // 优先显示会话标题：多会话之后，"我现在在哪个会话里"必须一眼可见；
    // 标题未知（列表尚未拉取 / 未命名）时退化成短 ID
    const title = state.sessionTitle ? state.sessionTitle.slice(0, 24) : '';
    const label = title || (sid ? short : '');
    for (const kind of CONTAINERS) {
        const el = els[`subtitle_${kind}`];
        if (el) el.textContent = label ? `WiseAgent · ${label}` : 'WiseAgent';
    }
}

/**
 * 发送/停止按钮状态同步
 */
function updateSendButtons() {
    for (const kind of CONTAINERS) {
        const btn = els[`send_${kind}`];
        const input = els[`input_${kind}`];
        if (!btn || !input) continue;
        if (state.streaming) {
            btn.title = '停止生成';
            btn.innerHTML = '<i class="bi bi-stop-fill"></i>';
            input.setAttribute('disabled', 'disabled');
            input.placeholder = 'AI 正在生成…';
        } else {
            btn.title = '发送';
            btn.innerHTML = '<i class="bi bi-send-fill"></i>';
            input.removeAttribute('disabled');
            input.placeholder = '输入消息，Enter 发送，Shift+Enter 换行';
        }
    }
}

// ============================================================
// 会话管理
// ============================================================

/**
 * 确保存在会话 ID（无则创建）
 * @returns {Promise<string>}
 */
async function ensureSession() {
    if (state.sessionId) return state.sessionId;
    const res = await postJSON('/admin/ai/session/create', { title: '后台 AI 助手会话' });
    const code = res ? res.code : undefined;
    if (code === 0 || code === 200) {
        if (res.data && res.data.session_id) {
            state.sessionId = res.data.session_id;
            state.sessionTitle = res.data.title ? String(res.data.title) : '';
            sessionStore.set(state.sessionId);
            updateSubtitles();
            // 历史列表已拉过时刷新一下（新会话应出现在顶部；没打开过则不打扰）
            if (state.sessions.length > 0) loadSessions(true);
            return state.sessionId;
        }
    }
    throw new Error((res && res.msg) || '创建会话失败，请稍后重试');
}

/**
 * 恢复上次会话消息（刷新页面后保持上下文）
 */
async function restoreSession() {
    if (!state.sessionId) {
        renderAll();
        return;
    }
    // 竞态保护：记录发起时的消息序号。若历史接口返回前用户已发送新消息（msgSeq 变化），
    // 直接丢弃旧历史，避免异步覆盖把正在流式的对话冲掉 / 产生重复
    const seqAtStart = state.msgSeq;
    try {
        const res = await fetch(`/admin/ai/session/detail?session_id=${encodeURIComponent(state.sessionId)}`)
            .then(r => r.json())
            .catch(() => ({}));
        if (seqAtStart !== state.msgSeq) return;
        if (res && res.code === 0 && res.data && Array.isArray(res.data.messages)) {
            state.messages = res.data.messages
                .filter(m => m.role === 'user' || m.role === 'assistant')
                .map(m => ({ role: m.role, content: (m.content || '').toString() }));
            updateSubtitles();
        } else {
            // 会话已失效（不存在/无权限），重置
            resetSession();
        }
    } catch (_) {
        // 网络异常：保持现状，静默降级
    }
    renderAll();
}

/**
 * 重置会话（新建会话 / 会话失效）
 */
async function newSession() {
    if (state.streaming) stopStream();
    endWriters();
    state.messages = [];
    state.sessionId = '';
    state.sessionTitle = '';
    state.draft = '';
    sessionStore.clear();
    closeSessionList();        // 新建会话时收起历史面板（避免"看着旧列表却在新会话里"）
    renderAll();
    updateSubtitles();
    try {
        await ensureSession();
        updateSubtitles();
    } catch (e) {
        pushSystemMsg(e.message || '创建会话失败');
    }
}

function resetSession() {
    endWriters();
    state.sessionId = '';
    state.messages = [];
    state.draft = '';
    sessionStore.clear();
    updateSubtitles();
}

// ============================================================
// 历史会话（列表 / 切换 / 重命名 / 删除）
//
// 消费这些既有接口（`RouteRegistrar.php:56-61`，本模块不新增后端）：
//   GET  /admin/ai/sessions?page&limit → {code:0,data:{list:[{session_id,title,message_count,update_time,…}],page,limit}}
//   POST /admin/ai/session/rename      → {session_id,title}
//   POST /admin/ai/session/delete      → {session_id}
//
// 归属由**后端**按当前用户过滤与校验（`getUserSessions()` 按 user_type+user_id 过滤、
// 各写接口走 `isOwnedByCurrentUser()`），因此前端不传任何用户标识 ——
// 越权在这里既做不到、也不需要前端兜。
//
// 列表 **惰性加载**：入口常驻每个后台页，若在 init 时请求会给每个页面都加一次
// 无用请求；改为"打开面板时才拉，且每次打开都刷新"（会话可能在其它标签页被改过）。
// ============================================================

const SESSIONS_PAGE_SIZE = 20;

/**
 * 拉取会话列表
 * @param {boolean} reset - true 从第一页重拉（覆盖），false 追加下一页
 */
async function loadSessions(reset = false) {
    if (state.sessionsLoading) return;
    state.sessionsLoading = true;
    renderSessionList();

    try {
        const page = reset ? 1 : state.sessionsPage + 1;
        const res = await fetch(`/admin/ai/sessions?page=${page}&limit=${SESSIONS_PAGE_SIZE}`, {
            credentials: 'same-origin',
        }).then(r => r.json()).catch(() => ({}));

        if (res && res.code === 0 && res.data && Array.isArray(res.data.list)) {
            const list = res.data.list.map(normalizeSession).filter(s => s.id);
            state.sessions = reset ? list : state.sessions.concat(list);
            state.sessionsPage = page;
            state.sessionsMore = list.length >= SESSIONS_PAGE_SIZE;
        } else {
            pushSystemMsg((res && res.msg) || '加载历史会话失败');
        }
    } finally {
        state.sessionsLoading = false;
        renderSessionList();
    }
}

/**
 * 归一化后端返回的一行（字段缺失时给安全默认，避免渲染出 undefined）
 * @param {object} row
 * @returns {{id: string, title: string, count: number, updatedAt: string}}
 */
function normalizeSession(row) {
    const src = row && typeof row === 'object' ? row : {};
    return {
        id: src.session_id ? String(src.session_id) : '',
        title: src.title ? String(src.title) : '',
        count: Number(src.message_count) || 0,
        updatedAt: src.update_time ? String(src.update_time) : '',
    };
}

/** 打开/关闭历史会话面板（打开前先收起其它容器，避免叠在消息区上） */
function toggleSessionList(kind) {
    const box = els[`historyList_${kind}`];
    if (!box) return;

    const willShow = box.hasAttribute('hidden');

    for (const k of CONTAINERS) {
        const other = els[`historyList_${k}`];
        if (other && other !== box) other.setAttribute('hidden', '');
    }

    if (willShow) {
        box.removeAttribute('hidden');
        state.renamingId = '';
        state.deletingId = '';
        renderSessionList();
        loadSessions(true);          // 每次打开都刷新（不 await：先渲染"加载中"）
    } else {
        box.setAttribute('hidden', '');
    }
}

function closeSessionList() {
    for (const k of CONTAINERS) {
        const box = els[`historyList_${k}`];
        if (box) box.setAttribute('hidden', '');
    }
}

/** 把列表渲染进两个容器（只换 innerHTML；事件在 bindPanelEvents 里委托绑定） */
function renderSessionList() {
    for (const kind of CONTAINERS) {
        const box = els[`historyList_${kind}`];
        if (box && box.isConnected) box.innerHTML = sessionListHTML();
    }
}

/** 列表整体 HTML */
function sessionListHTML() {
    if (state.sessionsLoading && state.sessions.length === 0) {
        return '<div class="wa-history-hint">加载中…</div>';
    }
    if (state.sessions.length === 0) {
        return '<div class="wa-history-hint">还没有历史会话<br><small>发送第一条消息即会自动创建</small></div>';
    }

    let html = `<div class="wa-history-head"><span>历史会话</span><span class="wa-history-num">${state.sessions.length}</span></div>`;
    html += '<div class="wa-history-items">';

    for (const s of state.sessions) html += sessionRowHTML(s);

    html += '</div>';

    if (state.sessionsMore) {
        html += `<button type="button" class="wa-history-more" data-session-action="more"${state.sessionsLoading ? ' disabled' : ''}>`
            + (state.sessionsLoading ? '加载中…' : '加载更多') + '</button>';
    }

    return html;
}

/**
 * 单行 HTML（含内联重命名输入框 / 二次确认删除两种临时态）
 *
 * 标题是**用户输入**，一律 `escapeHTML`（该函数连 `"`/`'` 都转义，故也安全用于属性值）。
 * @param {{id:string,title:string,count:number,updatedAt:string}} s
 */
function sessionRowHTML(s) {
    const current = !!s.id && s.id === state.sessionId;
    const title = s.title ? escapeHTML(s.title) : '未命名会话';

    if (state.renamingId === s.id) {
        return `<div class="wa-history-item editing" data-session-id="${escapeHTML(s.id)}">
            <input type="text" class="wa-history-rename" value="${escapeHTML(s.title)}" maxlength="60" placeholder="会话名称">
            <div class="wa-history-actions">
                <button type="button" class="wa-history-btn primary" data-session-action="rename-save" title="保存"><i class="bi bi-check-lg"></i></button>
                <button type="button" class="wa-history-btn" data-session-action="rename-cancel" title="取消"><i class="bi bi-x-lg"></i></button>
            </div>
        </div>`;
    }

    const confirming = state.deletingId === s.id;
    const meta = [s.count > 0 ? `${s.count} 条` : '', s.updatedAt ? escapeHTML(s.updatedAt.slice(0, 16)) : '']
        .filter(Boolean).join(' · ');

    const actions = confirming
        ? `<button type="button" class="wa-history-btn danger" data-session-action="delete-confirm" title="确认删除"><i class="bi bi-trash-fill"></i></button>
           <button type="button" class="wa-history-btn" data-session-action="delete-cancel" title="取消"><i class="bi bi-x-lg"></i></button>`
        : `<button type="button" class="wa-history-btn" data-session-action="rename" title="重命名"><i class="bi bi-pencil"></i></button>
           <button type="button" class="wa-history-btn" data-session-action="delete" title="删除"><i class="bi bi-trash"></i></button>`;

    return `<div class="wa-history-item${current ? ' active' : ''}" data-session-id="${escapeHTML(s.id)}">
        <button type="button" class="wa-history-main" data-session-action="switch" title="${title}">
            <span class="wa-history-title">${title}</span>
            ${meta ? `<span class="wa-history-meta">${meta}</span>` : ''}
        </button>
        <div class="wa-history-actions">${actions}</div>
        ${current ? '<span class="wa-history-current">当前</span>' : ''}
    </div>`;
}

/** 行内动作（事件委托，见 bindPanelEvents） */
function onSessionListClick(e) {
    const trigger = e.target.closest('[data-session-action]');
    if (!trigger) return;

    const action = trigger.getAttribute('data-session-action');
    const row = trigger.closest('[data-session-id]');
    const id = row ? row.getAttribute('data-session-id') : '';

    switch (action) {
        case 'more':
            loadSessions(false);
            return;
        case 'switch':
            switchToSession(id);
            return;
        case 'rename':
            state.renamingId = id;
            state.deletingId = '';
            renderSessionList();
            focusRenameInput();
            return;
        case 'rename-cancel':
            state.renamingId = '';
            renderSessionList();
            return;
        case 'rename-save': {
            const input = row ? row.querySelector('.wa-history-rename') : null;
            submitSessionRename(id, input ? input.value.trim() : '');
            return;
        }
        case 'delete':
            // 二次确认（内联按钮切换）——本模块不引入 alert/confirm/layer 依赖
            state.deletingId = id;
            state.renamingId = '';
            renderSessionList();
            return;
        case 'delete-cancel':
            state.deletingId = '';
            renderSessionList();
            return;
        case 'delete-confirm':
            submitSessionDelete(id);
            return;
        default:
    }
}

/** 重命名输入框：Enter 保存 / Esc 取消 */
function onSessionListKeydown(e) {
    if (!e.target.classList || !e.target.classList.contains('wa-history-rename')) return;

    const row = e.target.closest('[data-session-id]');
    const id = row ? row.getAttribute('data-session-id') : '';

    if (e.key === 'Enter') {
        e.preventDefault();
        submitSessionRename(id, e.target.value.trim());
    } else if (e.key === 'Escape') {
        e.preventDefault();
        state.renamingId = '';
        renderSessionList();
    }
}

/** 重命名输入框聚焦 + 光标置尾（渲染后调用） */
function focusRenameInput() {
    for (const kind of CONTAINERS) {
        const box = els[`historyList_${kind}`];
        if (!box || box.hasAttribute('hidden')) continue;
        const input = box.querySelector('.wa-history-rename');
        if (input) {
            input.focus();
            input.setSelectionRange(input.value.length, input.value.length);
            return;
        }
    }
}

/** 切换到指定会话（并把它设为**本标签页**的当前会话） */
async function switchToSession(id) {
    if (!id) return;

    if (id === state.sessionId) {
        closeSessionList();
        return;
    }

    if (state.streaming) stopStream();
    endWriters();

    state.sessionId = id;
    sessionStore.set(id);          // 按标签页独立（见文件头"会话隔离"）
    state.sessionTitle = titleOfSession(id);
    state.messages = [];
    state.draft = '';
    state.msgSeq++;               // 使在途的历史请求失效（与 restoreSession 的竞态保护同一机制）

    closeSessionList();
    updateSubtitles();
    renderAll();

    await restoreSession();
}

/** 会话标题（列表里有就用；没有则为空串，副标题会退化成短 ID） */
function titleOfSession(id) {
    const row = state.sessions.find(s => s.id === id);
    return row && row.title ? row.title : '';
}

/** 提交重命名 */
async function submitSessionRename(id, title) {
    if (!id) return;

    if (!title) {
        // 空标题无意义：直接退出编辑态（后端也要求 title 非空）
        state.renamingId = '';
        renderSessionList();
        return;
    }

    const res = await postJSON('/admin/ai/session/rename', { session_id: id, title });
    const ok = res && (res.code === 0 || res.code === 200);

    if (!ok) {
        pushSystemMsg((res && res.msg) || '重命名失败，请稍后重试');
        return;
    }

    const row = state.sessions.find(s => s.id === id);
    if (row) row.title = title;

    if (id === state.sessionId) {
        state.sessionTitle = title;
        updateSubtitles();
    }

    state.renamingId = '';
    renderSessionList();
}

/** 提交删除 */
async function submitSessionDelete(id) {
    if (!id) return;

    const res = await postJSON('/admin/ai/session/delete', { session_id: id });
    const ok = res && (res.code === 0 || res.code === 200);

    if (!ok) {
        state.deletingId = '';
        renderSessionList();
        pushSystemMsg((res && res.msg) || '删除失败，请稍后重试');
        return;
    }

    state.sessions = state.sessions.filter(s => s.id !== id);
    state.deletingId = '';

    if (id === state.sessionId) {
        // 删掉的是当前会话：重建一个空的（下一条消息会自动创建新会话）
        newSession();
        return;
    }

    renderSessionList();
}

/**
 * 插入一条系统提示消息
 * @param {string} text
 */
function pushSystemMsg(text) {
    state.messages.push({ role: 'system', content: text });
    renderAll();
}

// ============================================================
// 消息发送与 SSE 流式接收
// ============================================================

/**
 * 提交输入框内容（流式中点击则停止）
 * @param {string} kind
 */
function submitInput(kind) {
    if (state.streaming) {
        stopStream();
        return;
    }
    const input = els[`input_${kind}`];
    const text = input.value.trim();
    if (!text) return;
    input.value = '';
    input.style.height = 'auto';
    sendMessage(text);
}

/**
 * 发送用户消息并启动流式回复
 * @param {string} text
 */
async function sendMessage(text) {
    // 双保险防重：流式中 + ensureSession await 期间（sending）都禁止再次提交，
    // 避免「网络慢时连按 Enter / 双击发送」产生两条完全相同的消息
    if (state.streaming || state.sending) return;
    state.sending = true;
    let sessionId;
    try {
        sessionId = await ensureSession();
    } catch (e) {
        state.sending = false;
        pushSystemMsg(e.message || '创建会话失败');
        return;
    }
    state.sending = false;

    // 清理上一次异常中断残留的 streaming 气泡（上次回复未正常结束），
    // 防止新气泡与残留气泡并存造成视觉上的重复
    for (const m of state.messages) {
        if (m.streaming) {
            m.streaming = false;
            m.content = m.content || '(未完成)';
        }
    }

    state.messages.push({ role: 'user', content: text });
    state.messages.push({ role: 'assistant', content: '', streaming: true });
    state.draft = '';
    state.msgSeq++;
    // 必须重建：占位中的 AI 气泡不进 DOM 的话，流式 token 会被写进「上一条回答」
    // 的气泡里（appendToken 取的是 DOM 中最后一条 assistant 气泡）。
    // 此前仅在存在残留消息时才重建，导致从第二条消息起，流式期间会覆盖上一条
    // 回答的正文，直到流结束 renderAll() 才被纠正。
    renderAll();
    startStream(sessionId, text);
}

/**
 * 启动 XHR SSE 流式请求
 * @param {string} sessionId
 * @param {string} text
 */
function startStream(sessionId, text) {
    state.streaming = true;
    state.sseBuffer = '';
    state.sseLastIndex = 0;
    updateSendButtons();
    // 挂载流式 writer；须在 renderAll() 之后（占位气泡此时已存在于 DOM）
    attachWriters();

    const xhr = new XMLHttpRequest();
    state.xhr = xhr;
    xhr.open('POST', '/admin/ai/chat/stream');
    xhr.setRequestHeader('Content-Type', 'application/json');
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    const token = getCSRFToken();
    if (token) xhr.setRequestHeader('X-CSRF-TOKEN', token);

    // 增量读取：仅消费新增长度，残缺块留在缓冲等待补全
    xhr.onprogress = () => {
        const full = xhr.responseText || '';
        if (full.length > state.sseLastIndex) {
            state.sseBuffer += full.slice(state.sseLastIndex);
            state.sseLastIndex = full.length;
            drainSSE();
        }
    };
    xhr.onload = () => {
        // 兜底：某些代理可能不触发 onprogress，onload 时再消费一次
        const full = xhr.responseText || '';
        if (full.length > state.sseLastIndex) {
            state.sseBuffer += full.slice(state.sseLastIndex);
            state.sseLastIndex = full.length;
            drainSSE();
        }
        finishStream();
    };
    xhr.onerror = () => finishStream('网络错误，请求中断');
    xhr.onabort = () => finishStream();

    xhr.send(JSON.stringify({ message: text, session_id: sessionId }));
}

/**
 * 消费 SSE 缓冲中的完整事件块（以 \n\n 为界）
 */
function drainSSE() {
    let idx;
    while ((idx = state.sseBuffer.indexOf('\n\n')) !== -1) {
        const block = state.sseBuffer.slice(0, idx);
        state.sseBuffer = state.sseBuffer.slice(idx + 2);
        handleEventBlock(block);
    }
}

/**
 * 解析单个 SSE 事件块
 * @param {string} block - "event: xxx\ndata: {...}"
 */
function handleEventBlock(block) {
    let event = 'message';
    const dataParts = [];
    const lines = block.split('\n');
    for (const line of lines) {
        if (line.startsWith('event:')) {
            event = line.slice(6).trim();
        } else if (line.startsWith('data:')) {
            dataParts.push(line.slice(5).trim());
        }
    }
    if (!dataParts.length) return;
    let payload = {};
    try {
        payload = JSON.parse(dataParts.join('\n'));
    } catch (_) {
        return; // 块不完整，丢弃等待补全
    }
    dispatchEvent(event, payload);
}

/**
 * SSE 事件分发
 * @param {string} event
 * @param {Object} payload
 */
function dispatchEvent(event, payload) {
    switch (event) {
        case 'token':
            appendToken(payload.content || '');
            break;
        case 'tool_call': {
            const calls = payload.calls || [];
            const names = calls
                .map(c => (c.function && c.function.name) || 'tool')
                .join('、');
            const msg = { role: 'system', kind: 'tool', content: `正在调用工具：${names}…` };
            const aiIdx = state.messages.findIndex(m => m.role === 'assistant' && m.streaming);
            if (aiIdx >= 0) state.messages.splice(aiIdx, 0, msg);
            else state.messages.push(msg);
            renderAll();
            break;
        }
        case 'tool_result': {
            const list = state.messages;
            for (let i = list.length - 1; i >= 0; i--) {
                if (list[i].role === 'system' && list[i].kind === 'tool' && !list[i].done) {
                    list[i].done = true;
                    list[i].content = '✓ 工具执行完成';
                    break;
                }
            }
            renderAll();
            break;
        }
        case 'stopped':
            if (payload.message) appendToken(`\n\n${payload.message}`);
            finishStream();
            break;
        case 'error':
            finishStream(payload.message || '出错了，请重试');
            break;
        case 'done':
            finishStream();
            break;
        default:
            break; // usage / heartbeat 等仅作连接保活，前端无需处理
    }
}

/**
 * 结束当前流式会话（幂等）
 * @param {string} [errMsg] 错误信息（可选）
 */
function finishStream(errMsg) {
    if (!state.streaming && !errMsg) return;

    const last = state.messages[state.messages.length - 1];
    if (last && last.role === 'assistant' && last.streaming) {
        last.streaming = false;
        if (errMsg) {
            last.content = last.content
                ? `${last.content}\n\n⚠ ${errMsg}`
                : `⚠ ${errMsg}`;
        }
    } else if (errMsg) {
        state.messages.push({ role: 'assistant', content: `⚠ ${errMsg}` });
    }

    state.streaming = false;
    state.xhr = null;
    // 注意：不能在此重置 sseBuffer/sseLastIndex！
    // 若在 XHR onprogress 尚未全部触发时重置，下一次 onprogress 会
    // 把整个响应再次消费一遍，导致同一事件（如 error）被重复处理、
    // 渲染出两条完全相同的消息。
    endWriters();
    // 终态渲染：流式期间的节流结果可能滞后若干 token，此处做一次完整解析。
    // 不强制滚底 —— 用户若在生成过程中上翻阅读，不应被拉回底部。
    renderAll(false);
    updateSendButtons();
}

/**
 * 停止生成（通知后端优雅停止 + 中断连接）
 */
function stopStream() {
    if (!state.streaming) return;
    if (state.sessionId) {
        postJSON('/admin/ai/chat/stop', { session_id: state.sessionId }).catch(() => { /* ignore */ });
    }
    if (state.xhr) {
        try { state.xhr.abort(); } catch (_) { /* ignore */ }
    }
    finishStream('已停止生成');
}

// ============================================================
// 入口
// ============================================================

/**
 * 解析顶栏「扩展位」容器
 *
 * 框架在主导航最右侧提供了专属节点 #top-nav-ext（见 app/admin/view/index/index.html），
 * 顶栏扩展按钮应落在那里（全屏 / 用户菜单的右侧）。找不到该节点时退回「右对齐
 * 导航组 .navbar-nav.ms-auto」，再找不到则放弃（Shift+A 仍可唤起弹窗）。
 *
 * 历史问题：此前 prepend 进 `document.querySelector('.navbar .navbar-nav')`，
 * 命中的是左侧导航组（侧边栏折叠按钮那一组），导致入口出现在最左侧。
 *
 * @returns {HTMLElement|null}
 */
function resolveTopNav() {
    return document.getElementById('top-nav-ext')
        || document.querySelector('.app-header .navbar-nav.ms-auto')
        || null;
}

/**
 * 确保 AI 助手外壳 DOM 存在（自注入，宿主模板无需预置任何 AI 标记）
 * 注入：居中弹窗、顶栏机器人图标（best-effort）
 *
 * 右下角浮动按钮已移除：它固定压在页面右下角，会遮挡分页器 / 表格末行 /
 * 批量操作条等内容，入口统一走顶栏图标（+ Shift+A）。
 *
 * @returns {boolean} 是否处于可注入的主框架页（iframe 子页面返回 false）
 */
function ensureShell() {
    // 子框架 iframe 页面（插件内页）不注入全局 AI 外壳，避免污染子页面
    const subframeMeta = document.querySelector('meta[name="site-subframe"]');
    if (subframeMeta && subframeMeta.getAttribute('content') === 'true') {
        return false;
    }

    // 非后台主框架页（登录页 / 锁屏 / 独立错误页等）不注入：
    // 这些页面没有 app 框架容器，注入浮层只会留下一个点了没反应的按钮。
    // 正常路径下服务端已不再向这些页面下发本模块，此处是兜底。
    if (!document.querySelector('.app-wrapper') && !document.querySelector('.app-header')) {
        return false;
    }

    // 居中弹窗
    if (!document.getElementById('wiseAgentModal')) {
        const modal = document.createElement('div');
        modal.id = 'wiseAgentModal';
        modal.className = 'modal fade';
        modal.tabIndex = -1;
        modal.setAttribute('aria-labelledby', 'wiseAgentModalTitle');
        modal.setAttribute('aria-hidden', 'true');
        modal.setAttribute('data-bs-focus', 'false');
        modal.innerHTML = `
            <div class="modal-dialog" style="width: 560px; max-width: calc(100vw - 40px); height: min(640px, 82vh);">
                <div class="modal-content border-0" style="overflow: hidden;">
                    <div class="modal-header py-2 px-3 d-flex wise-agent-modal-header">
                        <div class="d-flex align-items-center gap-2 flex-fill">
                            <span class="wise-agent-logo rounded-circle d-inline-flex align-items-center justify-content-center"><i class="bi bi-robot"></i></span>
                            <div>
                                <h5 class="modal-title" id="wiseAgentModalTitle" style="font-size: 15px;">AI 助手</h5>
                                <small class="text-body-secondary" style="font-size: 11px;" id="wise-agent-modal-subtitle">WiseAgent · 会话待创建</small>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill" id="wise-agent-modal-history" title="历史会话">
                                <i class="bi bi-clock-history"></i>
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill" id="wise-agent-modal-new" title="新建会话">
                                <i class="bi bi-plus-lg"></i> 新建会话
                            </button>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="关闭"></button>
                        </div>
                    </div>
                    <div class="modal-body p-0">
                        <div id="wise-agent-panel-modal" class="h-100"></div>
                    </div>
                </div>
            </div>`;
        document.body.appendChild(modal);
    }

    // 顶栏 AI 入口（best-effort：找不到顶栏时静默跳过，Shift+A 仍可用）
    // 点击打开居中弹窗（原为打开右侧抽屉，抽屉形态已下线）
    if (!document.getElementById('wise-agent-nav-toggle')) {
        const nav = resolveTopNav();
        if (nav) {
            const li = document.createElement('li');
            li.className = 'nav-item d-none d-md-block';
            li.title = 'AI 助手 (Shift+A)';
            const a = document.createElement('a');
            a.href = 'javascript:;';
            a.className = 'nav-link';
            a.id = 'wise-agent-nav-toggle';
            a.setAttribute('role', 'button');
            a.setAttribute('aria-label', 'AI 助手');
            a.innerHTML = '<i class="bi bi-robot"></i>';
            a.addEventListener('click', (e) => { e.preventDefault(); openModal(); });
            li.appendChild(a);
            nav.appendChild(li);
            // 顶栏新增元素会挤占一级菜单可用宽度，派发一次 resize 让框架的
            // 菜单溢出计算（statics/admin/index.js 的 handleNavOverflow）重新测量
            window.dispatchEvent(new Event('resize'));
        }
    }

    return true;
}

/**
 * 模块初始化：渲染弹窗面板、绑定顶栏入口/快捷键、恢复会话
 * 外壳 DOM 由本模块自注入，仅在主框架页生效，子页面 iframe 内自动跳过。
 */
function init() {
    // 幂等：重复调用直接返回（详见 INITED_FLAG 的注释）
    if (window[INITED_FLAG]) return;
    if (!ensureShell()) return;
    window[INITED_FLAG] = true;

    if (!renderPanel('modal')) return;   // 面板容器由 modal-body 提供，标题栏复用 modal-header

    // 弹窗打开时聚焦输入框。
    // 延迟一帧：避免与 Bootstrap 5 的焦点管理（_enforceFocus，transition 结束后把
    // 焦点放到 modal 内首个可聚焦元素）竞争；modal 已加 data-bs-focus="false"，此为双保险。
    $('#wiseAgentModal').on('shown.bs.modal', () => {
        const input = els.input_modal;
        if (input) setTimeout(() => input.focus(), 60);
    });

    // 弹窗头部"新建会话"
    const modalNew = document.getElementById('wise-agent-modal-new');
    if (modalNew) modalNew.addEventListener('click', () => newSession());

    // 弹窗头部"历史会话"（弹窗形态的面板无自带 header，按钮在 modal-header 上）
    const modalHistory = document.getElementById('wise-agent-modal-history');
    if (modalHistory) modalHistory.addEventListener('click', () => toggleSessionList('modal'));

    // 弹窗可拖拽 + 可缩放（jquery-ui，项目 base.html 已引入，零新增依赖）
    bindModalDragResize();

    // Shift+A 唤起弹窗（AI 助手快捷键）
    const hotKey = new HotKey('wise-agent');
    hotKey.register('shift+a', () => { openModal() }, 'AI 助手');
    hotKey.subscribe('hotkey-triggered', (data) => {
        if (data.hotkey === 'shift+a') {
            openModal();
        }
    });

    updateSubtitles();
    renderAll();
    restoreSession();
}

/** 弹窗最小/最大尺寸约束（与 resizable 保持一致） */
const GEOM_MIN_W = 320;
const GEOM_MIN_H = 160;
const GEOM_MARGIN = 40;

const clamp = (v, min, max) => Math.min(Math.max(v, min), max);

/**
 * 读取本地保存的弹窗几何（left/top/width/height）
 * 数据损坏、字段缺失或非数字时一律返回 null，回退到居中。
 */
function loadGeom() {
    try {
        const raw = localStorage.getItem(GEOM_KEY);
        if (!raw) return null;
        const g = JSON.parse(raw);
        if (!g || typeof g !== 'object') return null;
        const fields = ['left', 'top', 'width', 'height'];
        for (const k of fields) {
            if (typeof g[k] !== 'number' || !isFinite(g[k])) return null;
        }
        return { left: g.left, top: g.top, width: g.width, height: g.height };
    } catch (_) {
        return null;
    }
}

/**
 * 保存当前弹窗几何到本地
 *
 * 时机：拖拽结束 / 缩放结束 / 弹窗关闭（兜底，覆盖 jquery-ui 事件未触发的边界）。
 * 宽高优先取 offsetWidth/offsetHeight（此时布局有效，能反映 max-width 的收缩结果）。
 */
function saveGeom(el) {
    try {
        localStorage.setItem(GEOM_KEY, JSON.stringify({
            left: Math.round(parseFloat(el.style.left) || 0),
            top: Math.round(parseFloat(el.style.top) || 0),
            width: Math.round(el.offsetWidth || parseFloat(el.style.width) || 560),
            height: Math.round(el.offsetHeight || parseFloat(el.style.height) || 640),
        }));
    } catch (_) {
        // localStorage 不可用（隐私模式 / 配额满）时静默降级：本次不记忆，功能不受影响
    }
}

/**
 * 给弹窗挂载 jquery-ui 的拖拽与缩放能力，并把位置/尺寸持久化到本地
 *
 * - 初始定位：有本地记录则还原（并回夹到当前视口内），否则居中。
 *   居中/还原都写入 left/top（dialog 为 absolute + margin:0），必须固化，
 *   否则 jquery-ui draggable 以 (0,0) 为基准，点击即瞬间跳到左上角；
 * - draggable：整条 modal-header 作为拖动区，基于已固化的 left/top 做偏移；
 * - resizable：右下角手柄（se）调整宽高，最小 320×160，最大不超过视口 -40px，
 *   缩放时 modal-content 高度 100% 跟随 dialog，消息区（flex:1）自动撑开；
 * - 持久化：拖拽/缩放结束及关闭时写入 localStorage（wise.agent.modalGeom），
 *   下次打开沿用；视口变小（换屏 / 缩窗口）导致旧坐标越界时自动收回视口内。
 *
 * 依赖 window.jQuery 的 ui 扩展（base.html 已 defer 引入 jquery-ui.min.js）。
 * jquery-ui 未加载（极少数子页面 / 异常环境）时静默跳过，弹窗仍可正常打开，只是不可拖拽缩放。
 */
function bindModalDragResize() {
    const $ = window.jQuery;
    if (!$ || !$.ui || !$.ui.draggable || !$.ui.resizable) return;

    const $dialog = $('#wiseAgentModal .modal-dialog');
    const el = $dialog[0];
    if (!el) return;

    // dialog 视口内可用上限（与 resizable 的 maxWidth/maxHeight 同规则）
    const maxW = () => Math.max(GEOM_MIN_W, window.innerWidth - GEOM_MARGIN);
    const maxH = () => Math.max(GEOM_MIN_H, window.innerHeight - GEOM_MARGIN);

    // 用户是否主动调整过弹窗（拖拽 / 缩放）。
    // 只有调整过才落盘：未调整时始终走居中，避免把「某次偶然的居中坐标」固化下来，
    // 导致换分辨率后开屏偏移。
    let geomDirty = false;
    const persistGeom = () => {
        geomDirty = true;
        saveGeom(el);
    };

    // 把 dialog 居中（写入 left/top）。仅在初始化、且无本地记录时执行一次：
    // 之后位置由 draggable/resizable 接管，不再随窗口变化强制居中。
    const center = () => {
        // 不能用 offsetWidth/Height：show.bs.modal 时布局尚未刷新，读到的是 0，
        // 会算出 left=innerWidth/2、top=innerHeight/2 这类错误坐标
        // （视口 1280×720 下实测得到 640/360，正确应为 360/65）。
        // dialog 宽高由内联 style 决定：初始 560 / min(640px, 82vh)，
        // 缩放后被 resizable 改写为具体 px —— 两者都能直接解析，与布局无关。
        const w = Math.min(parseFloat(el.style.width) || 560, window.innerWidth - GEOM_MARGIN);
        let h = parseFloat(el.style.height) || Math.min(640, Math.round(window.innerHeight * 0.82));
        h = Math.min(h, window.innerHeight);
        el.style.left = Math.max(0, Math.round((window.innerWidth - w) / 2)) + 'px';
        el.style.top = Math.max(0, Math.round((window.innerHeight - h) / 2)) + 'px';
    };

    // 还原本地记录的几何：先定尺寸，再按「实际可用宽高」把左上角回夹进视口，
    // 保证换到更小屏幕 / 缩放窗口后弹窗不会跑到看不见的地方。
    const restore = (g) => {
        const w = clamp(Math.round(g.width), GEOM_MIN_W, maxW());
        const h = clamp(Math.round(g.height), GEOM_MIN_H, maxH());
        el.style.width = w + 'px';
        el.style.height = h + 'px';
        // CSS 里 max-width: calc(100vw - 40px) 会二次压缩，按压缩后的结果夹取
        const realW = Math.min(w, window.innerWidth - GEOM_MARGIN);
        el.style.left = clamp(Math.round(g.left), 0, Math.max(0, window.innerWidth - realW)) + 'px';
        el.style.top = clamp(Math.round(g.top), 0, Math.max(0, window.innerHeight - h)) + 'px';
    };

    // 定位时机：必须用 show（而非 shown）。
    // shown.bs.modal 在 transition 结束后才触发，那时 dialog 已以左上角 (0,0)
    // 渲染过一帧，用户会看到「先闪到左上角、再瞬间跳到中间」。
    // 改为 show.bs.modal 后等一帧 —— 此时 Bootstrap 已完成 display:block，
    // dialog 参与布局可测量（visibility:hidden 不影响布局），定位完成即显形，
    // 用户看到的就是直接淡入到目标位置，无中间态。
    const setup = () => {
        $('#wiseAgentModal').off('show.bs.modal', setup);

        // 同步定位 + 显形：坐标由内联尺寸算出，不依赖布局，因此无中间态
        const saved = loadGeom();
        if (saved) restore(saved); else center();
        el.style.visibility = 'visible';

        // 拖拽/缩放等一帧（布局稳定后再挂，行为更可靠）
        requestAnimationFrame(() => {
            $dialog.draggable({
                handle: '.wise-agent-modal-header',
                stop: persistGeom,
            });

            $dialog.resizable({
                handles: 'se',
                minWidth: GEOM_MIN_W,
                minHeight: GEOM_MIN_H,
                maxWidth: () => window.innerWidth - GEOM_MARGIN,
                maxHeight: () => window.innerHeight - GEOM_MARGIN,
                stop: persistGeom,
            });
        });
    };

    $('#wiseAgentModal').on('show.bs.modal', setup);
    // 关闭兜底：jquery-ui 的 stop 未覆盖到的边界（如缩放后直接关闭）再补记一次
    $('#wiseAgentModal').on('hidden.bs.modal', () => {
        if (geomDirty) saveGeom(el);
    });
}

/** 打开弹窗（唯一入口形态） */
function openModal() {
    $('#wiseAgentModal').modal('show');
}

/** 新建会话 */
function reset() {
    newSession();
}

const agentChat = {
    init,
    openModal,
    // 兼容旧调用：抽屉形态已下线，统一的弹窗即当前唯一形态
    toggleDrawer: openModal,
    newSession: reset,
};

export { agentChat };
export default agentChat;
