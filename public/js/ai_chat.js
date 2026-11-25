/**
 * ai_chat.js — AI 对话页（ESM 模块，重构自 AiController::chatPage 的静态 HTML）
 *
 * 由 wise-bootstrap.js 按 wsconfig.js 动态加载并调用 Controller.index()。
 * 数据走 admin 组 AiController 的 REST API（/admin/ai/*）。
 * 依赖后台壳全局：jQuery、Wise.ui、Wise.util（wise-bundle 注入）。
 */
const $ = window.jQuery;

/**
 * 统一 UI 门面（UIS v1.1 §4）：iframe 内取父窗口的 Wise.ui，弹层才落在可视层。
 * 惰性解析 —— 本脚本可能早于 wise-bundle 执行；业务侧禁止直调 layer.*。
 */
function wsUi() {
    let win = window;
    try {
        if (window.parent && window.parent !== window && window.parent.Wise && window.parent.Wise.ui) {
            win = window.parent;
        }
    } catch (e) { /* 跨域：用自身 */ }
    return (win.Wise && win.Wise.ui) || null;
}

function wsDialog() {
    const ui = wsUi();
    return (ui && ui.dialog) || null;
}

'use strict';

const API = '/admin/ai';

let currentSessionId = '';
let isStreaming = false;
let stopFlag = false;

function toast(msg, type) {
    const ui = wsUi();
    if (!ui) {
        console.warn('[ai_chat] Wise.ui 未就绪，提示未展示：', msg);
        return;
    }
    ui.toast.show(msg, type === 'danger' ? 'error' : (type || 'success'));
}

function esc(text) {
    return $('<div>').text(text === null || text === undefined ? '' : String(text)).html();
}

function truncate(s, len) {
    if (!s) return '';
    return s.length > len ? s.substring(0, len) + '…' : s;
}

function markdownRender(content) {
    const md = window.WsMarkdown;
    return md ? md.render(content) : esc(content).replace(/\n/g, '<br>');
}

// ==================== Provider ====================

function loadProviders() {
    $.get(API + '/providers', function (res) {
        if (res.code === 0) {
            const list = res.data || [];
            const html = list.map((p) => '<option value="' + esc(p.name) + '">' + esc(p.label || p.name) + '</option>').join('');
            $('#select-provider').html(html || '<option value="">暂无可用提供商</option>');
        }
    }).fail(function () {
        $('#select-provider').html('<option value="">提供商加载失败</option>');
    });
}

// ==================== 会话 ====================

function loadSessions() {
    $.get(API + '/sessions', { page: 1, limit: 50 }, function (res) {
        if (res.code === 0) {
            renderSessions(res.data.list || []);
            checkInterruptedSessions(res.data.list || []);
        } else {
            $('#sessions-list').html('<div class="text-danger p-2 small">加载失败</div>');
        }
    }).fail(function () {
        $('#sessions-list').html('<div class="text-danger p-2 small">请求失败</div>');
    });
}

function renderSessions(list) {
    if (!list.length) {
        $('#sessions-list').html('<div class="text-center text-body-secondary pt-4 small">暂无对话<br>点击 + 新建</div>');
        return;
    }
    const html = list.map(function (s) {
        const active = s.session_id === currentSessionId ? ' active' : '';
        const time = s.update_time ? s.update_time.substring(5, 16) : '';
        return '<div class="ai-chat-session-item' + active + '" data-id="' + esc(s.session_id) + '">'
            + '<div class="ai-chat-session-title">' + esc(s.title || '新对话') + '</div>'
            + '<div class="ai-chat-session-meta">' + esc(time) + ' · ' + (s.message_count || 0) + ' 条</div>'
            + '</div>';
    }).join('');
    $('#sessions-list').html(html);
}

function checkInterruptedSessions(list) {
    list.forEach(function (s) {
        $.get(API + '/session/interrupted-status', { session_id: s.session_id }, function (res) {
            if (res.code === 0 && res.data && res.data.interrupted) {
                $('.ai-chat-session-item[data-id="' + s.session_id + '"]').addClass('interrupted');
            }
        });
    });
}

