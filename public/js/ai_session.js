/**
 * ai_session.js — AI 会话管理（ESM 模块，B4-8）
 *
 *   - Controller.index()  会话列表（按 session_id 聚合）
 *   - Controller.detail() 会话消息明细
 *
 * 数据接口走 admin 组的 SessionController（session/list、session/get、session/delete），
 * 权限在控制器层校验（admin.ai_session.view / .delete）。
 */
const $ = window.jQuery;
const { table } = window.Wise;

const baseUrl = '/admin/ai';

function esc(text) {
    return $('<div>').text(text === null || text === undefined ? '' : String(text)).html();
}

function toast(msg, type) {
    const ui = window.Wise && window.Wise.ui;
    if (!ui) {
        console.warn('[ai_session]', msg);
        return;
    }
    ui.toast.show(msg, type === 'danger' ? 'error' : (type || 'success'));
}

function openDetail(sessionId) {
    const ui = window.Wise && window.Wise.ui;
    if (!ui || !ui.dialog) {
        window.open('/admin/ai_session/detail?session_id=' + encodeURIComponent(sessionId));
        return;
    }
    ui.dialog.open('/admin/ai_session/detail?session_id=' + encodeURIComponent(sessionId), '会话详情', { area: ['80%', '85%'] });
}

function confirmThen(message, onOk) {
    const Layer = window.Layer;
    if (Layer && typeof Layer.confirm === 'function') {
        Layer.confirm(message, { title: '确认操作', icon: 3 }, (index) => {
            Layer.close && Layer.close(index);
            onOk();
        });
        return;
    }
    if (window.confirm(message)) {
        onOk();
    }
}

// ==================== 列表页 ====================

function index() {
    const list = table.createSync({
        url: baseUrl + '/session/list',
        columns: [
            {
                field: 'session_id', title: '会话 ID', width: 260,
                formatter: (v) => '<code>' + esc(v) + '</code>'
            },
            { field: 'message_count', title: '消息数', width: 90, align: 'right' },
            { field: 'started_at', title: '开始时间', width: 170 },
            { field: 'last_active', title: '最后活动', width: 170 },
            {
                field: 'operate', title: '操作', width: 200, align: 'center',
                formatter: (v, row) => table.operateButtons([
                    { action: 'detail', title: '查看消息', icon: 'bi bi-eye', class: 'btn-outline-primary' },
                    { action: 'delete', title: '删除', icon: 'bi bi-trash', class: 'btn-outline-danger' }
                ], row)
            }
        ],
        toolbarBtn: [
            {
                id: 'btn-refresh', name: 'refresh', title: '刷新', icon: 'bi bi-arrow-repeat',
                class: 'btn btn-sm btn-info mx-1', click: () => table.refresh(list)
            }
        ],
        onClick(action, row) {
            if (action === 'detail') {
                openDetail(row.session_id);
                return;
            }

            if (action === 'delete') {
                confirmThen('确定删除会话 ' + row.session_id + ' 的全部消息？此操作不可恢复。', () => {
                    $.ajax({
                        url: baseUrl + '/session/delete',
                        type: 'post',
                        data: { session_id: row.session_id },
                        dataType: 'json',
                        success(res) {
                            if (res.code === 0) {
                                toast('已删除');
                                table.refresh(list);
                            } else {
                                toast(res.msg || '删除失败', 'danger');
                            }
                        },
                        error() {
                            toast('网络错误', 'danger');
                        }
                    });
                });
            }
        }
    });
}

// ==================== 详情页 ====================

const ROLE_STYLES = {
    user: 'list-group-item',
    assistant: 'list-group-item bg-body-tertiary',
    system: 'list-group-item text-body-secondary',
    tool: 'list-group-item small text-body-secondary'
};

const ROLE_LABELS = { user: '用户', assistant: '助手', system: '系统', tool: '工具' };

function renderMeta(sessionId, messages) {
    return '<div class="small">'
        + '<div>会话 ID：<code>' + esc(sessionId) + '</code></div>'
        + '<div>消息数：' + messages.length + '</div>'
        + '</div>';
}

function renderMessages(messages) {
    if (!messages.length) {
        return '<div class="list-group-item text-body-secondary">该会话没有消息</div>';
    }

    return messages.map((m) => {
        const role = m.role || 'unknown';
        const cls = ROLE_STYLES[role] || 'list-group-item';
        const content = m.content ? esc(m.content) : '<span class="text-body-secondary">（空）</span>';
        const meta = [];
        if (m.create_time) meta.push(esc(m.create_time));
        if (m.tool_name) meta.push('工具：' + esc(m.tool_name));

        return '<div class="' + cls + '">'
            + '<div class="d-flex justify-content-between">'
            + '<span class="badge bg-body-secondary mb-1">' + esc(ROLE_LABELS[role] || role) + '</span>'
            + '<span class="text-body-secondary small">' + meta.join(' · ') + '</span>'
            + '</div>'
            + '<div class="small" style="white-space: pre-wrap; word-break: break-word;">' + content + '</div>'
            + '</div>';
    }).join('');
}

function detail() {
    const el = document.getElementById('session-detail');
    const sessionId = (el && el.dataset.sessionId) || '';

    if (!sessionId) {
        $('#detail-meta').html('<div class="text-body-secondary small">缺少 session_id</div>');
        return;
    }

    $.ajax({
        url: baseUrl + '/session/get',
        data: { session_id: sessionId },
        dataType: 'json',
        success(res) {
            if (res.code !== 0 || !res.data) {
                $('#detail-meta').html('<div class="text-body-secondary small">会话不存在或无权查看</div>');
                return;
            }
            const messages = res.data.messages || [];
            $('#detail-meta').html(renderMeta(res.data.session_id || sessionId, messages));
            $('#detail-messages').html(renderMessages(messages));
        },
        error() {
            $('#detail-meta').html('<div class="text-body-secondary small">网络请求失败</div>');
        }
    });
}

const Controller = { index, detail };

export default Controller;
