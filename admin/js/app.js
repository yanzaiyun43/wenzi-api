/**
 * 旧识桥 api · admin/js/app.js
 * ----------------------------------------------------------------
 * 全局公共逻辑：API_BASE、axios 实例、登录会话、公共工具函数。
 * 前端为纯静态页（Vue3 + Element Plus + axios，CDN 锁版本引入），
 * 后端地址在此集中配置。部署时修改 window.APP_CONFIG 即可。
 *
 * 鉴权方式：账号 + 密码登录换取会话令牌，令牌随后放在
 * X-Admin-Token 请求头里；明文令牌只保存在浏览器 localStorage。
 * 对外调用接口（runtime）不需要任何密钥。
 * ----------------------------------------------------------------
 */

// 全局配置：部署时按需修改。默认按当前页面所在目录推导同级 api/，兼容子目录部署。
window.APP_CONFIG = window.APP_CONFIG || {};
const API_BASE = window.APP_CONFIG.API_BASE || new URL('../api/index.php', document.baseURI).href;

// Element Plus 的 CDN 版不会把命令式 API 挂到 window，这里统一挂载，
// 供各页面与下方拦截器直接使用 ElMessage / ElMessageBox。
if (window.ElementPlus) {
  window.ElMessage = window.ElementPlus.ElMessage;
  window.ElMessageBox = window.ElementPlus.ElMessageBox;
}

// 登录会话令牌存取（localStorage）
const ADMIN_SESSION_KEY = 'jiushiqiao_admin_session';
function getToken() {
  return localStorage.getItem(ADMIN_SESSION_KEY) || '';
}
function setToken(t) {
  if (t) { localStorage.setItem(ADMIN_SESSION_KEY, t); }
  else { localStorage.removeItem(ADMIN_SESSION_KEY); }
}

// 登录页地址（带回来路，登录后跳回原页面）
function loginUrl(next) {
  const base = new URL('login.html', document.baseURI);
  const target = next || (location.pathname + location.search);
  if (target && target.indexOf('login.html') === -1) {
    base.searchParams.set('next', target);
  }
  return base.href;
}

function isLoginPage() {
  return location.pathname.indexOf('login.html') !== -1;
}

// axios 实例
const http = axios.create({
  baseURL: API_BASE,
  timeout: 15000,
});

// 请求拦截器：统一带会话令牌
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
}, function (error) {
  const resp = error.response;
  const originalRequest = error.config || {};
  const url = String(originalRequest.url || '');

  // 401 未授权：清掉本地令牌并回到登录页（登录接口本身的 401 交给页面处理）
  if (resp && resp.status === 401 && url.indexOf('admin/login') === -1) {
    setToken('');
    if (!isLoginPage()) {
      if (window.ElMessage) {
        ElMessage.error('登录已过期，请重新登录');
      }
      location.href = loginUrl();
    }
    return Promise.reject(error);
  }

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

// ---- 登录会话 ----

/** 查询会话状态：{ need_init, logged_in, username }。 */
async function fetchSession() {
  return http.request({
    method: 'GET',
    params: { route: 'admin/session' },
  });
}

/**
 * 页面守卫：进管理页前调用。
 * 未登录 -> 跳登录页；还没有管理员账号 -> 跳登录页做初始化。
 * 返回 Promise<boolean>：true=已登录，可继续加载数据。
 */
async function ensureLogin() {
  try {
    const s = await fetchSession();
    if (s && s.need_init) {
      if (!isLoginPage()) location.href = loginUrl();
      return false;
    }
    if (!s || !s.logged_in) {
      setToken('');
      if (!isLoginPage()) location.href = loginUrl();
      return false;
    }
    currentUser = s.username || '';
    return true;
  } catch (e) {
    return false;
  }
}

let currentUser = '';

/** 账号 + 密码登录；成功后保存令牌。 */
async function login(username, password) {
  const data = await http.request({
    method: 'POST',
    params: { route: 'admin/login' },
    data: { username: username, password: password },
  });
  setToken(data.token);
  currentUser = data.username || username;
  return data;
}

/** 首次初始化管理员账号（仅当系统还没有任何账号）。 */
async function initAdmin(username, password) {
  const data = await http.request({
    method: 'POST',
    params: { route: 'admin/init' },
    data: { username: username, password: password },
  });
  setToken(data.token);
  currentUser = data.username || username;
  return data;
}

/** 修改密码（需登录）。 */
async function changePassword(oldPassword, newPassword) {
  return apiRequest('admin/password', null, 'POST', {
    old_password: oldPassword,
    new_password: newPassword,
  });
}

/** 退出登录：服务端销毁会话，本地清令牌并回登录页。 */
async function logout() {
  try {
    await apiRequest('admin/logout', null, 'POST', {});
  } catch (e) {
    // 会话可能已失效，忽略错误继续清理本地状态
  }
  setToken('');
  currentUser = '';
  location.href = loginUrl('index.html');
}

/** 当前登录账号名（ensureLogin 成功后可用）。 */
function getUsername() {
  return currentUser;
}

// ---- 业务封装 ----

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
    return navigator.clipboard.writeText(text).catch(function () {
      return copyTextFallback(text);
    });
  }
  return copyTextFallback(text);
}

function copyTextFallback(text) {
  const ta = document.createElement('textarea');
  ta.value = text;
  ta.setAttribute('readonly', '');
  ta.style.position = 'fixed';
  ta.style.opacity = '0';
  document.body.appendChild(ta);
  ta.select();
  let copied = false;
  try {
    copied = document.execCommand('copy') === true;
  } finally {
    document.body.removeChild(ta);
  }
  if (!copied) {
    return Promise.reject(new Error('clipboard copy failed'));
  }
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
 * 构建对外调用地址（runtime）。接口无需密钥，地址可直接分享。
 */
function buildApiUrl(path) {
  return API_BASE + '?route=runtime&path=' + encodeURIComponent(path);
}

// 暴露到 window 供各页面使用
window.JSQ = {
  API_BASE: API_BASE,
  http: http,
  getToken: getToken,
  setToken: setToken,
  loginUrl: loginUrl,
  fetchSession: fetchSession,
  ensureLogin: ensureLogin,
  login: login,
  initAdmin: initAdmin,
  changePassword: changePassword,
  logout: logout,
  getUsername: getUsername,
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
};
