/**
 * 旧识桥 api · admin/js/app.js
 * ----------------------------------------------------------------
 * 全局公共逻辑：API_BASE、axios 实例、鉴权拦截器、公共工具函数。
 * 前端为纯静态页（Vue3 + Element Plus + axios，CDN 锁版本引入），
 * 后端地址在此集中配置。部署时修改 window.APP_CONFIG 即可。
 * ----------------------------------------------------------------
 */

// 全局配置：部署时按需修改（默认 admin 与 api 同级时相对路径）
window.APP_CONFIG = window.APP_CONFIG || {};
const API_BASE = window.APP_CONFIG.API_BASE || (location.origin + '/api/index.php');

// Element Plus 的 CDN 版不会把命令式 API 挂到 window，这里统一挂载，
// 供各页面与下方拦截器直接使用 ElMessage / ElMessageBox。
if (window.ElementPlus) {
  window.ElMessage = window.ElementPlus.ElMessage;
  window.ElMessageBox = window.ElementPlus.ElMessageBox;
}

// 管理员 TOKEN 存取（localStorage）
const ADMIN_TOKEN_KEY = 'jiushiqiao_admin_token';
function getToken() {
  return localStorage.getItem(ADMIN_TOKEN_KEY) || '';
}
function setToken(t) {
  if (t) { localStorage.setItem(ADMIN_TOKEN_KEY, t); }
  else { localStorage.removeItem(ADMIN_TOKEN_KEY); }
}

// API 密钥存取（localStorage）——用于复制地址时自动拼接 key
const API_KEY_KEY = 'jiushiqiao_api_key';
function getApiKey() {
  return localStorage.getItem(API_KEY_KEY) || '';
}
function setApiKey(k) {
  if (k) { localStorage.setItem(API_KEY_KEY, k); }
  else { localStorage.removeItem(API_KEY_KEY); }
}

// 401 处理全局锁：防止并发请求同时触发多个弹窗
let isHandling401 = false;

// axios 实例
const http = axios.create({
  baseURL: API_BASE,
  timeout: 15000,
});

// 请求拦截器：统一带 X-Admin-Token
http.interceptors.request.use(function (config) {
  const token = getToken();
  if (token) {
    config.headers['X-Admin-Token'] = token;
  }
  return config;
}, function (error) {
  return Promise.reject(error);
});

// 响应拦截器：统一处理后端 code 与非 2xx 错误
http.interceptors.response.use(function (response) {
  const data = response.data;
  // 后端管理接口返回 {code, msg, data}；成功 code===0
  if (data && typeof data === 'object' && 'code' in data) {
    if (data.code === 0) {
      return data.data;
    }
    if (window.ElMessage) {
      ElMessage.error(data.msg || '请求失败');
    }
    return Promise.reject(data);
  }
  // 非 JSON（如 runtime 纯文本）原样返回
  return data;
}, async function (error) {
  const resp = error.response;
  const originalRequest = error.config;

  // 401 未授权：全局锁防并发弹窗，等待用户输入新 token 后自动重试原请求（最多 1 次）
  if (resp && resp.status === 401 && !originalRequest._retry401) {
    originalRequest._retry401 = true; // 防止循环重试

    // 等待其它并发 401 处理完成
    while (isHandling401) {
      await new Promise(r => setTimeout(r, 50));
    }

    // 双重检查：等待期间可能已被其它请求刷新了 token
    if (getToken() && getToken() !== originalRequest.headers?.['X-Admin-Token']) {
      return http.request(originalRequest);
    }

    isHandling401 = true;
    try {
      setToken(''); // 先清旧 token

      if (window.ElMessage) {
        ElMessage.error('未授权，请重新输入管理员 TOKEN');
      }

      // 等待用户完成输入（含取消）
      const ok = await promptAdminToken();

      if (ok) {
        // 用户输了新 token，自动重试原请求（会带上新 token）
        return http.request(originalRequest);
      }

      // 用户取消：不重试，直接抛错让上层处理
      return Promise.reject(error);
    } finally {
      isHandling401 = false;
    }
  }

  // 其它错误
  if (window.ElMessage) {
    const msg = (resp && resp.data && resp.data.msg)
      || error.message || '网络错误';
    ElMessage.error(msg);
  }
  return Promise.reject(error);
});

/**
 * 请求管理接口的便捷封装。
 * route: 'admin/api/list' 等（后端统一走 ?route=xxx）
 * params: 对象，GET 时作为查询参数
 * method/data: 写操作（POST）
 */
async function apiRequest(route, params, method, data) {
  const method2 = (method || 'GET').toUpperCase();
  const payload = {
    method: method2,
    params: Object.assign({ route: route }, params || {}),
  };
  if (method2 !== 'GET') {
    payload.data = Object.assign({}, data || {});
  }
  return http.request(payload);
}

/**
 * 新增/编辑 API。
 */
async function saveApi(item) {
  return apiRequest('admin/api/save', null, 'POST', item);
}

/**
 * 删除 API。
 */
async function deleteApi(id) {
  return apiRequest('admin/api/delete', null, 'POST', { id: id });
}

/**
 * 启用/禁用 API。
 */
