/**
 * ai_config.js — AI 设置页（ESM 模块，重构自 AiController::configPage 的静态 HTML）
 *
 * 由 wise-bootstrap.js 按 wsconfig.js 动态加载并调用 Controller.index()。
 * 数据走 admin 组 AiController 的 REST API：
 *   - GET  /admin/ai/config      完整 Provider 配置（api_key 已脱敏）
 *   - POST /admin/ai/config/save 保存配置
 *   - POST /admin/ai/config/test 测试连接
 * 依赖后台壳全局：jQuery、Wise.ui（wise-bundle 注入）。
 */
const $ = window.jQuery;

/** 统一 UI 门面（UIS v1.1 §4）：iframe 内取父窗口的 Wise.ui，业务侧禁止直调 layer.* */
function wsUi() {
    let win = window;
    try {
        if (window.parent && window.parent !== window && window.parent.Wise && window.parent.Wise.ui) {
            win = window.parent;
        }
    } catch (e) { /* 跨域：用自身 */ }
    return (win.Wise && win.Wise.ui) || null;
}

'use strict';

const API = '/admin/ai';

let providerConfigs = [];

function toast(msg, type) {
    const ui = wsUi();
    if (!ui) {
        console.warn('[ai_config] Wise.ui 未就绪，提示未展示：', msg);
        return;
    }
    ui.toast.show(msg, type === 'danger' ? 'error' : (type || 'success'));
}

function esc(text) {
    return $('<div>').text(text === null || text === undefined ? '' : String(text)).html();
}

function providerIcon(name) {
    if (name === 'openai') return 'OA';
    if (name === 'deepseek') return 'DS';
    if (name === 'claude') return 'CL';
    return 'AI';
}

function providerColor(name) {
    if (name === 'openai') return '#10a37f';
    if (name === 'deepseek') return '#3875f6';
    if (name === 'claude') return '#d4a574';
    return '#6c757d';
}

function statusBadge(active) {
    return active
        ? '<span class="ai-config-status-dot active me-1"></span>已启用'
        : '<span class="ai-config-status-dot inactive me-1"></span>未启用';
}

// ==================== 提供商列表 ====================

function loadConfigs() {
    $.get(API + '/config', function (res) {
        if (res.code === 0) {
            providerConfigs = res.data || [];
            renderProviders(providerConfigs);
        } else {
            $('#providers-list').html('<div class="text-body-secondary p-4 text-center">' + esc(res.msg || '加载失败') + '</div>');
        }
    }).fail(function () {
        $('#providers-list').html('<div class="text-body-secondary p-4 text-center">请求失败</div>');
    });
}

function renderProviders(list) {
    if (!list.length) {
        $('#providers-list').html('<div class="ai-config-card"><div class="ai-config-card-body text-body-secondary text-center">暂无配置的提供商</div></div>');
        return;
    }

    const html = list.map(function (p) {
        return '<div class="ai-config-provider">'
            + '<div class="ai-config-provider-icon" style="background:' + providerColor(p.provider) + ';">' + providerIcon(p.provider) + '</div>'
            + '<div class="flex-grow-1">'
            + '<div style="font-weight:600;font-size:15px;">' + esc(p.provider) + '</div>'
            + '<div class="small text-body-secondary mt-1">' + statusBadge(p.is_active) + ' · 模型: ' + esc(p.model || '-') + '</div>'
            + '</div>'
            + '<button class="btn btn-sm btn-outline-primary ai-config-edit" data-provider="' + esc(p.provider) + '">'
            + '<i class="bi bi-pencil"></i> 配置</button>'
            + '</div>';
    }).join('');

    $('#providers-list').html('<div class="ai-config-card">' + html + '</div>');
}

// ==================== 编辑 / 保存 / 测试 ====================

function editProvider(name) {
    const p = providerConfigs.find((x) => x.provider === name);
    if (!p) {
        toast('提供商不存在', 'warning');
        return;
    }

    $('#form-title').text('配置: ' + (p.provider || name));
    $('#form-provider').val(name);
    // 安全考虑：不把完整 key 回填到输入框，仅展示脱敏值占位提示
    $('#form-api-key').val('');
    $('#form-api-key').attr('placeholder', p.api_key ? '已保存（' + p.api_key + '），留空则不修改' : 'sk-...');
    $('#form-api-base').val(p.api_base || '');
    $('#form-model').val(p.model || '');
    $('#form-max-tokens').val(p.max_tokens || 4096);
    $('#form-temperature').val(p.temperature || 0.7);
    $('#temp-val').text(p.temperature || 0.7);
    $('#form-is-active').prop('checked', !!p.is_active);
    $('#config-form-card').show();
}

