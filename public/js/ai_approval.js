/**
 * ai_approval.js — AI 能力审批中心（ESM 模块）
 *
 * 由 wise-bootstrap.js 按 wsconfig.js 动态加载并调用 Controller.index()。
 * 依赖后台壳提供的全局：jQuery、Layer、Wise.util（wise-bundle 注入）。
 *
 * 放行语义：批准后不重放历史调用，申请人需重新发起同一调用，
 * 后端校验时命中可用审批单并原子消费一次。
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

/** layer 的 type 词汇 → Wise.ui.toast 的 type 词汇 */
function toastType(type) {
    if (!type) return 'success';
    return type === 'danger' ? 'error' : type;
}

'use strict';

var baseUrl = '/admin/ai_approval';

var state = { page: 1, limit: 15, total: 0, status: '' };

var STATUS_COLORS = {
    pending:   'warning',
    approved:  'success',
    consumed:  'secondary',
    rejected:  'danger',
    expired:   'secondary',
    cancelled: 'secondary'
};

var STATUS_LABELS = {
    pending:   '待审批',
    approved:  '已批准',
    consumed:  '已用尽',
    rejected:  '已驳回',
    cancelled: '已撤回',
    expired:   '已失效'
};

function toast(msg, type) {
    const ui = wsUi();
    if (!ui) {
        console.warn('[ai_approval] Wise.ui 未就绪，提示未展示：', msg);
        return;
    }
    ui.toast.show(msg, toastType(type));
}

function esc(text) {
    return $('<div>').text(text === null || text === undefined ? '' : String(text)).html();
}

function get(url, params, cb) {
    $.ajax({
        url: url,
        data: params || {},
        dataType: 'json',
        success(res) {
            if (res.code === 0) {
                cb(res);
            } else {
                toast(res.msg || '请求失败', 'danger');
            }
        },
        error() {
            toast('网络请求失败', 'danger');
        }
    });
}

function post(url, data, cb) {
    Wise.util.http.post(url, data, { interceptor: false }).then(function (res) {
        if (res.code === 0) {
            cb(res);
        } else {
            toast(res.msg || '操作失败', 'danger');
        }
    }).catch(function () {});
}

// ==================== 统计卡片 ====================

function loadStats() {
    get(baseUrl + '/stat', {}, function (res) {
        var data = res.data || {};
        var html = '';
        $.each(Object.keys(data), function (i, status) {
            var active = state.status === status ? ' active' : '';
            html += '<div class="col-6 col-md-2">';
            html += '<div class="approval-stat' + active + '" data-status="' + status + '">';
            html += '<div class="stat-num text-' + (STATUS_COLORS[status] || 'body') + '">' + data[status] + '</div>';
            html += '<div class="stat-label">' + (STATUS_LABELS[status] || status) + '</div>';
            html += '</div></div>';
        });
        $('#approval-stats').html(html);
    });
}

// ==================== 列表 ====================

function currentFilter() {
    return {
        status:  state.status || $('#filter-status').val() || '',
        kind:    $('#filter-kind').val() || '',
        access:  $('#filter-access').val() || '',
        risk:    $('#filter-risk').val() || '',
        keyword: $('#filter-keyword').val() || '',
        page:    state.page,
        limit:   state.limit
    };
}

function renderRows(rows) {
    if (!rows || !rows.length) {
        $('#approval-body').html('<tr><td colspan="6" class="text-center text-body-secondary py-4">暂无数据</td></tr>');
        return;
    }

    var html = '';
    $.each(rows, function (i, row) {
        var badge = STATUS_COLORS[row.status] || 'secondary';
        html += '<tr>';
        html += '<td><code>' + esc(row.ident) + '</code></td>';
        html += '<td><span class="badge bg-info">' + esc(row.kind_label) + '</span> '
            + esc(row.capability_name)
            + '<div class="small text-body-secondary">' + esc(row.permission) + '</div></td>';
        html += '<td>' + esc(row.requester_name) + '</td>';
        html += '<td class="small text-body-secondary">' + esc(row.create_time) + '</td>';
        html += '<td><span class="badge bg-' + badge + '">' + esc(row.status_label) + '</span></td>';
        html += '<td>';
        html += '<button class="btn btn-sm btn-outline-secondary btn-preview" data-id="' + row.id + '">详情</button> ';
        if (row.status === 'pending') {
            html += '<button class="btn btn-sm btn-success btn-approve" data-id="' + row.id + '" data-ident="' + esc(row.ident) + '">批准</button> ';
            html += '<button class="btn btn-sm btn-danger btn-reject" data-id="' + row.id + '" data-ident="' + esc(row.ident) + '">驳回</button>';
        } else if (row.status === 'approved') {
            html += '<button class="btn btn-sm btn-outline-danger btn-cancel" data-id="' + row.id + '" data-ident="' + esc(row.ident) + '">撤回</button>';
        }
        html += '</td>';
        html += '</tr>';
    });

    $('#approval-body').html(html);
}

