/**
 * ai_execution_log.js — AI 执行日志（ESM 模块，B4-8）
 *
 *   - Controller.index()  工具级审计列表（ai_agent_logs.job_type = tool）
 *   - Controller.detail() 单次调用详情
 *
 * 与「任务中心」的分工：任务中心看一次任务的完整轨迹；本页看每一次工具调用
 * 的判决结果（是否被拦截、从哪个入口、耗时多久）。
 */
const $ = window.jQuery;
const { table } = window.Wise;

const baseUrl = '/admin/ai';

const STATUS_LABELS = { 2: '成功', 3: '失败' };
const STATUS_COLORS = { 2: 'success', 3: 'danger' };

const SOURCE_LABELS = { agent: 'Agent', mcp: 'MCP', http: 'HTTP', unknown: '未知' };

function esc(text) {
    return $('<div>').text(text === null || text === undefined ? '' : String(text)).html();
}

function toast(msg, type) {
    const ui = window.Wise && window.Wise.ui;
    if (!ui) {
        console.warn('[ai_execution_log]', msg);
        return;
    }
    ui.toast.show(msg, type === 'danger' ? 'error' : (type || 'success'));
}

function statusBadge(status) {
    const s = Number(status);
    return '<span class="badge bg-' + (STATUS_COLORS[s] || 'secondary') + '">'
        + esc(STATUS_LABELS[s] || ('#' + s)) + '</span>';
}

function sourceBadge(source) {
    return '<span class="badge bg-body-secondary">' + esc(SOURCE_LABELS[source] || source || '—') + '</span>';
}

function openDetail(trackingId) {
    const ui = window.Wise && window.Wise.ui;
    if (!ui || !ui.dialog) {
        window.open('/admin/ai_execution_log/detail?tracking_id=' + encodeURIComponent(trackingId));
        return;
    }
    ui.dialog.open(
        '/admin/ai_execution_log/detail?tracking_id=' + encodeURIComponent(trackingId),
        '执行详情',
        { area: ['80%', '80%'] }
    );
}

// ==================== 列表页 ====================

function index() {
    const state = { status: '', source: '' };

    const list = table.createSync({
        url: baseUrl + '/execution-log/list',
        columns: [
            {
                field: 'tracking_id', title: '追踪号', width: 150,
                formatter: (v) => '<code title="' + esc(v) + '">' + esc(String(v).slice(0, 12)) + '…</code>'
            },
            { field: 'tool_name', title: '工具', width: 200 },
            { field: 'entry_source', title: '入口', width: 90, align: 'center', formatter: (v) => sourceBadge(v) },
            { field: 'status', title: '结果', width: 80, align: 'center', formatter: (v) => statusBadge(v) },
            {
                field: 'error', title: '原因', width: 180,
                formatter: (v) => v ? '<span class="text-danger small">' + esc(v) + '</span>' : '—'
            },
            { field: 'duration', title: '耗时(s)', width: 90, align: 'right' },
            {
                field: 'user_id', title: '用户', width: 110,
                formatter: (v, row) => esc((row.user_type || '') + ':' + (v || 0))
            },
            { field: 'create_time', title: '时间', width: 165, formatter: 'datetime' },
            {
                field: 'operate', title: '操作', width: 100, align: 'center',
                formatter: (v, row) => table.operateButtons([
                    { action: 'detail', title: '详情', icon: 'bi bi-eye', class: 'btn-outline-primary' }
                ], row)
            }
        ],
        query() {
            return { status: state.status, entry_source: state.source };
        },
        toolbarBtn: [
            {
                id: 'btn-failed', name: 'failed', title: '只看失败', icon: 'bi bi-x-circle',
                class: 'btn btn-sm btn-outline-danger mx-1',
                click() {
                    state.status = state.status === '3' ? '' : '3';
                    $('#btn-failed').toggleClass('active', state.status === '3');
                    table.refresh(list);
                }
            },
            {
                id: 'btn-mcp', name: 'mcp', title: '只看 MCP', icon: 'bi bi-plug',
                class: 'btn btn-sm btn-outline-secondary mx-1',
                click() {
                    state.source = state.source === 'mcp' ? '' : 'mcp';
                    $('#btn-mcp').toggleClass('active', state.source === 'mcp');
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
            }
        }
    });
}

// ==================== 详情页 ====================

function renderFacts(row) {
    const items = [
        ['追踪号', '<code>' + esc(row.tracking_id) + '</code>'],
        ['工具', esc(row.tool_name || '—')],
        ['能力标识', row.capability ? '<code>' + esc(row.capability) + '</code>' : '—'],
        ['调用入口', sourceBadge(row.entry_source)],
        ['结果', statusBadge(row.status)],
        ['失败原因', row.error ? '<span class="text-danger">' + esc(row.error) + '</span>' : '—'],
        ['耗时', (row.duration || 0) + ' s'],
        ['用户', esc((row.user_type || '') + ':' + (row.user_id || 0))],
        ['审批单', row.approval_id ? esc(row.approval_id) : '—'],
        ['参数指纹', row.arguments_digest ? '<code>' + esc(row.arguments_digest) + '</code>' : '（无参数）'],
        ['时间', esc(row.create_time || '—')]
    ];

    return '<table class="table table-sm mb-0"><tbody>'
        + items.map(([k, v]) => '<tr><td class="text-body-secondary" style="width:140px;">' + k + '</td><td>' + v + '</td></tr>').join('')
        + '</tbody></table>';
}

function detail() {
    const el = document.getElementById('log-detail');
    const trackingId = (el && el.dataset.trackingId) || '';

    if (!trackingId) {
        $('#detail-facts').html('<div class="text-body-secondary small">缺少 tracking_id</div>');
        return;
    }

    $.ajax({
        url: baseUrl + '/execution-log/get',
        data: { tracking_id: trackingId },
        dataType: 'json',
        success(res) {
            if (res.code !== 0 || !res.data) {
                $('#detail-facts').html('<div class="text-body-secondary small">记录不存在或无权查看</div>');
                return;
            }
            const row = res.data;
            $('#detail-facts').html(renderFacts(row));
            $('#detail-result').text(row.result || '（无返回内容）');
        },
        error() {
            $('#detail-facts').html('<div class="text-body-secondary small">网络请求失败</div>');
        }
    });
}

const Controller = { index, detail };

export default Controller;
