/**
 * ai_task.js — AI 任务中心（ESM 模块，C1-3）
 *
 * 由 wise-bootstrap.js 按 wsconfig.js 动态加载，分别调用：
 *   - Controller.index()  列表页（Wise.table）
 *   - Controller.detail() 详情页（事实层 + 三附证据面板）
 *
 * 数据接口走 admin 组的 TaskController（task/list、task/get、task/stat）。
 * 依赖后台壳全局：jQuery、Layer、Wise.table（wise-bundle 注入）。
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
const { table } = window.Wise;

'use strict';

const baseUrl = '/admin/ai';

const STATUS_LABELS = {
    0: '排队中', 1: '运行中', 2: '已完成', 3: '失败', 4: '超时', 5: '已取消'
};

const STATUS_COLORS = {
    0: 'secondary', 1: 'info', 2: 'success', 3: 'danger', 4: 'warning', 5: 'secondary'
};

const JOB_LABELS = { agent: '对话', pipeline: '流水线', plan: '计划', skill: '技能' };

function esc(text) {
    return $('<div>').text(text === null || text === undefined ? '' : String(text)).html();
}

function statusBadge(status) {
    const s = Number(status);
    return '<span class="badge bg-' + (STATUS_COLORS[s] || 'secondary') + '">'
        + esc(STATUS_LABELS[s] || ('#' + s)) + '</span>';
}

function toast(msg, type) {
    const ui = wsUi();
    if (!ui) {
        console.warn('[ai_task] Wise.ui 未就绪，提示未展示：', msg);
        return;
    }
    ui.toast.show(msg, type === 'danger' ? 'error' : (type || 'success'));
}

// ==================== 列表页 ====================

function renderSummary(res) {
    // 响应透出：可在此渲染统计条（当前列表页暂不显示）
}

function copyRetryCommand(task) {
    const cmd = 'php think wise:agent:chat "' + (task || '').replace(/"/g, '\\"') + '"';
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(cmd).then(
            () => toast('重跑命令已复制：' + cmd),
            () => toast('复制失败，请手动复制：' + cmd, 'warning')
        );
    } else {
        // 无剪贴板能力时把命令展示出来供手动复制（唯一入口：dialog.alert）
        const dialog = wsDialog();
        if (dialog) {
            dialog.alert(cmd, { title: '重跑命令（复制后经审批执行）' });
        } else {
            toast('重跑命令：' + cmd, 'info');
        }
    }
}

function openDetail(trackingId) {
    const dialog = wsDialog();
    if (dialog) {
        dialog.open(
            '/admin/ai_task/detail?tracking_id=' + encodeURIComponent(trackingId),
            '任务详情',
            { area: ['820px', '620px'] }
        );
    }
}

function index() {
    let onlyPending = false;

    const OPERATE = [
        { action: 'detail', title: '详情', icon: 'bi bi-eye', class: 'btn-outline-primary' },
        { action: 'retry', title: '复制重跑命令', icon: 'bi bi-terminal', class: 'btn-outline-secondary' }
    ];

    const list = table.createSync({
        url: baseUrl + '/task/list',
        columns: [
            {
                field: 'tracking_id', title: '任务单号', width: 150,
                formatter: (v) => '<code title="' + esc(v) + '">' + esc(String(v).slice(0, 12) + (String(v).length > 12 ? '…' : '')) + '</code>'
            },
            {
                field: 'job_type', title: '执行体', width: 90, align: 'center',
                formatter: (v) => '<span class="badge bg-body-secondary">' + esc(JOB_LABELS[v] || v) + '</span>'
            },
            { field: 'status', title: '状态', width: 90, align: 'center', formatter: (v) => statusBadge(v) },
            { field: 'task', title: '任务摘要' },
            {
                field: 'user_id', title: '用户', width: 110,
                formatter: (v, row) => esc((row.user_type || '') + ':' + (row.user_id || 0))
            },
            { field: 'duration', title: '耗时(s)', width: 90, align: 'right' },
            { field: 'total_tokens', title: 'Tokens', width: 90, align: 'right' },
            { field: 'create_time', title: '创建时间', width: 160, formatter: 'datetime' },
            {
                field: 'operate', title: '操作', width: 190, align: 'center',
                formatter: (v, row) => {
                    const buttons = OPERATE.filter(b => {
                        // 复制重跑命令仅 failed(3)/timeout(4) 显示
                        return b.action !== 'retry' || row.status === 3 || row.status === 4;
                    });
                    return table.operateButtons(buttons, row);
                }
            }
        ],
        query() {
            return { status: onlyPending ? '0,1,4' : '' };
        },
        toolbarBtn: [
            {
                id: 'btn-pending', name: 'pending', title: '只看待处理', icon: 'bi bi-funnel',
                class: 'btn btn-sm btn-outline-secondary mx-1',
                click: () => {
                    onlyPending = !onlyPending;
                    $('#btn-pending').toggleClass('active', onlyPending);
                    table.refresh(list);
                }
            },
            {
                id: 'btn-refresh', name: 'refresh', title: '刷新', icon: 'bi bi-arrow-repeat',
                class: 'btn btn-sm btn-info mx-1', click: () => table.refresh(list)
            }
        ],
        onClick(action, row) {
            if (action === 'detail') {
                openDetail(row.tracking_id);
            } else if (action === 'retry') {
                copyRetryCommand(row.task);
            }
        },
        onResponse(res) {
            renderSummary(res);
        }
    });
}

// ==================== 详情页 ====================

function renderFacts(task) {
    const rows = [
        ['任务单号', task.tracking_id, true],
        ['执行体', JOB_LABELS[task.job_type] || task.job_type],
        ['状态', statusBadge(task.status)],
        ['任务', task.task],
        ['错误', task.error || '—'],
        ['耗时', (task.duration || 0) + ' s'],
        ['Tokens', task.total_tokens || 0],
        ['Provider / Model', (task.provider || '—') + ' / ' + (task.model || '—')],
        ['创建时间', task.create_time || '—']
    ];

    let html = '<dl class="row mb-0 small">';
    rows.forEach(([k, v, code]) => {
        html += '<dt class="col-3 text-body-secondary">' + esc(k) + '</dt>';
        html += '<dd class="col-9">' + (code ? '<code>' + esc(v) + '</code>' : v) + '</dd>';
    });
    html += '</dl>';
    return html;
}

function renderPlan(plan) {
    if (!plan || !plan.available) {
        return '<div class="text-body-secondary small">无计划数据（非 plan 执行体或未关联）</div>';
    }
    const steps = (plan.steps || []).map(s =>
        '<li class="list-group-item py-1 small">步骤 ' + esc(s.step_no) + ' · ' + esc(s.status || '') + ' · ' + esc(s.label || s.target || '') + '</li>'
    ).join('');
    return '<div class="small mb-1">目标：' + esc(plan.run.goal || '—') + '</div>'
        + '<ul class="list-group list-group-flush">' + (steps || '<li class="list-group-item text-body-secondary">无步骤</li>') + '</ul>';
}

function renderClosure(closure) {
    if (!closure || !closure.available) {
        return '<div class="text-body-secondary small">无闭环数据（该任务未触发闭环事件）</div>';
    }
    const events = (closure.events || []).map(e =>
        '<li class="list-group-item py-1 small">'
        + '<span class="badge bg-body-secondary me-1">' + esc(e.scene || '') + '</span>'
        + esc(e.capability_name || '') + ' → ' + esc(e.result || '')
        + '<div class="text-body-secondary">trace: <code>' + esc(e.trace_id || '') + '</code></div>'
        + '</li>'
    ).join('');
    return '<ul class="list-group list-group-flush">' + (events || '<li class="list-group-item text-body-secondary">无事件</li>') + '</ul>';
}

function renderPipeline(pipeline) {
    if (!pipeline || !pipeline.available) {
        return '<div class="text-body-secondary small">无 pipeline 运行数据（弱关联，可能未命中）</div>';
    }
    const runs = (pipeline.runs || []).map(r =>
        '<li class="list-group-item py-1 small">' + esc(r.pipeline_name || '') + ' · ' + esc(r.status || '') + '</li>'
    ).join('');
    return '<ul class="list-group list-group-flush">' + (runs || '<li class="list-group-item text-body-secondary">无运行</li>') + '</ul>';
}

function detail() {
    const el = document.getElementById('task-detail');
    const trackingId = (el && el.dataset.trackingId) || '';
    if (!trackingId) {
        $('#detail-body').html('<div class="text-body-secondary p-4 text-center">缺少 tracking_id</div>');
        return;
    }

    $.ajax({
        url: baseUrl + '/task/get',
        data: { tracking_id: trackingId },
        dataType: 'json',
        success(res) {
            if (res.code !== 0 || !res.data || !res.data.task) {
                $('#detail-body').html('<div class="text-body-secondary p-4 text-center">任务不存在或无权查看</div>');
                return;
            }
            const d = res.data;
            $('#detail-facts').html(renderFacts(d.task));
            $('#detail-plan').html(renderPlan(d.plan));
            $('#detail-closure').html(renderClosure(d.closure));
            $('#detail-pipeline').html(renderPipeline(d.pipeline));
        },
        error() {
            $('#detail-body').html('<div class="text-body-secondary p-4 text-center">网络请求失败</div>');
        }
    });
}

const Controller = { index, detail };

export default Controller;