function loadSessionMessages(sessionId) {
    $.get(API + '/session/detail', { session_id: sessionId }, function (res) {
        if (res.code === 0 && res.data && res.data.messages) {
            renderHistory(res.data.messages);
        } else {
            $('#chat-messages').html('<div class="text-center text-body-secondary pt-5"><p>暂无消息</p></div>');
        }
    }).fail(function () {
        $('#chat-messages').html('<div class="text-center text-body-secondary pt-5"><p>加载失败</p></div>');
    });
}

function renderHistory(messages) {
    let html = '';
    messages.forEach(function (m) {
        const role = m.role || 'user';
        const content = m.content || '';
        if (role === 'system') return;
        if (role === 'user') {
            html += userBubble(esc(content).replace(/\n/g, '<br>'));
        } else {
            html += aiBubble(markdownRender(content));
        }
    });
    $('#chat-messages').html(html || '<div class="text-center text-body-secondary pt-5"><p>暂无消息</p></div>');
    scrollToBottom();
}

function checkInterrupted(sessionId) {
    $('#resume-banner').hide();
    $.get(API + '/session/interrupted-status', { session_id: sessionId }, function (res) {
        if (res.code === 0 && res.data && res.data.interrupted) {
            $('#resume-reason').text(res.data.reason || '任务可能已中断');
            $('#resume-banner').show();
        }
    });
}

// ==================== 工具 / 工作流节点 ====================

function nsBadgeClass(ns) {
    if (ns === 'builtin') return 'text-bg-primary';
    if (ns.indexOf('plugin:') === 0) return 'text-bg-success';
    if (ns.indexOf('composer:') === 0) return 'text-bg-info';
    return 'text-bg-secondary';
}

function loadTools() {
    $.get(API + '/tools', function (res) {
        if (res.code === 0) {
            renderTools(res.data.groups || []);
        } else {
            $('#tools-list').html('<div class="text-danger p-2 small">加载失败</div>');
        }
    }).fail(function () {
        $('#tools-list').html('<div class="text-danger p-2 small">请求失败</div>');
    });
}

function renderTools(groups) {
    if (!groups.length) {
        $('#tools-list').html('<div class="text-center text-body-secondary pt-4 small">暂无可用工具</div>');
        return;
    }
    let html = '';
    groups.forEach(function (g) {
        html += '<div class="ai-chat-tool-group"><div class="ai-chat-tool-group-header">'
            + '<span><i class="bi bi-stack"></i> ' + esc(g.label) + '</span>'
            + '<span class="badge ' + nsBadgeClass(g.namespace) + '">' + esc(g.namespace) + '</span></div>';
        (g.tools || []).forEach(function (t) {
            html += '<div class="ai-chat-tool-item" data-tool-name="' + esc(t.full_name) + '" title="' + esc(t.description || '') + '">'
                + '<span class="ai-chat-tool-icon"><i class="bi bi-wrench"></i></span>'
                + '<div><div class="ai-chat-tool-name">' + esc(t.name) + '</div>'
                + '<div class="ai-chat-tool-desc">' + esc(truncate(t.description, 40)) + '</div></div></div>';
        });
        html += '</div>';
    });
    $('#tools-list').html(html);
}

function loadWorkflowNodes() {
    $.get(API + '/workflow-nodes', function (res) {
        if (res.code === 0) {
            renderWorkflowNodes(res.data.groups || []);
        } else {
            $('#workflow-nodes-list').html('<div class="text-danger p-2 small">加载失败</div>');
        }
    }).fail(function () {
        $('#workflow-nodes-list').html('<div class="text-danger p-2 small">请求失败</div>');
    });
}

