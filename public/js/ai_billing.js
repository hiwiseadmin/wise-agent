/**
 * ai_billing.js — AI 用量仪表盘（ESM 模块，C2-3 / 附录七 §5.4）
 *
 * 由 wise-bootstrap.js 按 wsconfig.js 动态加载，调用
 *   Controller.index() → GET /admin/ai/billing/summary
 *
 * 渲染三块：用量概览 + 覆盖率、近 30 天趋势（纯文本条形）、Top N（用户/模型/提供商）。
 * 依赖后台壳全局：jQuery（wise-bundle 注入）。
 */
const $ = window.jQuery;

'use strict';

const baseUrl = '/admin/ai/billing';

function esc(text) {
    return $('<div>').text(text === null || text === undefined ? '' : String(text)).html();
}

function fmtMoney(v) {
    const n = Number(v) || 0;
    return '¥' + n.toFixed(n % 1 === 0 ? 0 : 2);
}

function fmtNum(v) {
    const n = Number(v) || 0;
    return n.toLocaleString('zh-CN');
}

function empty(text) {
    return '<div class="text-body-secondary small py-3 text-center">' + esc(text) + '</div>';
}

// ==================== 概览 ====================

function renderOverview(d) {
    const coverage = d.total_calls > 0
        ? Math.max(0, 100 - Math.round((d.unpriced_calls / d.total_calls) * 100))
        : 0;

    const rows = [
        ['近 30 天调用', fmtNum(d.total_calls)],
        ['估算成本', fmtMoney(d.total_cost)],
        ['未定价调用', fmtNum(d.unpriced_calls)],
    ];

    let html = '<dl class="row mb-0 small">';
    rows.forEach(([k, v]) => {
        html += '<dt class="col-6 text-body-secondary">' + esc(k) + '</dt>';
        html += '<dd class="col-6 text-end fw-semibold">' + esc(v) + '</dd>';
    });
    html += '</dl>';

    html += '<div class="mt-2 mb-1 d-flex justify-content-between small">'
        + '<span class="text-body-secondary">定价覆盖率</span>'
        + '<span>' + coverage + '%</span></div>';
    html += '<div class="progress" style="height:6px;">'
        + '<div class="progress-bar bg-' + (coverage >= 80 ? 'success' : coverage >= 50 ? 'warning' : 'danger')
        + '" style="width:' + coverage + '%"></div></div>';

    return html;
}

// ==================== 趋势 ====================

function renderTrend(byDay) {
    if (!byDay || byDay.length === 0) {
        return empty('暂无用量数据（开启成本治理后按日汇总）');
    }

    const maxCost = Math.max.apply(null, byDay.map((r) => Number(r.cost) || 0));
    const maxCalls = Math.max.apply(null, byDay.map((r) => Number(r.calls) || 0));

    let html = '<div class="small">';
    byDay.forEach((r) => {
        const calls = Number(r.calls) || 0;
        const cost = Number(r.cost) || 0;
        const w = maxCalls > 0 ? Math.max(2, Math.round((calls / maxCalls) * 100)) : 0;

        html += '<div class="d-flex align-items-center mb-1">'
            + '<span class="text-body-secondary me-2" style="width:52px;flex:none;">' + esc(String(r.day).slice(5)) + '</span>'
            + '<div class="flex-grow-1 bg-body-secondary rounded" style="height:14px;">'
            + '<div class="bg-info rounded" style="height:14px;width:' + w + '%;"></div></div>'
            + '<span class="ms-2 text-end" style="width:120px;flex:none;">' + fmtNum(calls) + ' 次 · ' + fmtMoney(cost) + '</span>'
            + '</div>';
    });
    html += '</div>';

    if (maxCost === 0 && maxCalls === 0) {
        html += '<div class="text-body-secondary small mt-2">当前均为 0 值（未开启成本估算或无调用）</div>';
    }

    return html;
}

// ==================== Top N ====================

function renderTop(list, emptyText) {
    if (!list || list.length === 0) {
        return empty(emptyText);
    }

    let html = '<ul class="list-group list-group-flush small">';
    list.forEach((r) => {
        const calls = Number(r.calls) || 0;
        const cost = Number(r.cost) || 0;
        html += '<li class="list-group-item d-flex justify-content-between align-items-center px-0">'
            + '<span class="text-truncate me-2" title="' + esc(r.key) + '">' + esc(r.key) + '</span>'
            + '<span class="text-body-secondary text-end flex-shrink-0">' + fmtNum(calls) + ' 次 · ' + fmtMoney(cost) + '</span>'
            + '</li>';
    });
    html += '</ul>';
    return html;
}

// ==================== 入口 ====================

function index() {
    $.ajax({
        url: baseUrl + '/summary',
        dataType: 'json',
        success(res) {
            if (res.code !== 0 || !res.data) {
                $('#billing-declaration').html(
                    '<i class="bi bi-info-circle"></i> ' + esc(res.msg || '加载失败')
                ).removeClass('alert-secondary').addClass('alert-danger');
                return;
            }

            const d = res.data;

            $('#billing-declaration').html('<i class="bi bi-exclamation-triangle"></i> ' + esc(d.estimate_declaration || ''));
            $('#billing-overview').html(renderOverview(d));
            $('#billing-trend').html(renderTrend(d.by_day));
            $('#billing-top-users').html(renderTop(d.top_users, '暂无用户维度数据'));
            $('#billing-top-models').html(renderTop(d.top_models, '暂无模型维度数据'));
            $('#billing-top-providers').html(renderTop(d.top_providers, '暂无提供商维度数据'));
        },
        error() {
            $('#billing-declaration').html('<i class="bi bi-x-circle"></i> 网络请求失败')
                .removeClass('alert-secondary').addClass('alert-danger');
        }
    });
}

const Controller = { index };

export default Controller;