function saveConfig() {
    const provider = $('#form-provider').val();
    const data = {
        provider: provider,
        api_key: $('#form-api-key').val(),
        api_base: $('#form-api-base').val(),
        model: $('#form-model').val(),
        max_tokens: parseInt($('#form-max-tokens').val(), 10) || 4096,
        temperature: parseFloat($('#form-temperature').val()) || 0.7,
        is_active: $('#form-is-active').is(':checked') ? 1 : 0
    };

    $.post(API + '/config/save', data, function (res) {
        if (res.code === 0) {
            toast('保存成功');
            $('#config-form-card').hide();
            loadConfigs();
        } else {
            toast(res.msg || '保存失败', 'danger');
        }
    }).fail(function () {
        toast('请求失败，请检查网络', 'danger');
    });
}

function testConnection() {
    const apiKey = $('#form-api-key').val();
    if (!apiKey) {
        toast('请先填写 API Key（测试连接需要完整密钥）', 'warning');
        return;
    }

    const $btn = $('#btn-test');
    $btn.prop('disabled', true).html('<i class="bi bi-arrow-repeat"></i> 测试中…');
    $('#test-result').hide();

    $.post(API + '/config/test', {
        provider: $('#form-provider').val(),
        api_key: apiKey,
        api_base: $('#form-api-base').val(),
        model: $('#form-model').val()
    }, function (res) {
        $btn.prop('disabled', false).html('<i class="bi bi-vial"></i> 测试连接');
        const $box = $('#test-result').show();
        if (res.code === 0) {
            $box.html('<div class="alert alert-success small mb-0"><i class="bi bi-check-circle"></i> 连接成功！模型: ' + esc((res.data && res.data.model) || '-') + '</div>');
        } else {
            $box.html('<div class="alert alert-danger small mb-0"><i class="bi bi-x-circle"></i> ' + esc(res.msg || '连接失败') + '</div>');
        }
    }).fail(function () {
        $btn.prop('disabled', false).html('<i class="bi bi-vial"></i> 测试连接');
        $('#test-result').show().html('<div class="alert alert-danger small mb-0">请求失败</div>');
    });
}

// ==================== 插件 AI 能力汇总 ====================

function loadPluginsSummary() {
    $.when(
        $.get(API + '/tools'),
        $.get(API + '/workflow-nodes'),
        $.get(API + '/adapters')
    ).done(function (rTools, rNodes, rAdapters) {
        let nTools = 0, nNodes = 0, nAdapters = 0;
        if (rTools[0] && rTools[0].code === 0) nTools = rTools[0].data.total || 0;
        if (rNodes[0] && rNodes[0].code === 0) nNodes = rNodes[0].data.total || 0;
        if (rAdapters[0] && rAdapters[0].code === 0) nAdapters = rAdapters[0].data.total || 0;

        let html = '<div class="row text-center">'
            + '<div class="col-4"><div style="font-size:24px;font-weight:700;color:var(--bs-primary);">' + nTools + '</div><div class="small text-body-secondary">工具 (Tools)</div></div>'
            + '<div class="col-4"><div style="font-size:24px;font-weight:700;color:var(--bs-success);">' + nNodes + '</div><div class="small text-body-secondary">工作流节点</div></div>'
            + '<div class="col-4"><div style="font-size:24px;font-weight:700;color:var(--bs-warning);">' + nAdapters + '</div><div class="small text-body-secondary">模型适配器</div></div>'
            + '</div>';
        if (nTools === 0 && nNodes === 0 && nAdapters === 0) {
            html += '<p class="text-body-secondary text-center mt-2 small">暂无插件提供 AI 能力。安装插件后此处会显示。</p>';
        }
        $('#plugins-ai-summary').html(html);
    }).fail(function () {
        $('#plugins-ai-summary').html('<p class="text-danger small">加载失败</p>');
    });
}

// ==================== 事件绑定 ====================

function bindEvents() {
    $('#btn-cancel').on('click', function () {
        $('#config-form-card').hide();
        $('#config-form')[0].reset();
    });

    $('#config-form').on('submit', function (e) {
        e.preventDefault();
        saveConfig();
    });

    $('#btn-test').on('click', testConnection);

    $('#form-temperature').on('input', function () {
        $('#temp-val').text(this.value);
    });

    $(document).on('click', '.ai-config-edit', function () {
        editProvider($(this).data('provider'));
    });
}

const Controller = {
    index() {
        loadConfigs();
        loadPluginsSummary();
        bindEvents();
    }
};

export default Controller;