function renderWorkflowNodes(groups) {
    if (!groups.length) {
        $('#workflow-nodes-list').html('<div class="text-center text-body-secondary pt-4 small">暂无可用节点</div>');
        return;
    }
    let html = '';
    groups.forEach(function (g) {
        html += '<div class="ai-chat-tool-group"><div class="ai-chat-tool-group-header"><i class="bi bi-folder"></i> ' + esc(g.label) + '</div>';
        (g.nodes || []).forEach(function (n) {
            html += '<div class="ai-chat-node-item" draggable="true" data-node-type="' + esc(n.type) + '" title="' + esc(n.description || '') + '">'
                + '<span class="ai-chat-node-icon"><i class="bi bi-cube"></i></span> '
                + '<span class="ai-chat-tool-name">' + esc(n.label) + '</span>'
                + '<div class="ai-chat-tool-desc">' + esc(truncate(n.description, 35)) + '</div></div>';
        });
        html += '</div>';
    });
    $('#workflow-nodes-list').html(html);
}

// ==================== 消息气泡 ====================

function userBubble(contentHtml) {
    return '<div class="ai-chat-msg user"><div class="ai-chat-avatar"><i class="bi bi-person"></i></div>'
        + '<div class="ai-chat-msg-body"><div class="ai-chat-bubble">' + contentHtml + '</div></div></div>';
}

function aiBubble(contentHtml, toolCallsHtml) {
    return '<div class="ai-chat-msg ai"><div class="ai-chat-avatar"><i class="bi bi-robot"></i></div>'
        + '<div class="ai-chat-msg-body"><div class="ai-chat-bubble">' + contentHtml + '</div>'
        + (toolCallsHtml || '') + '</div></div>';
}

function typingHtml() {
    return '<div class="ai-chat-typing"><span></span><span></span><span></span></div>';
}

function toolCallHtml(toolName) {
    return '<div class="ai-chat-tool-call"><i class="bi bi-wrench"></i> 调用工具: '
        + '<span class="badge text-bg-warning">' + esc(toolName) + '</span></div>';
}

function scrollToBottom() {
    const el = $('#chat-messages')[0];
    if (el) el.scrollTop = el.scrollHeight;
}

// ==================== 发送 / 恢复（SSE） ====================

function sendMessage() {
    const msg = $.trim($('#input-message').val());
    if (!msg || isStreaming) return;

    $('#input-message').val('');
    $('#chat-messages').append(userBubble(esc(msg).replace(/\n/g, '<br>')));
    scrollToBottom();

    const $bubble = $('<div class="ai-chat-msg ai"><div class="ai-chat-avatar"><i class="bi bi-robot"></i></div>'
        + '<div class="ai-chat-msg-body"><div class="ai-chat-bubble">' + typingHtml() + '</div></div></div>');
    $('#chat-messages').append($bubble);
    scrollToBottom();

    startStream('/chat/stream', {
        message: msg,
        session_id: currentSessionId,
        provider: $('#select-provider').val()
    }, $bubble, false);
}

function resumeChat() {
    if (!currentSessionId || isStreaming) return;
    $('#resume-banner').hide();

    const $bubble = $('<div class="ai-chat-msg ai"><div class="ai-chat-avatar"><i class="bi bi-robot"></i></div>'
        + '<div class="ai-chat-msg-body"><div class="ai-chat-bubble">' + typingHtml() + '</div></div></div>');
    $('#chat-messages').append($bubble);
    scrollToBottom();

    startStream('/chat/resume', { session_id: currentSessionId }, $bubble, true);
}

/**
 * 统一 SSE 流式处理：POST 后逐块读取，累积 token 渲染（闭包内累积，避免旧实现的「只渲染最后 token」问题）。
 */