async function toggleApi(id, enabled) {
  return apiRequest('admin/api/toggle', null, 'POST', { id: id, enabled: enabled ? 1 : 0 });
}

/**
 * 素材相关。
 */
async function listText(params) {
  return apiRequest('admin/text/list', params, 'GET');
}
async function saveText(item) {
  return apiRequest('admin/text/save', null, 'POST', item);
}
async function deleteText(id) {
  return apiRequest('admin/text/delete', null, 'POST', { id: id });
}

/**
 * 日志相关。
 */
async function listLog(params) {
  return apiRequest('admin/log/list', params, 'GET');
}
async function clearLog(params) {
  return apiRequest('admin/log/clear', null, 'POST', params || {});
}

// ---- 公共工具 ----

/**
 * 复制文本到剪贴板。
 */
function copyText(text) {
  if (navigator.clipboard && navigator.clipboard.writeText) {
    return navigator.clipboard.writeText(text);
  }
  // 降级：临时 textarea
  const ta = document.createElement('textarea');
  ta.value = text;
  document.body.appendChild(ta);
  ta.select();
  document.execCommand('copy');
  document.body.removeChild(ta);
  return Promise.resolve();
}

/**
 * 格式化时间戳（秒）。
 */
function fmtTime(ts) {
  if (!ts) return '-';
  const d = new Date(ts * 1000);
  const p = function (n) { return n < 10 ? '0' + n : '' + n; };
  return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate())
    + ' ' + p(d.getHours()) + ':' + p(d.getMinutes()) + ':' + p(d.getSeconds());
}

/**
 * 构建对外调用地址（runtime）。
 */
function buildApiUrl(path) {
  const key = getApiKeyFromUrl() || '';
  let u = location.origin + '/api/index.php?route=runtime&path=' + encodeURIComponent(path);
  if (key) { u += '&key=' + encodeURIComponent(key); }
  return u;
}

/**
 * 获取 API 密钥：优先 localStorage，其次 APP_CONFIG（部署时预设）。
 */
function getApiKeyFromUrl() {
  const local = getApiKey();
  if (local) return local;
  if (window.APP_CONFIG && window.APP_CONFIG.API_KEY) {
    return window.APP_CONFIG.API_KEY;
  }
  return '';
}

/**
 * 提示输入/更新 API 密钥（Element Plus 内置输入弹窗）。
 * 返回 Promise<boolean>：true=已保存，false=取消。
 */
function promptApiKey() {
  return new Promise((resolve) => {
    if (!window.ElMessageBox) {
      const k = window.prompt('请输入 API 访问密钥（key）', getApiKey());
      if (k !== null) { setApiKey(k); resolve(true); } else { resolve(false); }
      return;
    }
    ElMessageBox.prompt('请输入 API 访问密钥（key）', '设置密钥', {
      confirmButtonText: '保存',
      cancelButtonText: '取消',
      inputValue: getApiKey(),
      inputPattern: /^[a-zA-Z0-9_-]{16,128}$/,
      inputErrorMessage: '密钥格式：16-128 位字母数字下划线中划线',
    })
      .then(({ value }) => {
        setApiKey(value);
        if (window.ElMessage) ElMessage.success('密钥已保存，复制地址将自动带上');
        resolve(true);
      })
      .catch(() => resolve(false));
  });
}

/**
 * 提示输入/更新管理员 TOKEN（Element Plus 内置输入弹窗）。
 * 返回 Promise<boolean>：true=已保存，false=取消。
 */
function promptAdminToken() {
  return new Promise((resolve) => {
    if (!window.ElMessageBox) {
      // 降级：Element Plus 未就绪时回退原生 prompt
      const t = window.prompt('请输入管理员 TOKEN（X-Admin-Token）', getToken());
      if (t !== null) { setToken(t); resolve(true); } else { resolve(false); }
      return;
    }
    ElMessageBox.prompt('请输入管理员 TOKEN（X-Admin-Token）', '设置 TOKEN', {
      confirmButtonText: '保存',
      cancelButtonText: '取消',
      inputValue: getToken(),
      inputPattern: /^[a-zA-Z0-9_-]{16,128}$/,
      inputErrorMessage: 'TOKEN 格式：16-128 位字母数字下划线中划线',
    })
      .then(({ value }) => {
        setToken(value);
        if (window.ElMessage) ElMessage.success('TOKEN 已保存');
        resolve(true);
      })
      .catch(() => resolve(false));
  });
}

// 暴露到 window 供各页面使用
window.JSQ = {
  API_BASE: API_BASE,
  http: http,
  getToken: getToken,
  setToken: setToken,
  promptAdminToken: promptAdminToken,
  getApiKey: getApiKey,
  setApiKey: setApiKey,
  promptApiKey: promptApiKey,
  apiRequest: apiRequest,
  saveApi: saveApi,
  deleteApi: deleteApi,
  toggleApi: toggleApi,
  listText: listText,
  saveText: saveText,
  deleteText: deleteText,
  listLog: listLog,
  clearLog: clearLog,
  copyText: copyText,
  fmtTime: fmtTime,
  buildApiUrl: buildApiUrl,
  getApiKeyFromUrl: getApiKeyFromUrl,
};