function renderPager() {
    var pages = Math.max(1, Math.ceil(state.total / state.limit));
    var html = '<span class="text-body-secondary small">共 ' + state.total + ' 条，第 ' + state.page + '/' + pages + ' 页</span>';
    html += '<span>';
    html += '<button class="btn btn-sm btn-outline-secondary me-2 btn-page" data-page="' + (state.page - 1) + '"'
        + (state.page <= 1 ? ' disabled' : '') + '>上一页</button>';
    html += '<button class="btn btn-sm btn-outline-secondary btn-page" data-page="' + (state.page + 1) + '"'
        + (state.page >= pages ? ' disabled' : '') + '>下一页</button>';
    html += '</span>';
    $('#approval-pager').html(html);
}

function loadList() {
    $('#approval-body').html('<tr><td colspan="6" class="text-center text-body-secondary py-4">加载中…</td></tr>');

    get(baseUrl + '/getList', currentFilter(), function (res) {
        state.total = res.data.total || 0;
        renderRows(res.data.list || []);
        renderPager();
    });
}

function reload() {
    state.page = 1;
    loadStats();
    loadList();
}

// ==================== 决策 ====================

function decide(action, id, ident) {
    var label = action === 'approve' ? '批准' : (action === 'reject' ? '驳回' : '撤回');
    var dialog = wsDialog();

    if (!dialog) {
        toast('弹窗组件未加载，无法提交审批', 'error');
        return;
    }

    // 唯一入口（UIS v1.1 §4）：原「文本域 prompt 弹层」→ dialog.form()
    dialog.form({
        title: label + '审批单 ' + ident,
        size: 'md',
        submitText: label,
        successMsg: label + '成功',
        action: baseUrl + '/' + action,
        method: 'POST',
        html: '<input type="hidden" name="id" value="' + esc(String(id)) + '">'
            + '<div><label class="form-label" for="ws-approval-note">备注（可选）</label>'
            + '<textarea class="form-control" id="ws-approval-note" name="note" rows="3"'
            + ' placeholder="备注（可选）"></textarea></div>',
        onSuccess: function () {
            loadStats();
            loadList();
        }
    });
}

function preview(id) {
    const dialog = wsDialog();
    if (dialog) {
        dialog.open(baseUrl + '/preview?id=' + id, '审批单详情', { area: ['720px', '620px'] });
    }
}

// ==================== 事件绑定 ====================

function bindEvents() {
    $('#btn-refresh').click(reload);

    $('#btn-expire').click(function () {
        post(baseUrl + '/expire', {}, function (res) {
            toast(res.msg || '已清理');
            loadStats();
            loadList();
        });
    });

    $('#filter-status, #filter-kind, #filter-access, #filter-risk').on('change', function () {
        state.status = $('#filter-status').val() || '';
        state.page = 1;
        loadStats();
        loadList();
    });

    var timer;
    $('#filter-keyword').on('input', function () {
        clearTimeout(timer);
        timer = setTimeout(function () {
            state.page = 1;
            loadList();
        }, 300);
    });

    // 搜索区（.ws-filter-bar 契约：显式查询 / 重置，表单绝不整页提交）
    function reloadFiltered() {
        state.page = 1;
        loadStats();
        loadList();
    }

    $('#ws-filter').on('submit', function (e) {
        e.preventDefault();
        reloadFiltered();
    });

    $('#ws-filter .ws-filter-submit').on('click', reloadFiltered);

    $('#ws-filter .ws-filter-reset').on('click', function () {
        state.status = '';
        $('#filter-status, #filter-kind, #filter-access, #filter-risk').val('');
        $('#filter-keyword').val('');
        reloadFiltered();
    });

    // 统计卡片：点击按状态筛选
    $(document).on('click', '.approval-stat', function () {
        var status = $(this).data('status') || '';
        state.status = state.status === status ? '' : status;
        $('#filter-status').val(state.status);
        state.page = 1;
        loadStats();
        loadList();
    });

    $(document).on('click', '.btn-page', function () {
        var page = parseInt($(this).data('page'), 10);
        if (page < 1) { return; }
        state.page = page;
        loadList();
    });

    $(document).on('click', '.btn-preview', function () {
        preview($(this).data('id'));
    });

    $(document).on('click', '.btn-approve', function () {
        decide('approve', $(this).data('id'), $(this).data('ident'));
    });

    $(document).on('click', '.btn-reject', function () {
        decide('reject', $(this).data('id'), $(this).data('ident'));
    });

    $(document).on('click', '.btn-cancel', function () {
        decide('cancel', $(this).data('id'), $(this).data('ident'));
    });
}

const Controller = {
    index() {
        bindEvents();
        loadStats();
        loadList();
    }
};

export default Controller;