function startStream(url, payload, $bubble, reloadHistoryOnDone) {
    isStreaming = true;
    stopFlag = false;
    $('#btn-stop').show();

    fetch(API + url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify(payload)
    }).then(function (response) {
        if (!response.ok) throw new Error('status=' + response.status);
        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';
        let aiContent = '';
        let toolCallsHtml = '';

        function read() {
            reader.read().then(function (result) {
                if (result.done) {
                    finish(reloadHistoryOnDone);
                    return;
                }
                buffer += decoder.decode(result.value, { stream: true });
                const parts = buffer.split('\n');
                buffer = parts.pop();

                parts.forEach(function (line) {
                    if (line.indexOf('data:') !== 0) return;
                    const dataStr = line.substring(5).trim();
                    if (dataStr === '[DONE]') return;
                    let data;
                    try { data = JSON.parse(dataStr); } catch (e) { return; }

                    if (data.type === 'token' && data.content) {
                        aiContent += data.content;
                        $bubble.find('.ai-chat-bubble').html(markdownRender(aiContent));
                        scrollToBottom();
                    } else if (data.type === 'tool_call' && data.tool_name) {
                        toolCallsHtml += toolCallHtml(data.tool_name);
                        $bubble.find('.ai-chat-bubble').html(markdownRender(aiContent));
                        $bubble.find('.ai-chat-msg-body').append(toolCallsHtml);
                    } else if (data.type === 'error') {
                        $bubble.find('.ai-chat-bubble').html('<span class="text-danger">' + esc(data.message || '发生错误') + '</span>');
                        finish(false);
                    } else if (data.type === 'done') {
                        finish(reloadHistoryOnDone);
                    }
                });

                if (!stopFlag && isStreaming) read();
                else finish(false);
            }).catch(function () {
                finish(false);
            });
        }

        function finish(reload) {
            if (!isStreaming) return;
            isStreaming = false;
            $('#btn-stop').hide();
            if (reload && currentSessionId) {
                loadSessionMessages(currentSessionId);
            } else {
                loadSessions();
            }
        }

        read();
    }).catch(function (err) {
        isStreaming = false;
        $('#btn-stop').hide();
        $bubble.find('.ai-chat-bubble').html('<span class="text-danger">请求失败: ' + esc(err.message || '') + '</span>');
    });
}

// ==================== 事件绑定 ====================

function bindEvents() {
    $('#btn-send').on('click', sendMessage);
    $('#input-message').on('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });

    $('#btn-new-session').on('click', function () {
        $.post(API + '/session/create', {}, function (res) {
            if (res.code === 0) {
                currentSessionId = res.data.session_id;
                $('#chat-messages').html('<div class="text-center text-body-secondary pt-5"><p>开始新的对话吧</p></div>');
                loadSessions();
            }
        });
    });

    $(document).on('click', '.ai-chat-session-item', function () {
        const id = $(this).data('id');
        if (id === currentSessionId) return;
        currentSessionId = id;
        $('.ai-chat-session-item').removeClass('active');
        $(this).addClass('active');
        loadSessionMessages(id);
        checkInterrupted(id);
    });

    $(document).on('click', '.ai-chat-tool-item', function () {
        const toolName = $(this).data('tool-name');
        const $input = $('#input-message');
        $input.val($input.val() + '[调用工具: ' + toolName + ']');
        $input.trigger('focus');
    });

    $('#btn-stop').on('click', function () {
        stopFlag = true;
        if (currentSessionId) {
            $.post(API + '/chat/stop', { session_id: currentSessionId });
        }
    });

    $('#btn-clear-session').on('click', function () {
        if (!currentSessionId) return;
        const dialog = wsDialog();
        if (!dialog) return;
        dialog.confirm('确定清空当前对话？', { title: '清空对话' }).then(function (ok) {
            if (!ok) return;
            $.post(API + '/session/clear', { session_id: currentSessionId }, function (res) {
                if (res.code === 0) {
                    $('#chat-messages').html('<div class="text-center text-body-secondary pt-5"><p>对话已清空，继续聊天吧</p></div>');
                }
            });
        });
    });

    $('#btn-resume').on('click', resumeChat);
}

const Controller = {
    index() {
        loadProviders();
        loadSessions();
        loadTools();
        loadWorkflowNodes();
        bindEvents();
    }
};

export default Controller;
