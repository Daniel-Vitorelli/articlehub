// ============================================
//  ArticleHub — App Logic with PHP API Backend
// ============================================

(function () {
  "use strict";

  // ---- API Base ----
  const API = "api";

  // ---- State ----
  let users = [];
  let requests = [];
  let deletedRequests = [];
  let domains = [];
  let languages = [];
  let niches = [];
  let notifications = [];
  let messages = [];
  let currentUser = null;
  let currentMsgTab = "inbox";
  let pollInterval = null;
  let realtimeSource = null;
  let complianceHistoryProvider = null;
  let currentImageData = null; // { id, mime, b64, filename } - só fica em memória enquanto modal aberto
  let periodicAnalysisGroups = [];
  let periodicAnalysisVisible = [];
  let periodicAnalysisAll = []; // latest row por grupo (sem filtro) — fonte para filtragem client-side
  let periodicAnalysisLoaded = 0;
  let periodicAnalysisTotal = 0;
  let periodicLoadedPromise = null;
  let requestHistoryCache = {}; // request_id -> [history]
  let complianceHistoryCache = {}; // request_id -> [compliance_history]
  let periodicSentinelObserver = null;
  const PERIODIC_PAGE_SIZE = 50;
  const PERIODIC_SENTINEL_MARGIN = "500px"; // Alterar aqui o gatilho do infinite scroll
  const selectedPeriodicKeys = new Set();
  const POLL_INTERVAL_MS = 15000;
  const APP_VERSION = "1.4.4";
  // Cache de opções de filtro (distinct) - buscadas uma vez por sessão
  let periodicDomainOptionsCache = null;
  let periodicTypeOptionsCache = null;

  // ---- Helpers ----
  const $ = (sel) => document.querySelector(sel);
  const $$ = (sel) => document.querySelectorAll(sel);

  function escapeHtml(str) {
    if (!str) return "";
    const div = document.createElement("div");
    div.textContent = str;
    return div.innerHTML;
  }

  // ============================================
  //  TIMEZONE UTILS (America/Sao_Paulo)
  // ============================================
  function formatDate(dateStr) {
    if (!dateStr) return "—";
    // Se vier 'YYYY-MM-DD', forçamos a leitura adicionando tempo nulo UTC,
    // garantindo que não sofra shift se o script rodar em fuso diferente
    const [y, m, d] = dateStr.split("-");
    return `${d}/${m}/${y}`;
  }

  function formatDateTime(isoStr) {
    if (!isoStr) return "—";
    const d = new Date(isoStr);
    try {
      return new Intl.DateTimeFormat("pt-BR", {
        timeZone: "America/Sao_Paulo",
        day: "2-digit",
        month: "2-digit",
        year: "numeric",
        hour: "2-digit",
        minute: "2-digit",
      }).format(d);
    } catch (e) {
      return d.toLocaleString("pt-BR", {
        day: "2-digit",
        month: "2-digit",
        year: "numeric",
        hour: "2-digit",
        minute: "2-digit",
      });
    }
  }

  function statusLabel(s) {
    return (
      {
        pending: "Pendente",
        "in-progress": "Em Produção",
        review: "Em Revisão",
        done: "Concluído",
        published: "Publicado",
        revisado: "Revisado",
      }[s] || s
    );
  }

  function priorityLabel(p) {
    return { alta: "Alta", media: "Média", baixa: "Baixa" }[p] || p;
  }

  function roleLabel(r) {
    return (
      {
        admin: "Administrador",
        gestor: "Gestor de Tráfego",
        revisor: "Revisor",
        redator: "Redator",
      }[r] || r
    );
  }

  function getInitials(name) {
    if (!name) return "--";
    return name
      .split(" ")
      .map((w) => w[0])
      .join("")
      .toUpperCase()
      .slice(0, 2);
  }

  function today() {
    // Retorna a data de hoje formatada em YYYY-MM-DD considerando o fuso de São Paulo
    try {
      const parts = new Intl.DateTimeFormat("pt-BR", {
        timeZone: "America/Sao_Paulo",
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
      }).formatToParts(new Date());
      const p = {};
      parts.forEach((part) => (p[part.type] = part.value));
      return `${p.year}-${p.month}-${p.day}`;
    } catch (e) {
      return new Date().toISOString().split("T")[0];
    }
  }

  function fieldLabel(field) {
    return (
      {
        keyword: "Palavra-chave",
        blog: "Blog",
        domain_id: "Blog",
        writer: "Redator",
        writer_id: "Redator",
        priority: "Prioridade",
        wordcount: "Volume de Palavras",
        deadline: "Prazo",
        language: "Idioma",
        purpose: "Finalidade",
        content_type: "Tipo",
        niche_id: "Nicho",
        instructions: "Instruções",
        status: "Status",
      }[field] || field
    );
  }

  // ---- API Fetch Helpers ----
  async function apiGet(endpoint) {
    const res = await fetch(`${API}/${endpoint}`, { credentials: "include" });
    if (!res.ok) {
      const err = await res.json().catch(() => ({}));
      throw new Error(err.error || `Erro ${res.status}`);
    }
    return res.json();
  }

  async function apiPost(endpoint, data) {
    const res = await fetch(`${API}/${endpoint}`, {
      method: "POST",
      credentials: "include",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(data),
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(json.error || `Erro ${res.status}`);
    return json;
  }

  async function apiPut(endpoint, data) {
    const res = await fetch(`${API}/${endpoint}`, {
      method: "PUT",
      credentials: "include",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(data),
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(json.error || `Erro ${res.status}`);
    return json;
  }

  async function apiDelete(endpoint) {
    const res = await fetch(`${API}/${endpoint}`, {
      method: "DELETE",
      credentials: "include",
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(json.error || `Erro ${res.status}`);
    return json;
  }

  // ---- Load Data from API (lazy) ----
  // Essencial (bloqueante): requests + notifications - usado no dashboard
  async function loadAll() {
    try {
      // Carga PARALELA de tudo que é leve. Navegação e modais ficam instantâneos
      // porque os dados (inclusive resumo_analise/instructions/histórico) já estão em memória.
      // Só a IMAGEM (BLOB binário) continua lazy por registro.
      const [reqData, notifData, domData, langData, nicheData, userData, delData, histData, compHistData, periodicRaw] = await Promise.all([
        apiGet("requests.php"),
        apiGet("notifications.php"),
        apiGet("domains.php"),
        apiGet("languages.php"),
        apiGet("niches.php"),
        apiGet("users.php"),
        apiGet("requests.php?action=deleted"),
        apiGet("requests.php?action=history_all").catch(() => []),
        apiGet("compliance.php?action=history_all").catch(() => []),
        apiGet("periodic_analysis.php").catch(() => []), // 403 p/ não-admin não deve quebrar o resto
      ]);
      requests = reqData;
      notifications = notifData;
      domains = domData;
      languages = langData;
      niches = nicheData;
      users = userData;
      deletedRequests = delData;
      // Histórico de todas as solicitações em cache para modal instantâneo
      requestHistoryCache = buildHistoryCache(histData);
      // Histórico de compliance em cache para modal instantâneo
      complianceHistoryCache = buildHistoryCache(compHistData);
      // Análise periódica: pré-carrega tudo e agrupa em memória (abre na hora)
      buildPeriodicInMemory(periodicRaw);
    } catch (e) {
      console.error("Erro ao carregar dados:", e);
    }
  }

  // Agrupa rows da periodic_analysis em memória (latest por dominio+id_post)
  function buildPeriodicInMemory(raw) {
    const rows = Array.isArray(raw) ? raw : (raw && raw.data ? raw.data : []);
    const groupsMap = new Map();
    rows.forEach((r) => {
      const key = `${r.dominio}::${r.id_post ?? ""}`;
      let g = groupsMap.get(key);
      if (!g) { g = { key, sorted: [] }; groupsMap.set(key, g); }
      g.sorted.push(r);
    });
    periodicAnalysisGroups = [];
    groupsMap.forEach((g) => {
      g.sorted.sort((a, b) => new Date(b.created_at) - new Date(a.created_at));
      periodicAnalysisGroups.push(g);
    });
    periodicAnalysisAll = periodicAnalysisGroups.map((g) => g.sorted[0]).filter(Boolean);
    // Cache de filtros (distinct) derivado dos dados em memória (sem rede)
    periodicDomainOptionsCache = [...new Set(periodicAnalysisAll.map((r) => r.dominio))].filter(Boolean).sort();
    periodicTypeOptionsCache = [...new Set(periodicAnalysisAll.map((r) => r.post_type))].filter(Boolean).sort();
  }

  // Garante que a periodic esteja carregada (preload em loadAll ou sob demanda)
  function ensurePeriodicLoaded() {
    if (periodicAnalysisGroups.length) return Promise.resolve();
    if (periodicLoadedPromise) return periodicLoadedPromise;
    periodicLoadedPromise = apiGet("periodic_analysis.php")
      .then((raw) => buildPeriodicInMemory(raw))
      .catch(() => {})
      .finally(() => { periodicLoadedPromise = null; });
    return periodicLoadedPromise;
  }

  // Reconstrói periodicAnalysisAll a partir dos groups (após mutações locais)
  function syncPeriodicAllFromGroups() {
    periodicAnalysisAll = periodicAnalysisGroups.map((g) => g.sorted[0]).filter(Boolean);
    periodicDomainOptionsCache = [...new Set(periodicAnalysisAll.map((r) => r.dominio))].filter(Boolean).sort();
    periodicTypeOptionsCache = [...new Set(periodicAnalysisAll.map((r) => r.post_type))].filter(Boolean).sort();
  }

  // Recarrega a periodic do servidor (usado após reanálise em lote) silenciosamente
  async function reloadPeriodicFromServer() {
    try {
      const raw = await apiGet("periodic_analysis.php");
      buildPeriodicInMemory(raw);
    } catch (e) {
      console.error("Falha ao recarregar periódica:", e);
    }
  }

  // Garante dados de uma view antes de render (chamado em navigateTo)
  async function ensureViewData(viewName) {
    const needs = [];
    if (["requests"].includes(viewName) && (domains.length === 0 || users.length === 0)) {
      if (domains.length === 0) needs.push(apiGet("domains.php").then(d => domains = d));
      if (users.length === 0) needs.push(apiGet("users.php").then(d => users = d));
      if (languages.length === 0) needs.push(apiGet("languages.php").then(d => languages = d));
      if (niches.length === 0) needs.push(apiGet("niches.php").then(d => niches = d));
    }
    if (["users"].includes(viewName) && users.length === 0) needs.push(apiGet("users.php").then(d => users = d));
    if (["domains"].includes(viewName) && domains.length === 0) needs.push(apiGet("domains.php").then(d => domains = d));
    if (["languages"].includes(viewName) && languages.length === 0) needs.push(apiGet("languages.php").then(d => languages = d));
    if (["niches"].includes(viewName) && niches.length === 0) needs.push(apiGet("niches.php").then(d => niches = d));
    if (["trash"].includes(viewName) && deletedRequests.length === 0) needs.push(apiGet("requests.php?action=deleted").then(d => deletedRequests = d));
    if (needs.length) await Promise.all(needs);
  }

  // ============================================
  //  PERMISSIONS
  // ============================================
  function is(role) {
    return currentUser && currentUser.role === role;
  }
  function canCreate() {
    return !!currentUser;
  }
  function canDelete() {
    return is("admin");
  }
  function canManageUsers() {
    return is("admin");
  }
  function canManageDomains() {
    return is("admin");
  }
  function canChangeStatus(r) {
    if (!currentUser || !r) return false;
    if (is("admin") || is("redator") || is("revisor")) return true;
    if (Number(r.requested_by_id) === Number(currentUser.id)) return true;
    return false;
  }

  function canEdit(r) {
    if (is("admin") || is("revisor")) return true;
    if (
      (is("gestor") || is("redator")) &&
      Number(r.requested_by_id) === Number(currentUser.id)
    )
      return true;
    return false;
  }

  function canManagePendency(r) {
    if (is("admin") || is("revisor") || is("redator")) return true;
    if (is("gestor") && Number(r.requested_by_id) === Number(currentUser.id))
      return true;
    return false;
  }

  function canSeeRevisado() {
    return is("admin") || is("revisor");
  }

  function getVisibleRequests() {
    // Already filtered server-side, return as-is
    return requests;
  }

  // ============================================
  //  LOGIN / LOGOUT
  // ============================================
  async function initLogin() {
    try {
      const data = await apiGet("auth.php?action=check");
      if (data.authenticated) {
        currentUser = data.user;
        applyThemeFromPrefs(data.preferences);
        await showApp(data.preferences);
        return;
      }
    } catch (e) {
      console.error("Session check failed:", e);
    }
    showLogin();
  }

  function showLogin() {
    $("#loginScreen").style.display = "flex";
    $("#appWrapper").style.display = "none";
    currentUser = null;
  }

  async function showApp(prefs) {
    $("#loginScreen").style.display = "none";
    $("#appWrapper").style.display = "flex";
    if (prefs) {
      applyThemeFromPrefs(prefs);
    }
    applyRoleUI();
    setHeaderDate();
    await loadAll();
    updateNotifBadge();
    updateMsgBadge();
    navigateTo("dashboard");
    startPolling();
    connectRealtime();
  }

  async function handleLogin(e) {
    e.preventDefault();
    const email = $("#loginEmail").value.trim().toLowerCase();
    const password = $("#loginPassword").value;
    const errorEl = $("#loginError");

    try {
      const data = await apiPost("auth.php?action=login", { email, password });
      errorEl.textContent = "";
      currentUser = data.user;
      await showApp(data.preferences);
    } catch (err) {
      errorEl.textContent = "❌ " + err.message;
    }
  }

  async function handleLogout() {
    stopPolling();
    disconnectRealtime();
    try {
      await apiPost("auth.php?action=logout", {});
    } catch (e) {
      /* ignore */
    }
    showLogin();
    $("#loginEmail").value = "";
    $("#loginPassword").value = "";
    $("#loginError").textContent = "";
  }

  // ---- Auto-refresh polling (lazy, pausa se aba oculta) ----
  function startPolling() {
    stopPolling();
    pollInterval = setInterval(async () => {
      if (!currentUser) return;
      if (document.hidden) return;
      try {
        const [notifData, reqData] = await Promise.all([
          apiGet("notifications.php"),
          apiGet("requests.php"),
        ]);
        notifications = notifData;
        requests = reqData;
        updateNotifBadge();
        updateMsgBadge();
        // Refresh current view if on messages
        const active = $(".nav-link.active[data-view]");
        if (active && active.dataset.view === "messages") renderMessages();
        if (active && active.dataset.view === "dashboard") renderDashboard();
      } catch (e) {
        /* silent */
      }
    }, POLL_INTERVAL_MS);
  }

  function stopPolling() {
    if (pollInterval) {
      clearInterval(pollInterval);
      pollInterval = null;
    }
  }

  // ---- Realtime via SSE + Webhook ----
  // Atualiza em segundo plano TODOS os dados da view atual, sem nenhum indicador
  // de carregamento. A tela só é substituída quando os fetches terminam.
  async function silentRefreshActiveView() {
    // Notificações/mensagens: badge sempre atualizado
    try {
      notifications = await apiGet("notifications.php");
      updateNotifBadge();
      updateMsgBadge();
    } catch (_) {}

    const active = document.querySelector(".nav-link.active[data-view]");
    const view = active ? active.dataset.view : "";
    if (!view) return;

    if (view === "requests") {
      try {
        const [reqData, histData, compHistData] = await Promise.all([
          apiGet("requests.php"),
          apiGet("requests.php?action=history_all").catch(() => []),
          apiGet("compliance.php?action=history_all").catch(() => []),
        ]);
        requests = reqData;
        requestHistoryCache = buildHistoryCache(histData);
        complianceHistoryCache = buildHistoryCache(compHistData);
        renderRequests();
      } catch (e) {
        console.error("Falha ao atualizar solicitações via realtime:", e);
      }
      // Mantém a periódica em cache em background (sem UI) para quando navegar
      reloadPeriodicFromServer().catch(() => {});
    } else if (view === "trash") {
      try {
        deletedRequests = await apiGet("requests.php?action=deleted");
        renderTrash();
      } catch (_) {}
      reloadPeriodicFromServer().catch(() => {});
    } else if (view === "compliance-analysis") {
      try {
        // Re-busca TUDO da periódica no servidor (não usa cache obsoleto) e atualiza a tela
        await reloadPeriodicFromServer();
        await renderComplianceAnalysis({ preserveSelection: true });
      } catch (e) {
        console.error("Falha ao atualizar periódica via realtime:", e);
      }
      // Mantém requests em cache em background (sem UI)
      apiGet("requests.php").then((d) => { requests = d; }).catch(() => {});
    } else if (view === "dashboard") {
      try {
        requests = await apiGet("requests.php");
        renderDashboard();
      } catch (_) {}
    } else if (view === "messages") {
      try { await renderMessages(); } catch (_) {}
    }
  }

  // Agrupa um array de histórico em mapa request_id -> [linhas]
  function buildHistoryCache(data) {
    const m = {};
    (Array.isArray(data) ? data : []).forEach((h) => {
      if (!m[h.request_id]) m[h.request_id] = [];
      m[h.request_id].push(h);
    });
    return m;
  }

  function connectRealtime() {
    disconnectRealtime();
    if (!currentUser) return;
    try {
      const es = new EventSource("api/realtime.php", { withCredentials: true });
      realtimeSource = es;

      es.addEventListener("connected", (e) => {
        // Conexão estabelecida, não precisa fazer nada (polling continua como fallback)
        console.log("Realtime conectado", e.data);
      });

      es.addEventListener("refresh", async (e) => {
        try {
          let payload = {};
          try { payload = JSON.parse(e.data); } catch (_) {}
          console.log("Webhook refresh recebido", payload);
          // Refresh em BACKGROUND de tudo o que o usuário está vendo.
          // Não é limitado a ids específicos: atualiza TODOS os dados da view atual.
          // Sem spinner: a tela só é atualizada quando os fetches terminam.
          await silentRefreshActiveView();
        } catch (err) {
          console.error("Erro ao processar refresh realtime:", err);
        }
      });

      es.onerror = (err) => {
        console.warn("Realtime erro/desconectado, tentando reconectar em 5s", err);
        // EventSource reconecta automaticamente, mas se fechar, tenta de novo
        if (es.readyState === EventSource.CLOSED) {
          setTimeout(() => {
            if (currentUser) connectRealtime();
          }, 5000);
        }
      };
    } catch (e) {
      console.error("Falha ao conectar realtime:", e);
    }
  }

  function disconnectRealtime() {
    if (realtimeSource) {
      try {
        realtimeSource.close();
      } catch (_) {}
      realtimeSource = null;
    }
  }

  // ============================================
  //  ROLE-BASED UI
  // ============================================
  function applyRoleUI() {
    $("#userAvatar").textContent = getInitials(currentUser.name);
    $("#userName").textContent = currentUser.name;
    $("#userRole").textContent = roleLabel(currentUser.role);

    const badge = $("#roleBadge");
    badge.textContent = roleLabel(currentUser.role);
    badge.className = `user-role-badge role-${currentUser.role}`;

    $$(".admin-only").forEach((el) => {
      el.style.display = is("admin") ? "" : "none";
    });
    $$(".gestor-admin-only").forEach((el) => {
      el.style.display = canCreate() ? "" : "none";
    });
    // 'gestor-col' logic removed as Solicitante is now visible to all
    $$(".revisor-admin-only").forEach((el) => {
      el.style.display = is("admin") || is("revisor") ? "" : "none";
    });
  }

  // ============================================
  //  NAVIGATION (lazy - garante dados antes de render sem mudar visual)
  // ============================================
  async function navigateTo(viewName, options) {
    if (
      (viewName === "users" ||
        viewName === "domains" ||
        viewName === "languages" ||
        viewName === "niches" ||
        viewName === "compliance-analysis") &&
      !is("admin")
    ) {
      viewName = "dashboard";
    }

    if (viewName === "requests" && options && options.statusFilter != null) {
      const statusSelect = $("#filterStatus");
      if (statusSelect) statusSelect.value = options.statusFilter;
    }

    $$(".view-panel").forEach((p) => p.classList.remove("active"));
    const target = $(`#view${capitalize(viewName)}`);
    if (target) target.classList.add("active");

    $$(".nav-link[data-view]").forEach((l) => l.classList.remove("active"));
    const activeLink = $(`.nav-link[data-view="${viewName}"]`);
    if (activeLink) activeLink.classList.add("active");

    const titles = {
      dashboard: "Dashboard",
      requests: "Solicitações",
      users: "Usuários",
      domains: "Domínios / Blogs",
      languages: "Idiomas",
      niches: "Nichos",
      messages: "Mensagens",
      logs: "Logs de Status",
      "compliance-analysis": "Análise Periódica de Compliance",
    };
    $("#pageTitle").textContent = titles[viewName] || "Dashboard";

    // Lazy: garante dados antes de render (sem trocar layout, só evita tabela vazia)
    await ensureViewData(viewName);

    if (viewName === "dashboard") renderDashboard();
    if (viewName === "requests") renderRequests();
    if (viewName === "users") renderUsers();
    if (viewName === "domains") renderDomains();
    if (viewName === "languages") renderLanguages();
    if (viewName === "niches") renderNiches();
    if (viewName === "messages") renderMessages();
    if (viewName === "logs") renderLogs();
    if (viewName === "trash") renderTrash();
    if (viewName === "compliance-analysis") renderComplianceAnalysis();
  }

  function capitalize(str) {
    return str.replace(/(^|-)(\w)/g, (_, sep, ch) => ch.toUpperCase());
  }

  // ============================================
  //  DASHBOARD VIEW
  // ============================================
  function renderDashboard() {
    const visible = getVisibleRequests();
    const total = visible.length;
    const pending = visible.filter((r) => r.status === "pending").length;
    const inProgress = visible.filter((r) => r.status === "in-progress").length;
    const done = visible.filter((r) => r.status === "done").length;
    const published = visible.filter((r) => r.status === "published").length;
    const rate = total > 0 ? Math.round(((done + published) / total) * 100) : 0;

    // Stat card for 'Revisados' (only visible for admin/revisor)
    const revisadoCard = $("#statRevisadoCard");
    if (revisadoCard) {
      if (canSeeRevisado()) {
        const revisado = visible.filter((r) => r.status === "revisado").length;
        revisadoCard.style.display = "";
        $("#statRevisado").textContent = revisado;
      } else {
        revisadoCard.style.display = "none";
      }
    }

    $("#statTotal").textContent = total;
    $("#statPending").textContent = pending;
    $("#statInProgress").textContent = inProgress;
    $("#statDone").textContent = done;
    $("#statPublished").textContent = published;
    $("#trendDone").textContent = `${rate}%`;

    $("#navBadgePending").textContent = pending;

    const sorted = [...visible]
      .sort((a, b) => new Date(b.created_at) - new Date(a.created_at))
      .slice(0, 5);
    const tbody = $("#dashTableBody");

    if (sorted.length === 0) {
      tbody.innerHTML = `<tr><td colspan="7"><div class="empty-state"><div class="empty-icon">📭</div><p>Nenhuma solicitação encontrada.</p></div></td></tr>`;
      return;
    }

    tbody.innerHTML = sorted
      .map((r) => {
        const color = r.color || "#7f5af0";
        const blogName = r.blog_name || "—";
        const writerName = r.writer_name || "A definir";

        const hasResumo = Number(r.has_resumo) ? true : (r.resumo_analise && String(r.resumo_analise).trim() !== "");
        const complianceValue = r.status_compliance || "";
        const label = complianceStatusLabel(complianceValue);

        return `
        <tr style="cursor:pointer" data-detail-id="${r.id}">
          <td><div class="article-title">${escapeHtml(r.keyword)}</div><span class="article-keyword">${r.wordcount} palavras</span></td>
          <td><div class="blog-name"><span class="blog-dot" style="background:${color}"></span>${escapeHtml(blogName)}</div></td>
          <td>${escapeHtml(writerName)}</td>
          <td><span class="status-badge ${r.status}">${statusLabel(r.status)}</span></td>
          <td>${
            complianceValue
              ? `<span class="status-badge ${complianceValue}${hasResumo ? " compliance-clickable" : ""}" ${hasResumo ? `data-compliance-id="${r.id}" title="Ver resumo da análise"` : ""}>${label}</span>`
              : "—"
          }</td>
          <td><span class="priority-indicator ${r.priority}"><span class="priority-dot"></span>${priorityLabel(r.priority)}</span></td>
          <td>${formatDate(r.deadline)}</td>
        </tr>`;
      })
      .join("");

    tbody.querySelectorAll("[data-detail-id]").forEach((row) => {
      row.addEventListener("click", () =>
        openDetail(Number(row.dataset.detailId)),
      );
    });

    tbody.querySelectorAll("[data-compliance-id]").forEach((el) => {
      el.addEventListener("click", (e) => {
        e.stopPropagation();
        openComplianceModal(Number(el.dataset.complianceId));
      });
    });
  }

  // ============================================
  //  REQUESTS VIEW
  // ============================================
  function populateRequestFilters() {
    const currentBlog = $("#filterBlog").value;
    const currentWriter = $("#filterWriter").value;
    const currentRequester = $("#filterRequester").value;

    // Blogs: do banco (domains)
    const blogSelect = $("#filterBlog");
    blogSelect.innerHTML =
      '<option value="">Todos Blogs</option>' +
      domains
        .filter((d) => d.active)
        .sort((a, b) => (a.blog_name || "").localeCompare(b.blog_name || ""))
        .map(
          (d) =>
            `<option value="${d.id}">${escapeHtml(d.blog_name || "—")}</option>`,
        )
        .join("");
    blogSelect.value = currentBlog;

    // Redatores: do banco (users com role redator)
    const writerSelect = $("#filterWriter");
    writerSelect.innerHTML =
      '<option value="">Todos Redatores</option>' +
      users
        .filter((u) => u.role === "redator" && u.active)
        .sort((a, b) => (a.name || "").localeCompare(b.name || ""))
        .map(
          (u) =>
            `<option value="${u.id}">${escapeHtml(u.name || "—")}</option>`,
        )
        .join("");
    writerSelect.value = currentWriter;

    // Solicitantes: do banco (users que podem criar solicitações)
    const requesterSelect = $("#filterRequester");
    requesterSelect.innerHTML =
      '<option value="">Todos Solicitantes</option>' +
      users
        .filter((u) => u.active)
        .sort((a, b) => (a.name || "").localeCompare(b.name || ""))
        .map(
          (u) =>
            `<option value="${u.id}">${escapeHtml(u.name || "—")}</option>`,
        )
        .join("");
    requesterSelect.value = currentRequester;
  }

  function renderRequests() {
    populateRequestFilters();

    const statusFilter = $("#filterStatus").value;
    const priorityFilter = $("#filterPriority").value;
    const blogFilter = $("#filterBlog").value;
    const writerFilter = $("#filterWriter").value;
    const requesterFilter = $("#filterRequester").value;
    const search = $("#globalSearch").value.toLowerCase().trim();

    let visible = [...getVisibleRequests()];

    if (statusFilter)
      visible = visible.filter((r) => r.status === statusFilter);
    if (priorityFilter)
      visible = visible.filter((r) => r.priority === priorityFilter);
    if (blogFilter)
      visible = visible.filter((r) => String(r.domain_id) === blogFilter);
    if (writerFilter)
      visible = visible.filter((r) => String(r.writer_id) === writerFilter);
    if (requesterFilter)
      visible = visible.filter(
        (r) => String(r.requested_by_id) === requesterFilter,
      );
    if (search) {
      visible = visible.filter((r) => {
        const h =
          `${r.keyword} ${r.blog_name || ""} ${r.writer_name || ""} ${r.requester_name || ""}`.toLowerCase();
        return h.includes(search);
      });
    }

    const statusOrder = {
      pending: 0,
      "in-progress": 1,
      review: 2,
      done: 3,
      published: 4,
      revisado: 5,
    };
    visible.sort((a, b) => {
      const sa = statusOrder[a.status] ?? 9;
      const sb = statusOrder[b.status] ?? 9;
      if (sa !== sb) return sa - sb;
      return new Date(a.deadline) - new Date(b.deadline);
    });

    const tbody = $("#requestsTableBody");

    if (visible.length === 0) {
      tbody.innerHTML = `<tr><td colspan="10"><div class="empty-state"><div class="empty-icon">📭</div><p>Nenhuma solicitação encontrada.</p></div></td></tr>`;
      $("#tableInfo").textContent = "Nenhuma solicitação";
      return;
    }

    tbody.innerHTML = visible
      .map((r) => {
        const color = r.color || "#7f5af0";
        const blogName = r.blog_name || "—";
        const writerName = r.writer_name || "A definir";
        const requesterName = r.requester_name || "—";
        const hasResumo = Number(r.has_resumo) ? true : (r.resumo_analise && String(r.resumo_analise).trim() !== "");
        const complianceValue = r.status_compliance || "";
        const label = complianceStatusLabel(complianceValue);
        // Soft delete: only pending requests can be deleted
        // Admin can delete any pending request; others only their own
        const canSoftDelete =
          r.status === "pending" &&
          (is("admin") || Number(r.requested_by_id) === Number(currentUser.id));
        const deleteBtn = canSoftDelete
          ? `<button class="row-action-btn btn-delete-row" data-delete-id="${r.id}" title="Mover para lixeira">🗑</button>`
          : "";
        const editBtn = canEdit(r)
          ? `<button class="row-action-btn" data-edit-id="${r.id}" title="Editar">✏️</button>`
          : "";

        const pendencyIconColor =
          Number(r.unresolved_pendencies_count) > 0
            ? "color: var(--accent-danger); font-weight: bold; font-size: 1.1em;"
            : "color: rgba(255,255,255,0.1); filter: grayscale(1);";
        const pendencyBtn = canManagePendency(r)
          ? `<button class="row-action-btn" data-pendency-id="${r.id}" title="Pendências" style="${pendencyIconColor}">⚠️</button>`
          : "";

        const requesterCol = `<td>${escapeHtml(requesterName)}</td>`;

        return `
        <tr style="cursor:pointer" data-detail-id="${r.id}">
          <td><div class="article-title">${escapeHtml(r.keyword)}</div><span class="article-keyword">${r.wordcount} palavras</span></td>
          <td><div class="blog-name"><span class="blog-dot" style="background:${color}"></span>${escapeHtml(blogName)}</div></td>
          ${requesterCol}
          <td>${escapeHtml(writerName)}</td>
          <td><span class="status-badge ${r.status}">${statusLabel(r.status)}</span></td>
          <td>${
            complianceValue
              ? `<span class="status-badge ${complianceValue}${hasResumo ? " compliance-clickable" : ""}" ${hasResumo ? `data-compliance-id="${r.id}" title="Ver resumo da análise"` : ""}>${label}</span>`
              : "—"
          }</td>
          <td><span class="priority-indicator ${r.priority}"><span class="priority-dot"></span>${priorityLabel(r.priority)}</span></td>
          <td>${formatDate(r.deadline)}</td>
          <td style="text-align:center; font-size:1.1em;">${Number(r.has_imagem) ? `<span class="image-view-btn" data-image-id="${r.id}" title="Clique para ver imagem" style="cursor:pointer">🖼️</span>` : '<span style="opacity:0.35" title="Sem imagem">—</span>'}</td>
          <td>
            <div class="row-actions">
              ${pendencyBtn}
              ${editBtn}
              ${deleteBtn}
            </div>
          </td>
        </tr>`;
      })
      .join("");

    $("#tableInfo").textContent =
      `Mostrando ${visible.length} de ${getVisibleRequests().length} solicitações`;

    tbody.querySelectorAll("[data-detail-id]").forEach((row) => {
      row.addEventListener("click", () =>
        openDetail(Number(row.dataset.detailId)),
      );
    });
    tbody.querySelectorAll("[data-edit-id]").forEach((btn) => {
      btn.addEventListener("click", (e) => {
        e.stopPropagation();
        openEditRequest(Number(btn.dataset.editId));
      });
    });
    tbody.querySelectorAll("[data-pendency-id]").forEach((btn) => {
      btn.addEventListener("click", (e) => {
        e.stopPropagation();
        openPendenciesModal(Number(btn.dataset.pendencyId));
      });
    });
    tbody.querySelectorAll("[data-delete-id]").forEach((btn) => {
      btn.addEventListener("click", (e) => {
        e.stopPropagation();
        deleteRequest(Number(btn.dataset.deleteId));
      });
    });

    tbody.querySelectorAll("[data-compliance-id]").forEach((el) => {
      el.addEventListener("click", (e) => {
        e.stopPropagation();
        openComplianceModal(Number(el.dataset.complianceId));
      });
    });

    tbody.querySelectorAll("[data-image-id]").forEach((el) => {
      el.addEventListener("click", (e) => {
        e.stopPropagation();
        openImageModal(Number(el.dataset.imageId));
      });
    });
  }

  // --- Imagem lazy load (só carrega BLOB ao clicar) ---
  async function openImageModal(id) {
    const img = $("#modalImageEl");
    const loader = $("#imageLoader");
    const errEl = $("#imageError");
    const infoEl = $("#imageInfo");
    const idEl = $("#imageModalId");
    const dlBtn = $("#btnDownloadImage");
    const delBtn = $("#btnDeleteImage");
    // Reset estado
    currentImageData = null;
    if (img) {
      img.style.display = "none";
      img.removeAttribute("src");
    }
    if (errEl) {
      errEl.style.display = "none";
      errEl.textContent = "";
    }
    if (loader) loader.style.display = "flex";
    if (idEl) idEl.textContent = `#${id}`;
    if (infoEl) infoEl.textContent = "Carregando...";
    if (dlBtn) {
      dlBtn.disabled = true;
      dlBtn.style.opacity = "0.5";
      dlBtn.style.pointerEvents = "none";
    }
    if (delBtn) {
      delBtn.disabled = true;
      delBtn.style.opacity = "0.5";
      delBtn.style.pointerEvents = "none";
    }
    openModal("modalImage");
    try {
      const data = await apiGet(`requests.php?action=image&id=${id}`);
      const mime = data.mime || "image/jpeg";
      const b64 = data.image;
      const filename = data.filename || null; // vem de imagem_nome no banco
      currentImageData = { id, mime, b64, filename };
      img.src = `data:${mime};base64,${b64}`;
      // Espera carregar para esconder loader e mostrar info de proporção
      await new Promise((resolve, reject) => {
        img.onload = resolve;
        img.onerror = reject;
      });
      if (loader) loader.style.display = "none";
      img.style.display = "block";
      // Mostra info preservando proporção original (sem esticar)
      if (infoEl) {
        const w = img.naturalWidth;
        const h = img.naturalHeight;
        const namePart = filename ? ` • ${filename}` : "";
        infoEl.textContent = w && h ? `${w} × ${h} • ${mime}${namePart} • proporção original` : `${mime}${namePart} • proporção original`;
      }
      if (dlBtn) {
        dlBtn.disabled = false;
        dlBtn.style.opacity = "1";
        dlBtn.style.pointerEvents = "auto";
      }
      const delBtn2 = $("#btnDeleteImage");
      if (delBtn2) {
        delBtn2.disabled = false;
        delBtn2.style.opacity = "1";
        delBtn2.style.pointerEvents = "auto";
      }
    } catch (e) {
      if (loader) loader.style.display = "none";
      if (errEl) {
        errEl.textContent = e.message || "Erro ao carregar imagem";
        errEl.style.display = "block";
      }
      if (infoEl) infoEl.textContent = "Erro ao carregar";
      currentImageData = null;
    }
  }

  async function deleteCurrentImage() {
    if (!currentImageData || !currentImageData.id) return;
    if (!confirm("Excluir a imagem desta solicitação? Esta ação não pode ser desfeita.")) return;
    const delBtn = $("#btnDeleteImage");
    const originalText = delBtn ? delBtn.innerHTML : "";
    if (delBtn) {
      delBtn.disabled = true;
      delBtn.innerHTML = '<span class="spinner" style="width:14px;height:14px;border-width:2px;"></span> Excluindo...';
    }
    try {
      await apiPut("requests.php?action=clear_image", { id: currentImageData.id });
      // Atualiza flag local e recarrega lista para refletir 🖼️ -> —
      const req = requests.find((r) => Number(r.id) === Number(currentImageData.id));
      if (req) {
        req.has_imagem = 0;
        req.imagem_nome = null;
      }
      const delReq = deletedRequests.find((r) => Number(r.id) === Number(currentImageData.id));
      if (delReq) {
        delReq.has_imagem = 0;
        delReq.imagem_nome = null;
      }
      closeModal("modalImage");
      renderRequests();
      // Se estiver no dashboard, atualiza também
      const active = $(".nav-link.active[data-view]");
      if (active && active.dataset.view === "dashboard") renderDashboard();
    } catch (e) {
      alert("Erro ao excluir imagem: " + (e.message || "erro desconhecido"));
      if (delBtn) {
        delBtn.disabled = false;
        delBtn.style.opacity = "1";
        delBtn.style.pointerEvents = "auto";
        delBtn.innerHTML = originalText;
      }
    }
  }

  function clearImageModal() {
    const img = $("#modalImageEl");
    if (img) {
      img.removeAttribute("src");
      img.style.display = "none";
    }
    const loader = $("#imageLoader");
    if (loader) loader.style.display = "none";
    const errEl = $("#imageError");
    if (errEl) {
      errEl.style.display = "none";
      errEl.textContent = "";
    }
    const idEl = $("#imageModalId");
    if (idEl) idEl.textContent = "";
    const infoEl = $("#imageInfo");
    if (infoEl) infoEl.textContent = "Proporção original preservada • sem cortes";
    const dlBtn = $("#btnDownloadImage");
    if (dlBtn) {
      dlBtn.disabled = true;
      dlBtn.style.opacity = "0.5";
      dlBtn.style.pointerEvents = "none";
      dlBtn.innerHTML = '<span>⬇️</span> Baixar';
    }
    const delBtn = $("#btnDeleteImage");
    if (delBtn) {
      delBtn.disabled = true;
      delBtn.style.opacity = "0.5";
      delBtn.style.pointerEvents = "none";
      delBtn.innerHTML = '<span>🗑️</span> Excluir';
    }
    currentImageData = null;
  }

  function mimeToExt(mime) {
    const map = {
      "image/jpeg": "jpg",
      "image/jpg": "jpg",
      "image/png": "png",
      "image/webp": "webp",
      "image/gif": "gif",
      "image/bmp": "bmp",
      "image/svg+xml": "svg",
      "image/avif": "avif",
    };
    return map[mime] || "jpg";
  }

  function downloadCurrentImage() {
    if (!currentImageData || !currentImageData.b64) return;
    // Prioriza imagem_nome do banco; fallback = solicitacao-{id}.ext
    let filename = currentImageData.filename;
    if (filename && String(filename).trim() !== "") {
      filename = String(filename).trim();
      // Se não tem extensão, adiciona pela mime
      if (!filename.includes(".")) {
        filename += "." + mimeToExt(currentImageData.mime);
      }
    } else {
      const ext = mimeToExt(currentImageData.mime);
      filename = `solicitacao-${currentImageData.id}.${ext}`;
    }
    const link = document.createElement("a");
    link.href = `data:${currentImageData.mime};base64,${currentImageData.b64}`;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
  }

  function complianceStatusLabel(value) {
    if (value === "aprovado") return "Aprovado";
    if (value === "nao_analisado") return "Não analisado";
    if (value === "reprovado") return "Reprovado";
    if (value === "revisar") return "Revisar";
    if (value === "falha") return "Falha";
    return value || "—";
  }

  function publishStatusBadge(value) {
    if (!value) return "—";
    const labels = {
      publish: "Publicado",
      draft: "Rascunho",
      "auto-draft": "Rascunho automático",
      inherit: "Herança",
    };
    return `<span class="status-badge ${escapeHtml(value)}">${escapeHtml(labels[value] || value)}</span>`;
  }

  function periodicPostLink(domainUrl, postId, published) {
    if (!domainUrl || postId == null) return "—";
    const base = String(domainUrl).replace(/\/+$/, "");
    const href = published
      ? `${base}?p=${postId}`
      : `${base}/wp-admin/post.php?post=${postId}&action=edit`;
    const label = published ? "Ver publicado" : "Editar";
    const icon = published ? "🔗" : "📝";
    const variant = published ? "published" : "edit";
    return `<a href="${escapeAttr(href)}" target="_blank" class="table-link ${variant}">${icon} ${label}</a>`;
  }

  function escapeAttr(str) {
    return String(str)
      .replace(/&/g, "&amp;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#39;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;");
  }

  function renderComplianceResumo(text) {
    const raw = String(text || "").trim();
    if (!raw) return "";

    // Normaliza CRLF/LF para '\n' (evita cabeçalhos presos no \r)
    const normalized = raw.replace(/\r\n?/g, "\n");

    // Divide mantendo o cabeçalho capturado; parts[0] = texto antes da 1ª seção
    const parts = normalized.split(/^(\[[^\]]+\]:)/m);
    if (parts.length <= 1) {
      return `<p class="compliance-section-body">${escapeHtml(raw)}</p>`;
    }

    let html = "";

    // Texto introdutório antes da primeira seção (antes era descartado)
    const intro = (parts[0] || "").trim();
    if (intro) {
      html += `<p class="compliance-section-body">${escapeHtml(intro)}</p>`;
    }

    for (let i = 1; i < parts.length; i += 2) {
      const header = (parts[i] || "").trim();
      const label = (header.match(/^\[([^\]]+)\]/) || [])[1] || "Análise";
      const displayLabel = label.replace(/[_\s]+/g, " ").trim() || "Análise";
      const body = (parts[i + 1] || "").trim();
      const bodyHtml = body
        ? escapeHtml(body)
        : '<span style="color:var(--text-muted)">—</span>';
      html += `
        <div class="compliance-section" data-label="${escapeAttr(displayLabel.toLowerCase())}">
          <span class="compliance-label">${escapeHtml(displayLabel)}</span>
          <p class="compliance-section-body">${bodyHtml}</p>
        </div>`;
    }
    return html;
  }

  async function openComplianceModal(id, opts = {}) {
    let r = requests.find((x) => Number(x.id) === Number(id));
    // Dados já vieram no loadAll (resumo_analise na listagem). Modal abre INSTANTÂNEO.
    const resumo = opts.resumo !== undefined ? opts.resumo : r ? r.resumo_analise : "";
    const status = opts.status !== undefined ? opts.status : r ? r.status_compliance : "";

    const el = $("#complianceResumo");
    if (el) el.innerHTML = renderComplianceResumo(resumo || "—");
    const hasCompliance = !!status || (resumo && String(resumo).trim() !== "");
    const btn = $("#btnResetCompliance");
    if (btn) btn.style.display = opts.showReset !== false && hasCompliance ? "" : "none";
    const histContainer = $("#complianceHistoryContainer");
    if (histContainer) histContainer.style.display = "none";
    const histBtn = $("#btnToggleComplianceHistory");
    if (histBtn) histBtn.textContent = "📜 Ver Histórico";
    complianceHistoryProvider = opts.historyRows || (id ? (complianceHistoryCache[id] || null) : null);
    const modalEl = document.getElementById("modalCompliance");
    modalEl.dataset.requestId = id || "";
    // Limpa contexto periódico: garante que modais de requests nunca
    // sejam roteados para a lógica de análise periódica
    modalEl.dataset.periodicKey = "";
    openModal("modalCompliance");
  }

  async function toggleComplianceHistory() {
    const container = $("#complianceHistoryContainer");
    const btn = $("#btnToggleComplianceHistory");
    if (!container || !btn) return;
    const hidden =
      container.style.display === "none" || container.style.display === "";
    if (hidden) {
      if (complianceHistoryProvider) {
        renderComplianceHistoryRows(complianceHistoryProvider);
      } else {
        const id = Number(
          document.getElementById("modalCompliance").dataset.requestId,
        );
        if (!id) return;
        // Cache pré-carregado no login → instantâneo; senão busca sob demanda
        const cached = complianceHistoryCache[id];
        if (cached) {
          renderComplianceHistoryRows(cached);
        } else {
          await loadComplianceHistory(id);
        }
      }
      container.style.display = "block";
      btn.textContent = "📜 Ocultar Histórico";
    } else {
      container.style.display = "none";
      btn.textContent = "📜 Ver Histórico";
    }
  }

  function renderComplianceHistoryRows(rows) {
    const tbody = $("#complianceHistoryBody");
    const emptyEl = $("#complianceHistoryEmpty");
    if (!tbody || !emptyEl) return;
    if (!rows || rows.length === 0) {
      tbody.innerHTML = "";
      emptyEl.style.display = "";
      return;
    }
    emptyEl.style.display = "none";
    tbody.innerHTML = rows
      .map((h) => {
        const status = escapeHtml(h.status_compliance)
          ? `<span class="status-badge ${escapeHtml(h.status_compliance)}">${complianceStatusLabel(h.status_compliance)}</span>`
          : "—";
        return `
        <tr class="compliance-history-row" tabindex="0"
            data-resumo="${escapeAttr(h.resumo_analise || "")}"
            data-status="${escapeAttr(h.status_compliance || "")}"
            data-date="${escapeAttr(formatDateTime(h.created_at))}"
            title="Clique para ver o resumo">
          <td style="white-space:nowrap">${formatDateTime(h.created_at)}</td>
          <td>${status}</td>
          <td style="text-align:center; color:var(--text-muted);">👁</td>
        </tr>`;
      })
      .join("");
  }

  async function loadComplianceHistory(requestId) {
    try {
      const rows = await apiGet(`compliance.php?request_id=${requestId}`);
      renderComplianceHistoryRows(rows);
    } catch (e) {
      renderComplianceHistoryRows([]);
    }
  }

  async function openComplianceModalForPeriodic(key) {
    const [dominio, idPost] = key.split("::");
    let group = periodicAnalysisGroups.find((g) => g.key === key);
    let latest = group ? group.sorted[0] : null;
    let historyRows = group ? group.sorted.slice(1).map((h) => ({
        created_at: h.created_at,
        status_compliance: h.status_compliance,
        resumo_analise: h.resumo_analise,
      })) : [];

    // Se já temos o latest em memória (grupo carregado), abre instantâneo
    if (latest) {
      await openComplianceModal(null, {
        resumo: latest.resumo_analise,
        status: latest.status_compliance,
        historyRows,
        showReset: true,
      });
      const modal = $("#modalCompliance");
      modal.dataset.periodicKey = key;
      modal.dataset.requestId = "";
      return;
    }

    // Sem dados em memória: abre NA HORA com "Carregando..." e busca histórico em background
    if (!latest) {
      await openComplianceModal(null, {
        resumo: "Carregando histórico...",
        status: "",
        historyRows: [],
        showReset: false,
      });
      const modal = $("#modalCompliance");
      modal.dataset.periodicKey = key;
      modal.dataset.requestId = "";
    }

    // Busca histórico lazy sem travar a abertura
    try {
      const rows = await apiGet(`periodic_analysis.php?history=1&dominio=${encodeURIComponent(dominio)}&id_post=${encodeURIComponent(idPost)}`);
      if (rows && rows.length) {
        rows.sort((a, b) => new Date(b.created_at) - new Date(a.created_at));
        latest = rows[0];
        historyRows = rows.slice(1).map((h) => ({
          created_at: h.created_at,
          status_compliance: h.status_compliance,
          resumo_analise: h.resumo_analise,
        }));
        // Preenche o modal já aberto
        const el = $("#complianceResumo");
        if (el) el.innerHTML = renderComplianceResumo(latest.resumo_analise || "—");
        const btn = $("#btnResetCompliance");
        const has2 = !!latest.status_compliance || (latest.resumo_analise && String(latest.resumo_analise).trim() !== "");
        if (btn) btn.style.display = has2 ? "" : "none";
        complianceHistoryProvider = historyRows;
        // Atualiza cache para reuso
        if (!group) {
          group = { key, sorted: rows };
          periodicAnalysisGroups.push(group);
        } else {
          rows.forEach((r) => {
            if (!group.sorted.find((x) => x.id === r.id)) group.sorted.push(r);
          });
          group.sorted.sort((a, b) => new Date(b.created_at) - new Date(a.created_at));
        }
      }
    } catch (e) {
      console.error("Falha ao carregar histórico periódico lazy:", e);
    }
  }

  // HTML padrão do botão — usado para restaurar SEM capturar o estado atual
  const RESET_BTN_DEFAULT_HTML = "<span>↺</span> Analisar Novamente";
  const RESET_BTN_LOADING_HTML = '<span class="spinner"></span> Criando...';

  async function handlePeriodicReanalyze() {
    const modal = $("#modalCompliance");
    const key = modal?.dataset?.periodicKey;
    if (!key) return;

    // Encontrar o grupo da análise periódica
    const group = periodicAnalysisGroups.find((g) => g.key === key);
    if (!group || !group.sorted.length) return;

    // Pegar a análise mais recente (a que está sendo visualizada)
    const latest = group.sorted[0];

    // Preparar dados para o insert
    const payload = {
      action: "reanalyze",
      id_post: latest.id_post,
      post_type: latest.post_type,
      dominio: latest.dominio,
      publish_status: latest.publish_status || "draft", // fallback se não tiver
    };

    // Mostrar loading no botão (com guarda de existência)
    const btn = $("#btnResetCompliance");
    if (btn) {
      btn.disabled = true;
      btn.innerHTML = RESET_BTN_LOADING_HTML;
    }

    let res = null;
    try {
      res = await apiPost("periodic_analysis.php", payload);

      if (!res || !res.success) {
        throw new Error(res?.message || "Resposta inesperada do servidor.");
      }

      console.log("Nova análise periódica criada:", res.id);

      // Só fecha o modal se ainda estiver mostrando ESTE item
      if (modal.dataset.periodicKey === key) {
        closeModal("modalCompliance");
      }
    } catch (err) {
      alert(
        "Erro ao criar nova análise: " +
          (err?.message || "erro desconhecido"),
      );
    } finally {
      // Sempre restaura com HTML FIXO — nunca depende do estado anterior
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = RESET_BTN_DEFAULT_HTML;
      }
    }

    // Atualização silenciosa: não mostra spinner na tabela de solicitações nem na periódica
    // Apenas insere a nova linha no topo sem recarregar tudo com loading visível
    if (!res || !res.id) return;
    try {
      const now = new Date();
      const pad = (n) => String(n).padStart(2, "0");
      const createdAt = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())} ${pad(now.getHours())}:${pad(now.getMinutes())}:${pad(now.getSeconds())}`;
      const newRow = {
        id: res.id,
        id_post: latest.id_post,
        post_type: latest.post_type,
        dominio: latest.dominio,
        dominio_url: latest.dominio_url,
        status_compliance: "nao_analisado",
        resumo_analise: "esperanndo re-analise",
        publish_status: payload.publish_status,
        created_at: createdAt,
      };
      // Atualiza grupo
      group.sorted.unshift(newRow);
      // Verifica filtros atuais da view periódica
      const statusFilter = document.getElementById("filterPeriodicStatus")?.value || "";
      const typeFilter = document.getElementById("filterPeriodicType")?.value || "";
      const domainFilter = document.getElementById("filterPeriodicDomain")?.value || "";
      const matchesFilter =
        (!statusFilter || newRow.status_compliance === statusFilter) &&
        (!typeFilter || newRow.post_type === typeFilter) &&
        (!domainFilter || newRow.dominio === domainFilter);
      const oldIdx = periodicAnalysisVisible.findIndex((r) => `${r.dominio}::${r.id_post}` === key);
      if (oldIdx !== -1) periodicAnalysisVisible.splice(oldIdx, 1);
      if (matchesFilter) {
        periodicAnalysisVisible.unshift(newRow);
        periodicAnalysisVisible.sort((a, b) => new Date(b.created_at) - new Date(a.created_at));
        periodicAnalysisLoaded = Math.min(periodicAnalysisLoaded + 1, periodicAnalysisVisible.length);
        periodicAnalysisTotal = Math.max(periodicAnalysisTotal, periodicAnalysisVisible.length);
        const tbody = document.getElementById("periodicAnalysisBody");
        if (tbody) {
          const currentLoaded = periodicAnalysisLoaded;
          const holder = document.createElement("tbody");
          const toRender = periodicAnalysisVisible.slice(0, currentLoaded);
          holder.innerHTML = toRender.map(periodicRowHtml).join("");
          tbody.innerHTML = "";
          while (holder.firstChild) tbody.appendChild(holder.firstChild);
          const infoEl = document.getElementById("periodicAnalysisInfo");
          if (infoEl) infoEl.textContent = `Mostrando ${currentLoaded} de ${periodicAnalysisTotal} análises`;
          if (currentLoaded < periodicAnalysisTotal) {
            tbody.insertAdjacentHTML("beforeend", periodicSentinelRow());
            if (periodicSentinelObserver) periodicSentinelObserver.disconnect();
            periodicSentinelObserver = new IntersectionObserver(
              (entries) => {
                if (entries.some((e) => e.isIntersecting)) renderPeriodicChunk();
              },
              { rootMargin: PERIODIC_SENTINEL_MARGIN },
            );
            periodicSentinelObserver.observe(document.getElementById("periodicScrollSentinel"));
          } else {
            if (periodicSentinelObserver) {
              periodicSentinelObserver.disconnect();
              periodicSentinelObserver = null;
            }
            const s = document.getElementById("periodicScrollSentinel");
            if (s) s.remove();
          }
          requestAnimationFrame(() => {
            document.querySelectorAll("#periodicAnalysisBody tr").forEach((tr) => {
              const badge = tr.querySelector("[data-key]");
              if (badge && badge.getAttribute("data-key") === key) tr.classList.add("is-focused");
            });
          });
        }
      } else {
        periodicAnalysisTotal = Math.max(periodicAnalysisTotal, periodicAnalysisVisible.length);
        const infoEl = document.getElementById("periodicAnalysisInfo");
        if (infoEl) infoEl.textContent = `Mostrando ${periodicAnalysisLoaded} de ${periodicAnalysisTotal} análises`;
      }
      // Mantém periodicAnalysisAll sincronizado com os groups (fonte da filtragem)
      syncPeriodicAllFromGroups();
    } catch (e) {
      console.error("Falha ao atualizar análise silenciosamente:", e);
      // Fallback silencioso sem spinner visível na tabela de solicitações
      try {
        const statusFilter = document.getElementById("filterPeriodicStatus")?.value || "";
        const typeFilter = document.getElementById("filterPeriodicType")?.value || "";
        const domainFilter = document.getElementById("filterPeriodicDomain")?.value || "";
        const params = new URLSearchParams({ limit: String(PERIODIC_PAGE_SIZE), offset: "0" });
        if (statusFilter) params.set("status", statusFilter);
        if (typeFilter) params.set("post_type", typeFilter);
        if (domainFilter) params.set("dominio", domainFilter);
        const res2 = await apiGet(`periodic_analysis.php?${params.toString()}`);
        const rows = Array.isArray(res2) ? res2 : res2.data || [];
        const total = Array.isArray(res2) ? rows.length : res2.total || 0;
        periodicAnalysisVisible = rows;
        periodicAnalysisLoaded = rows.length;
        periodicAnalysisTotal = total;
        const groups = new Map();
        rows.forEach((r) => {
          const k = `${r.dominio}::${r.id_post ?? ""}`;
          if (!groups.has(k)) groups.set(k, []);
          groups.get(k).push(r);
        });
        periodicAnalysisGroups = [...groups.entries()].map(([k, entries]) => ({
          key: k,
          sorted: [...entries].sort((a, b) => new Date(b.created_at) - new Date(a.created_at)),
        }));
        const tbody = document.getElementById("periodicAnalysisBody");
        if (tbody) {
          tbody.innerHTML = "";
          const holder = document.createElement("tbody");
          holder.innerHTML = rows.map(periodicRowHtml).join("");
          while (holder.firstChild) tbody.appendChild(holder.firstChild);
          const infoEl = document.getElementById("periodicAnalysisInfo");
          if (infoEl) infoEl.textContent = `Mostrando ${rows.length} de ${total} análises`;
          if (rows.length < total) {
            tbody.insertAdjacentHTML("beforeend", periodicSentinelRow());
            if (periodicSentinelObserver) periodicSentinelObserver.disconnect();
            periodicSentinelObserver = new IntersectionObserver(
              (entries) => {
                if (entries.some((e) => e.isIntersecting)) renderPeriodicChunk();
              },
              { rootMargin: PERIODIC_SENTINEL_MARGIN },
            );
            periodicSentinelObserver.observe(document.getElementById("periodicScrollSentinel"));
          }
        }
      } catch (e2) {
        console.error("Fallback silencioso falhou:", e2);
      }
    }
  }

  function updateBulkUI() {
    const count = selectedPeriodicKeys.size;
    const bulkBtn = $("#btnBulkReanalyze");
    const bulkCount = $("#bulkCount");
    const selectAll = $("#periodicSelectAll");
    if (bulkCount) bulkCount.textContent = count;
    if (bulkBtn) {
      if (count === 0) {
        bulkBtn.style.display = "none";
        bulkBtn.disabled = true;
      } else {
        bulkBtn.style.display = "inline-flex";
        bulkBtn.disabled = false;
        bulkBtn.style.opacity = "1";
        bulkBtn.style.pointerEvents = "auto";
      }
    }
    if (selectAll) {
      const visibleKeys = periodicAnalysisVisible.map((r) => `${r.dominio}::${r.id_post}`);
      const allVisibleSelected = visibleKeys.length > 0 && visibleKeys.every((k) => selectedPeriodicKeys.has(k));
      selectAll.checked = allVisibleSelected;
      selectAll.indeterminate = !allVisibleSelected && visibleKeys.some((k) => selectedPeriodicKeys.has(k));
    }
  }

  async function handleBulkReanalyze() {
    if (selectedPeriodicKeys.size === 0) return;
    const keys = Array.from(selectedPeriodicKeys);
    if (!confirm(`Analisar novamente ${keys.length} ${keys.length === 1 ? "item" : "itens"} selecionados?`)) return;
    const btn = $("#btnBulkReanalyze");
    const originalHTML = btn ? btn.innerHTML : "";
    if (btn) {
      btn.disabled = true;
      btn.innerHTML = '<span class="spinner"></span> Criando...';
      btn.style.opacity = "0.5";
      btn.style.pointerEvents = "none";
    }
    let successCount = 0;
    let failCount = 0;
    const concurrency = 3;
    for (let i = 0; i < keys.length; i += concurrency) {
      const chunk = keys.slice(i, i + concurrency);
      const results = await Promise.allSettled(
        chunk.map(async (key) => {
          const [dominio, idPost] = key.split("::");
          let group = periodicAnalysisGroups.find((g) => g.key === key);
          let latest = group ? group.sorted[0] : periodicAnalysisVisible.find((r) => `${r.dominio}::${r.id_post}` === key);
          if (!latest) {
            try {
              const rows = await apiGet(`periodic_analysis.php?history=1&dominio=${encodeURIComponent(dominio)}&id_post=${encodeURIComponent(idPost)}`);
              if (rows && rows.length) latest = rows[0];
            } catch (e) {}
          }
          if (!latest) throw new Error(`Dados não encontrados para ${key}`);
          const payload = {
            action: "reanalyze",
            id_post: latest.id_post,
            post_type: latest.post_type,
            dominio: latest.dominio,
            publish_status: latest.publish_status || "draft",
          };
          const res = await apiPost("periodic_analysis.php", payload);
          if (!res || !res.success) throw new Error(res?.message || "Falha");
          return { key, latest, res };
        }),
      );
      results.forEach((r) => {
        if (r.status === "fulfilled") successCount++;
        else {
          failCount++;
          console.error("Falha ao reanalisar", r.reason);
        }
      });
    }
    selectedPeriodicKeys.clear();
    updateBulkUI();
    const selAll = $("#periodicSelectAll");
    if (selAll) {
      selAll.checked = false;
      selAll.indeterminate = false;
    }
    document.querySelectorAll(".periodic-checkbox").forEach((cb) => (cb.checked = false));
    if (btn) {
      btn.disabled = false;
      btn.innerHTML = originalHTML;
      btn.style.opacity = "0.5";
      btn.style.pointerEvents = "none";
    }
    if (successCount > 0) {
      // Recarrega silenciosamente (sem spinner visível) - mantém dados atuais até novo chegar
      const hadPreviousBulkData = periodicAnalysisVisible.length > 0;
      const previousBulkHTML = hadPreviousBulkData ? document.getElementById("periodicAnalysisBody")?.innerHTML : "";
      try {
        periodicAnalysisVisible = [];
        periodicAnalysisLoaded = 0;
        periodicAnalysisTotal = 0;
        periodicAnalysisGroups = [];
        // Não mostra loading se já tinha dados (silencioso)
        const tbody = document.getElementById("periodicAnalysisBody");
        if (tbody && !hadPreviousBulkData) tbody.innerHTML = `<tr><td colspan="8"><div style="text-align:center; padding:2rem; color:var(--text-muted)"><span class="spinner"></span> Atualizando...</div></td></tr>`;
        // Recarrega do servidor (inclui as novas análises criadas) e reconstrói em memória
        await reloadPeriodicFromServer();
        await fetchNextPeriodicPage();
        const tb = document.getElementById("periodicAnalysisBody");
        if (tb) {
          tb.innerHTML = "";
          const holder = document.createElement("tbody");
          holder.innerHTML = periodicAnalysisVisible.slice(0, Math.min(PERIODIC_PAGE_SIZE, periodicAnalysisVisible.length)).map(periodicRowHtml).join("");
          while (holder.firstChild) tb.appendChild(holder.firstChild);
          periodicAnalysisLoaded = Math.min(PERIODIC_PAGE_SIZE, periodicAnalysisVisible.length);
          const infoEl = document.getElementById("periodicAnalysisInfo");
          if (infoEl) infoEl.textContent = `Mostrando ${periodicAnalysisLoaded} de ${periodicAnalysisTotal} análises`;
          if (periodicAnalysisLoaded < periodicAnalysisTotal) {
            tb.insertAdjacentHTML("beforeend", periodicSentinelRow());
            if (periodicSentinelObserver) periodicSentinelObserver.disconnect();
            periodicSentinelObserver = new IntersectionObserver(
              (entries) => {
                if (entries.some((e) => e.isIntersecting)) renderPeriodicChunk();
              },
              { rootMargin: PERIODIC_SENTINEL_MARGIN },
            );
            periodicSentinelObserver.observe(document.getElementById("periodicScrollSentinel"));
          }
        }
      } catch (e) {
        console.error("Falha ao recarregar após bulk:", e);
        if (hadPreviousBulkData && previousBulkHTML) {
          const tb = document.getElementById("periodicAnalysisBody");
          if (tb) tb.innerHTML = previousBulkHTML;
        }
      }
    }
    if (failCount) {
      alert(`Falha ao reanalisar: ${failCount} erro(s).`);
    }
  }

  function openComplianceHistoryDetail(row) {
    const resumo = row.dataset.resumo || "";
    const status = row.dataset.status || "";
    const date = row.dataset.date || "—";

    const meta = $("#complianceDetailMeta");
    if (meta) {
      meta.innerHTML =
        `<span>${escapeHtml(date)}</span>` +
        (status
          ? `<span class="status-badge ${escapeHtml(status)}">${complianceStatusLabel(status)}</span>`
          : "");
    }

    const body = $("#complianceDetailResumo");
    if (body) {
      body.innerHTML = resumo
        ? renderComplianceResumo(resumo)
        : '<p style="color:var(--text-muted)">—</p>';
    }

    openModal("modalComplianceDetail");
  }

  // ============================================
  //  TRASH VIEW
  // ============================================
  function renderTrash() {
    const tbody = $("#trashTableBody");

    if (deletedRequests.length === 0) {
      tbody.innerHTML = `<tr><td colspan="7"><div class="empty-state"><div class="empty-icon">📭</div><p>Sua lixeira está vazia.</p></div></td></tr>`;
      $("#trashTableInfo").textContent = "Nenhuma solicitação na lixeira";
      return;
    }

    tbody.innerHTML = deletedRequests
      .map((r) => {
        const color = r.color || "#7f5af0";
        const blogName = r.blog_name || "—";
        const writerName = r.writer_name || "A definir";
        const requesterName = r.requester_name || "—";

        // Apenas admin pode fazer hard delete da lixeira (excluir permanentemente)
        const hardDeleteBtn = canDelete()
          ? `<button class="row-action-btn btn-delete-row" data-hard-delete-id="${r.id}" title="Excluir Permanentemente">🗑</button>`
          : "";

        // Recuperar: admin pode recuperar qualquer, os demais apenas os seus
        const canRestore =
          is("admin") || Number(r.requested_by_id) === Number(currentUser.id);
        const restoreBtn = canRestore
          ? `<button class="row-action-btn" data-restore-id="${r.id}" title="Recuperar solicitação" style="color: var(--accent-secondary)">♻️</button>`
          : "";

        const originalStatusText =
          '<span class="status-badge disabled">Cancelado</span>';

        return `
        <tr>
          <td><div class="article-title">${escapeHtml(r.keyword)}</div><span class="article-keyword">${r.wordcount} palavras</span></td>
          <td><div class="blog-name"><span class="blog-dot" style="background:${color}"></span>${escapeHtml(blogName)}</div></td>
          <td>${escapeHtml(requesterName)}</td>
          <td>${escapeHtml(writerName)}</td>
          <td>${originalStatusText}</td>
          <td>${formatDate(r.deadline)}</td>
          <td>
            <div class="row-actions">
              ${restoreBtn}
              ${hardDeleteBtn}
            </div>
          </td>
        </tr>`;
      })
      .join("");

    $("#trashTableInfo").textContent =
      `Mostrando ${deletedRequests.length} solicitações canceladas`;

    tbody.querySelectorAll("[data-restore-id]").forEach((btn) => {
      btn.addEventListener("click", (e) => {
        e.stopPropagation();
        restoreRequest(Number(btn.dataset.restoreId));
      });
    });
    tbody.querySelectorAll("[data-hard-delete-id]").forEach((btn) => {
      btn.addEventListener("click", (e) => {
        e.stopPropagation();
        if (
          confirm(
            "Atenção: A exclusão permanente não pode ser desfeita. Deseja excluir definitivamente esta solicitação?",
          )
        ) {
          hardDeleteRequest(Number(btn.dataset.hardDeleteId));
        }
      });
    });
  }

  async function restoreRequest(id) {
    try {
      await apiPut("requests.php?action=restore", { id });
      // Atualiza ambas as listas (requests e lixeira) sem recarregar tudo
      const [reqData, delData] = await Promise.all([
        apiGet("requests.php"),
        apiGet("requests.php?action=deleted"),
      ]);
      requests = reqData;
      deletedRequests = delData;
      renderTrash();
      updateNotifBadge();
    } catch (err) {
      alert("Erro ao recuperar solicitação: " + err.message);
    }
  }

  // ============================================
  //  PENDENCIES VIEW
  // ============================================
  let currentPendencies = [];

  async function openPendenciesModal(reqId) {
    $("#pendencyReqId").value = reqId;
    $("#pendencyDescription").value = "";
    openModal("modalPendencies");
    await loadPendencies(reqId);
  }

  async function loadPendencies(reqId) {
    const listEl = $("#pendenciesList");
    listEl.innerHTML = '<div class="spinner" style="margin: 20px auto;"></div>';

    try {
      currentPendencies = await apiGet(`pendencies.php?request_id=${reqId}`);
      renderPendenciesList();
    } catch (err) {
      listEl.innerHTML = `<p style="color:var(--accent-danger)">Erro: ${escapeHtml(err.message)}</p>`;
    }
  }

  function renderPendenciesList() {
    const listEl = $("#pendenciesList");

    if (currentPendencies.length === 0) {
      listEl.innerHTML =
        '<p class="text-muted" style="text-align:center; padding: 20px 0;">Nenhuma pendência encontrada.</p>';
      return;
    }

    listEl.innerHTML = currentPendencies
      .map((p) => {
        const isResolved = p.status === "resolved";
        const statusClass = isResolved ? "resolved" : "unresolved";
        const actionText = isResolved
          ? "Reabrir pendência"
          : "Marcar como resolvido";
        const nextStatus = isResolved ? "unresolved" : "resolved";

        return `
        <div class="pendency-item ${statusClass}">
          <div class="pendency-header">
            <strong>${escapeHtml(p.user_name)} (${roleLabel(p.user_role)})</strong>
            <span>${formatDateTime(p.created_at)}</span>
          </div>
          <div class="pendency-body">${escapeHtml(p.description)}</div>
          <div class="pendency-action">
            <button class="btn-toggle-pendency ${statusClass}" onclick="togglePendencyStatus(${p.id}, '${nextStatus}')">
              ${isResolved ? "✅ Resolvido" : "⏳ Não resolvido"} (Clique para alterar)
            </button>
          </div>
        </div>
      `;
      })
      .join("");
  }

  window.togglePendencyStatus = async function (id, newStatus) {
    try {
      await apiPost("pendencies.php", {
        action: "update_status",
        id,
        status: newStatus,
      });
      const reqId = $("#pendencyReqId").value;
      await loadPendencies(reqId);

      // Reload lists in background to update the icon color on dashboard
      await loadAll();
      refreshCurrentView();
    } catch (err) {
      alert("Erro ao atualizar pendência: " + err.message);
    }
  };

  // Ensure modal can be closed directly if bindEvents missed it
  $$("#modalPendencies .modal-close, #modalPendencies .btn-secondary").forEach(
    (btn) => {
      btn.addEventListener("click", () => closeModal("modalPendencies"));
    },
  );

  $("#btnSubmitPendency").addEventListener("click", async (e) => {
    e.preventDefault();
    const reqId = $("#pendencyReqId").value;
    const desc = $("#pendencyDescription").value.trim();
    if (!desc) {
      alert("Digite a descrição da pendência.");
      return;
    }

    const btn = $("#btnSubmitPendency");
    btn.disabled = true;
    btn.textContent = "Adicionando...";

    try {
      await apiPost("pendencies.php", { request_id: reqId, description: desc });
      $("#pendencyDescription").value = "";
      await loadPendencies(reqId);

      await loadAll();
      refreshCurrentView();
    } catch (err) {
      alert("Erro: " + err.message);
    } finally {
      btn.disabled = false;
      btn.textContent = "Adicionar Pendência";
    }
  });

  $("#btnToggleComplianceHistory").addEventListener(
    "click",
    toggleComplianceHistory,
  );

  $("#complianceHistoryBody").addEventListener("click", (e) => {
    const row = e.target.closest("tr.compliance-history-row");
    if (row) openComplianceHistoryDetail(row);
  });

  $("#periodicAnalysisBody").addEventListener("click", (e) => {
    const el = e.target.closest("[data-key]");
    if (el) openComplianceModalForPeriodic(el.dataset.key);
  });

  // Foco visual na linha da análise periódica (sem mudar layout)
  const periodicBodyEl = $("#periodicAnalysisBody");
  if (periodicBodyEl) {
    periodicBodyEl.addEventListener("click", (e) => {
      const tr = e.target.closest("tr");
      if (!tr || tr.id === "periodicScrollSentinel" || !periodicBodyEl.contains(tr)) return;
      periodicBodyEl.querySelectorAll("tr.is-focused").forEach((row) => row.classList.remove("is-focused"));
      tr.classList.add("is-focused");
    });
  }
  document.addEventListener("click", (e) => {
    const body = $("#periodicAnalysisBody");
    const panel = document.getElementById("viewComplianceAnalysis");
    if (!body || !panel || !panel.classList.contains("active")) return;
    // Não limpa se clicou na própria linha, no badge que abre modal, ou dentro de qualquer modal
    if (e.target.closest("#periodicAnalysisBody tr") || e.target.closest("[data-key]")) return;
    if (e.target.closest(".modal-overlay") || e.target.closest(".modal")) return;
    if (document.querySelector(".modal-overlay.active")) return;
    // Botões dentro da view que abrem modal a partir da linha focada (mesmo fora da tabela) não devem limpar
    if (e.target.closest("#viewComplianceAnalysis button") && body.querySelector("tr.is-focused")) return;
    body.querySelectorAll("tr.is-focused").forEach((row) => row.classList.remove("is-focused"));
  });

  // Bulk seleção periódica
  const bulkBtn = $("#btnBulkReanalyze");
  if (bulkBtn) bulkBtn.addEventListener("click", handleBulkReanalyze);
  const selectAll = $("#periodicSelectAll");
  if (selectAll)
    selectAll.addEventListener("change", (e) => {
      // Toggle real: se já há seleção -> limpa TUDO (deselecionar todas de uma vez);
      // se não há -> seleciona todas as linhas carregadas (visíveis e fora da tela).
      const hadSelection = selectedPeriodicKeys.size > 0;
      if (hadSelection) {
        selectedPeriodicKeys.clear();
      } else {
        periodicAnalysisVisible.forEach((r) => {
          selectedPeriodicKeys.add(`${r.dominio}::${r.id_post ?? ""}`);
        });
      }
      // Sincroniza checkboxes e linhas já renderizadas no DOM
      document.querySelectorAll(".periodic-checkbox").forEach((cb) => {
        const k = cb.dataset.periodicKey;
        const tr = cb.closest("tr");
        const on = selectedPeriodicKeys.has(k);
        cb.checked = on;
        if (tr) tr.classList.toggle("is-selected", on);
      });
      updateBulkUI();
      e.target.indeterminate = false;
    });
  const periodicBodyForCheck = document.getElementById("periodicAnalysisBody");
  if (periodicBodyForCheck) {
    periodicBodyForCheck.addEventListener("change", (e) => {
      const cb = e.target.closest(".periodic-checkbox");
      if (!cb) return;
      const k = cb.dataset.periodicKey;
      const tr = cb.closest("tr");
      if (cb.checked) {
        selectedPeriodicKeys.add(k);
        if (tr) tr.classList.add("is-selected");
      } else {
        selectedPeriodicKeys.delete(k);
        if (tr) tr.classList.remove("is-selected");
      }
      updateBulkUI();
      e.stopPropagation();
    });
    // Evita que clique no checkbox também dispare foco na linha
    periodicBodyForCheck.addEventListener("click", (e) => {
      if (e.target.closest(".periodic-checkbox")) e.stopPropagation();
    });
  }

  $("#btnResetCompliance").addEventListener("click", async () => {
    const modal = document.getElementById("modalCompliance");

    // Roteia para a lógica da análise periódica quando aplicável
    if (modal.dataset.periodicKey) {
      try {
        await handlePeriodicReanalyze();
      } catch (e) {
        // handlePeriodicReanalyze já trata os próprios erros internamente;
        // este catch é uma rede de segurança extra
        console.error("Erro inesperado na reanálise periódica:", e);
      }
      return;
    }

    // Lógica original para requests
    const id = Number(modal.dataset.requestId);
    if (!id) return;
    try {
      await apiPut("requests.php?action=reset_compliance", { id });
      closeModal("modalCompliance");
      await loadAll();
      refreshCurrentView();
    } catch (err) {
      alert("Erro ao redefinir compliance: " + err.message);
    }
  });

  // ============================================
  //  USERS VIEW (ADMIN)
  // ============================================
  function renderUsers() {
    const tbody = $("#usersTableBody");
    tbody.innerHTML = users
      .map((u) => {
        const isSelf = u.id == currentUser.id;
        return `
        <tr>
          <td><strong>${escapeHtml(u.name)}</strong></td>
          <td>${escapeHtml(u.email)}</td>
          <td><span class="role-tag ${u.role}">${roleLabel(u.role)}</span></td>
          <td><span class="status-badge ${u.active ? "done" : "pending"}">${u.active ? "Ativo" : "Inativo"}</span></td>
          <td>
            <div class="row-actions">
              <button class="row-action-btn" data-edit-user="${u.id}" title="Editar">✏️</button>
              ${!isSelf ? `<button class="row-action-btn btn-delete-row" data-delete-user="${u.id}" title="Excluir">🗑</button>` : ""}
            </div>
          </td>
        </tr>`;
      })
      .join("");

    tbody.querySelectorAll("[data-edit-user]").forEach((btn) => {
      btn.addEventListener("click", () =>
        openEditUser(Number(btn.dataset.editUser)),
      );
    });
    tbody.querySelectorAll("[data-delete-user]").forEach((btn) => {
      btn.addEventListener("click", () =>
        deleteUser(Number(btn.dataset.deleteUser)),
      );
    });
  }

  // ============================================
  //  DOMAINS VIEW (ADMIN)
  // ============================================
  function renderDomains() {
    const isAdmin = is("admin");
    const tbody = $("#domainsTableBody");
    tbody.innerHTML = domains
      .map(
        (d) => `
      <tr>
        <td><div class="blog-name"><span class="blog-dot" style="background:${d.color}"></span><strong>${escapeHtml(d.blog_name)}</strong></div></td>
        <td><a href="${escapeHtml(d.url)}" target="_blank" style="color:var(--accent-info);text-decoration:none">${escapeHtml(d.url)}</a></td>
        <td>${escapeHtml(d.niche)}</td>
        <td style="text-align:center;">
          <label style="cursor:${isAdmin ? "pointer" : "not-allowed"}; display:inline-flex; align-items:center; justify-content:center;">
            <input type="checkbox" data-automacao-id="${d.id}" ${Number(d.automacao_imagem) ? "checked" : ""} ${isAdmin ? "" : "disabled"} title="${isAdmin ? "Ativar/desativar automação de imagem" : "Apenas admins podem alterar"}" style="width:18px; height:18px; accent-color:var(--accent-primary); cursor:${isAdmin ? "pointer" : "not-allowed"};">
          </label>
        </td>
        <td><span class="status-badge ${d.active ? "done" : "pending"}">${d.active ? "Ativo" : "Inativo"}</span></td>
        <td>
          <div class="row-actions">
            <button class="row-action-btn" data-edit-domain="${d.id}" title="Editar">✏️</button>
            <button class="row-action-btn btn-delete-row" data-delete-domain="${d.id}" title="Excluir">🗑</button>
          </div>
        </td>
      </tr>`,
      )
      .join("");

    tbody.querySelectorAll("[data-edit-domain]").forEach((btn) => {
      btn.addEventListener("click", () =>
        openEditDomain(Number(btn.dataset.editDomain)),
      );
    });
    tbody.querySelectorAll("[data-delete-domain]").forEach((btn) => {
      btn.addEventListener("click", () =>
        deleteDomain(Number(btn.dataset.deleteDomain)),
      );
    });
    tbody.querySelectorAll("[data-automacao-id]").forEach((chk) => {
      chk.addEventListener("change", async (e) => {
        if (!isAdmin) {
          e.preventDefault();
          chk.checked = !chk.checked;
          return;
        }
        const id = Number(chk.dataset.automacaoId);
        const newVal = chk.checked ? 1 : 0;
        chk.disabled = true;
        try {
          await apiPut("domains.php", { id, automacao_imagem: newVal });
          const dom = domains.find((x) => Number(x.id) === id);
          if (dom) dom.automacao_imagem = newVal;
        } catch (err) {
          chk.checked = !chk.checked;
          alert("Erro ao atualizar automação: " + err.message);
        } finally {
          chk.disabled = false;
        }
      });
    });
  }

  // ============================================
  //  MODALS
  // ============================================
  function openModal(id) {
    $(`#${id}`).classList.add("active");
    document.body.style.overflow = "hidden";
  }

  function closeModal(id) {
    $(`#${id}`).classList.remove("active");
    document.body.style.overflow = "";
    if (id === "modalImage") clearImageModal();
  }

  function closeAllModals() {
    const wasImageOpen = $("#modalImage")?.classList.contains("active");
    $$(".modal-overlay.active").forEach((m) => {
      m.classList.remove("active");
    });
    document.body.style.overflow = "";
    if (wasImageOpen) clearImageModal();
  }

  // ---- Populate select elements ----
  function populateRequestSelects(blogSelectId, writerSelectId, prefix = "") {
    const blogSelect = $(`#${blogSelectId}`);
    const writerSelect = $(`#${writerSelectId}`);

    blogSelect.innerHTML = '<option value="">Selecione...</option>';
    domains
      .filter((d) => d.active)
      .forEach((d) => {
        blogSelect.innerHTML += `<option value="${d.id}">${escapeHtml(d.blog_name)}</option>`;
      });

    writerSelect.innerHTML = '<option value="">Auto-atribuir</option>';
    const writerList =
      users.length > 0
        ? users.filter((u) => u.role === "redator" && u.active)
        : [];
    writerList.forEach((u) => {
      writerSelect.innerHTML += `<option value="${u.id}">${escapeHtml(u.name)}</option>`;
    });

    // Populate language select
    const langSelectId = prefix ? `${prefix}SelectLanguage` : "selectLanguage";
    const langSelect = $(`#${langSelectId}`);
    if (langSelect) {
      langSelect.innerHTML = '<option value="">Selecione...</option>';
      languages
        .filter((l) => l.active)
        .forEach((l) => {
          langSelect.innerHTML += `<option value="${escapeHtml(l.code)}">${escapeHtml(l.name)}</option>`;
        });
    }

    // Populate niche select
    const nicheSelectId = prefix ? `${prefix}SelectNiche` : "selectNiche";
    const nicheSelect = $(`#${nicheSelectId}`);
    if (nicheSelect) {
      nicheSelect.innerHTML = '<option value="">Selecione...</option>';
      niches
        .filter((n) => n.active)
        .forEach((n) => {
          nicheSelect.innerHTML += `<option value="${n.id}">${escapeHtml(n.name)}</option>`;
        });
    }
  }

  // ---- New Request ----
  function openNewRequest() {
    if (!canCreate()) return;
    // Load users for writer select (admins and gestors need it)
    if (users.length === 0 && !is("redator")) {
      apiGet("users.php")
        .then((data) => {
          users = data;
          populateRequestSelects("selectBlog", "selectWriter");
        })
        .catch(() => {
          populateRequestSelects("selectBlog", "selectWriter");
        });
    } else {
      populateRequestSelects("selectBlog", "selectWriter");
    }
    $("#formNewRequest").reset();
    const deadlineInput =
      $("#formNewRequest").querySelector('[name="deadline"]');
    const d = new Date();
    d.setDate(d.getDate() + 7);
    deadlineInput.value = d.toISOString().split("T")[0];

    // Hide writer select for redators (auto-assigned by backend)
    const writerGroup = $("#selectWriter")?.closest(".form-group");
    if (writerGroup) writerGroup.style.display = is("redator") ? "none" : "";

    openModal("modalNew");
  }

  let _submitting = false;

  async function submitRequest() {
    if (_submitting) return;
    const form = $("#formNewRequest");
    if (!form.checkValidity()) {
      form.reportValidity();
      return;
    }

    _submitting = true;
    $("#btnSubmitRequest").disabled = true;
    const fd = new FormData(form);
    try {
      await apiPost("requests.php", {
        keyword: fd.get("keyword"),
        domainId: Number(fd.get("blog")),
        writerId: fd.get("writer") ? Number(fd.get("writer")) : null,
        priority: fd.get("priority"),
        wordcount: fd.get("wordcount"),
        deadline: fd.get("deadline"),
        language: fd.get("language"),
        niche_id: fd.get("niche_id") ? Number(fd.get("niche_id")) : null,
        purpose: fd.get("purpose"),
        content_type: fd.get("content_type"),
        instructions: fd.get("instructions") || "",
      });

      closeModal("modalNew");
      await loadAll();
      refreshCurrentView();
    } catch (err) {
      alert("Erro ao criar: " + err.message);
    } finally {
      _submitting = false;
      $("#btnSubmitRequest").disabled = false;
    }
  }

  // ---- Edit Request (Gestor / Admin) ----
  function openEditRequest(id) {
    const r = requests.find((req) => req.id == id);
    if (!r || !canEdit(r)) return;

    const doFill = () => fillEditForm(r);
    if (users.length === 0) {
      apiGet("users.php")
        .then((data) => {
          users = data;
          doFill();
        })
        .catch(() => doFill());
    } else {
      doFill();
    }

    // Instruções são lazy (não vêm na listagem). Se ainda não carregadas,
    // busca em background e preenche o campo SEM travar a abertura do modal (evita perder dados ao salvar).
    if (r.instructions === undefined) {
      apiGet(`requests.php?action=detail&id=${id}`)
        .then((detail) => {
          Object.assign(r, detail);
          const f = document.querySelector('#formEditRequest [name="instructions"]');
          if (f && document.getElementById("modalEdit")?.style.display !== "none") {
            f.value = r.instructions || "";
          }
        })
        .catch(() => {});
    }
  }

  function fillEditForm(r) {
    populateRequestSelects("editSelectBlog", "editSelectWriter", "edit");

    const form = $("#formEditRequest");
    $("#editRequestId").value = r.id;
    form.querySelector('[name="keyword"]').value = r.keyword;
    form.querySelector('[name="blog"]').value = r.domain_id;
    form.querySelector('[name="writer"]').value = r.writer_id || "";
    form.querySelector('[name="wordcount"]').value = r.wordcount;
    form.querySelector('[name="priority"]').value = r.priority;
    form.querySelector('[name="deadline"]').value = r.deadline;
    form.querySelector('[name="language"]').value = r.language || "";
    form.querySelector('[name="niche_id"]').value = r.niche_id || "";
    form.querySelector('[name="purpose"]').value = r.purpose || "conteudo";
    form.querySelector('[name="content_type"]').value =
      r.content_type || "artigo";
    form.querySelector('[name="instructions"]').value = r.instructions || "";

    openModal("modalEdit");
  }

  async function submitEditRequest() {
    if (_submitting) return;
    const form = $("#formEditRequest");
    if (!form.checkValidity()) {
      form.reportValidity();
      return;
    }

    _submitting = true;
    $("#btnSubmitEdit").disabled = true;
    const editId = Number($("#editRequestId").value);
    const fd = new FormData(form);

    try {
      await apiPut("requests.php", {
        id: editId,
        keyword: fd.get("keyword"),
        domainId: Number(fd.get("blog")),
        writerId: fd.get("writer") ? Number(fd.get("writer")) : null,
        priority: fd.get("priority"),
        wordcount: fd.get("wordcount"),
        deadline: fd.get("deadline"),
        language: fd.get("language"),
        niche_id: fd.get("niche_id") ? Number(fd.get("niche_id")) : null,
        purpose: fd.get("purpose"),
        content_type: fd.get("content_type"),
        instructions: fd.get("instructions") || "",
      });

      closeModal("modalEdit");
      await loadAll();
      refreshCurrentView();
    } catch (err) {
      alert("Erro ao editar: " + err.message);
    } finally {
      _submitting = false;
      $("#btnSubmitEdit").disabled = false;
    }
  }

  // ---- Detail (dados already em memória: resumo/instructions/histórico pré-carregados) ----
  async function openDetail(id) {
    const r = requests.find((req) => req.id == id) || deletedRequests.find((req) => req.id == id);
    if (!r) return;

    // Tudo já veio no loadAll: resumo_analise/instructions na listagem e histórico em cache.
    // Modal abre INSTANTÂNEO, sem nenhuma chamada de rede.
    r.history = requestHistoryCache[id] || [];
    renderDetailModal(r);
    openModal("modalDetail");
    return;
  }

  function renderDetailModal(r) {
    const blogName = r.blog_name || "—";
    const writerName = r.writer_name || "A definir";
    const requesterName = r.requester_name || "—";

    // Build status steps — everyone sees all steps, but 'revisado' is disabled for non-admins
    const statuses = [
      "pending",
      "in-progress",
      "review",
      "done",
      "published",
      "revisado",
    ];
    const sLabels = {
      pending: "Pendente",
      "in-progress": "Em Produção",
      review: "Em Revisão",
      done: "Concluído",
      published: "Publicado",
      revisado: "Revisado",
    };

    const currentIdx = statuses.indexOf(r.status);
    const canChange = canChangeStatus(r);

    const stepsHtml = statuses
      .map((s, i) => {
        let cls =
          s === "published"
            ? "published-step"
            : s === "revisado"
              ? "revisado-step"
              : "";
        if (i < currentIdx) cls += " completed";
        else if (i === currentIdx) cls += " current";

        let stepDisabled = !canChange;
        if (s === "revisado" && !is("admin") && !is("revisor")) {
          stepDisabled = true;
        }

        if (stepDisabled) cls += " disabled";
        const arrow =
          i < statuses.length - 1 ? '<span class="status-arrow">→</span>' : "";
        return `<span class="status-step ${cls}" data-status="${s}" data-req-id="${r.id}">${sLabels[s]}</span>${arrow}`;
      })
      .join("");

    const historyHtml = r.history === undefined
      ? `<div class="detail-section" id="detailHistoryContainer"><h4>📜 Histórico de Alterações</h4><div style="text-align:center; padding:1rem; color:var(--text-muted)"><span class="spinner"></span> Carregando histórico...</div></div>`
      : buildHistoryHtml(r);

    const publishedLinkHtml =
      r.status === "published" && r.published_url
        ? `<div class="detail-section">
           <h4>Link Publicado</h4>
           <div class="published-link">
             <span>🔗</span>
             <a href="${escapeHtml(r.published_url)}" target="_blank">${escapeHtml(r.published_url)}</a>
           </div>
         </div>`
        : "";

    // WP Edit URL — show for done, revisado, or published statuses
    const wpEditLinkHtml =
      ["done", "revisado", "published"].includes(r.status) && r.wp_edit_url
        ? `<div class="detail-section">
           <h4>Link de Edição WordPress</h4>
           <div class="wp-edit-link">
             <span>📝</span>
             <a href="${escapeHtml(r.wp_edit_url)}" target="_blank">${escapeHtml(r.wp_edit_url)}</a>
           </div>
         </div>`
        : "";

    const editBtnHtml = canEdit(r)
      ? `<button class="btn-primary" id="detailEditBtn" data-edit-id="${r.id}" style="margin-right:auto"><span>✏️</span> Editar</button>`
      : "";

    const langObj = languages.find((l) => l.code === r.language);
    const langLabel = langObj ? langObj.name : r.language || "—";
    const purposeLabel =
      { conteudo: "Conteúdo", arbitragem: "Arbitragem" }[r.purpose] ||
      r.purpose ||
      "—";
    const typeLabel =
      { artigo: "Artigo", pagina: "Página" }[r.content_type] ||
      r.content_type ||
      "—";
    const nicheObj = niches.find((n) => n.id == r.niche_id);
    const nicheName = nicheObj ? nicheObj.name : "—";

    $("#detailContent").innerHTML = `
      <div class="detail-section">
        <h4>Palavra-chave</h4>
        <div style="font-size:1.1rem;font-weight:700;margin-bottom:4px">${escapeHtml(r.keyword)}</div>
        <span class="article-keyword">${r.wordcount} palavras</span>
      </div>
      <div class="detail-section">
        <h4>Informações</h4>
        <div class="detail-grid">
          <div class="detail-item"><div class="detail-label">Blog Destino</div><div class="detail-value">${escapeHtml(blogName)}</div></div>
          <div class="detail-item"><div class="detail-label">Redator</div><div class="detail-value">${escapeHtml(writerName)}</div></div>
          <div class="detail-item"><div class="detail-label">Solicitado por</div><div class="detail-value">${escapeHtml(requesterName)}</div></div>
          <div class="detail-item"><div class="detail-label">Prioridade</div><div class="detail-value"><span class="priority-indicator ${r.priority}"><span class="priority-dot"></span>${priorityLabel(r.priority)}</span></div></div>
          <div class="detail-item"><div class="detail-label">Prazo</div><div class="detail-value">${formatDate(r.deadline)}</div></div>
          <div class="detail-item"><div class="detail-label">Criado em</div><div class="detail-value">${formatDate(r.created_at ? r.created_at.split(" ")[0] : "")}</div></div>
          <div class="detail-item"><div class="detail-label">Idioma</div><div class="detail-value">${langLabel}</div></div>
          <div class="detail-item"><div class="detail-label">Nicho</div><div class="detail-value">${escapeHtml(nicheName)}</div></div>
          <div class="detail-item"><div class="detail-label">Finalidade</div><div class="detail-value">${purposeLabel}</div></div>
          <div class="detail-item"><div class="detail-label">Tipo</div><div class="detail-value">${typeLabel}</div></div>
          <div class="detail-item"><div class="detail-label">Imagem</div><div class="detail-value">${Number(r.has_imagem) ? '🖼️ Com imagem' : '— Sem imagem'}</div></div>
        </div>
      </div>
      ${r.instructions ? `<div class="detail-section"><h4>Instruções</h4><div class="detail-instructions">${escapeHtml(r.instructions)}</div></div>` : ""}
      ${wpEditLinkHtml}
      ${publishedLinkHtml}
      <div class="detail-section">
        <h4>Fluxo de Status ${canChange ? '<span style="font-size:0.65rem;color:var(--text-muted);text-transform:none;letter-spacing:0">(clique para alterar)</span>' : ""}</h4>
        <div class="detail-status-flow">${stepsHtml}</div>
      </div>
      ${historyHtml}`;

    if (canChange) {
      $("#detailContent")
        .querySelectorAll(".status-step:not(.disabled)")
        .forEach((step) => {
          step.addEventListener("click", () => {
            const targetStatus = step.dataset.status;
            const reqId = Number(step.dataset.reqId);
            if (targetStatus === "published") {
              openPublishModal(reqId);
            } else if (targetStatus === "done") {
              openDoneModal(reqId);
            } else {
              updateRequestStatus(reqId, targetStatus);
            }
          });
        });
    }

    const editBtnEl = $("#detailContent").querySelector("#detailEditBtn");
    if (editBtnEl) {
      editBtnEl.addEventListener("click", () => {
        closeModal("modalDetail");
        openEditRequest(Number(editBtnEl.dataset.editId));
      });
    }

    const footerEl = $("#modalDetail").querySelector(".modal-footer");
    footerEl.innerHTML = `${editBtnHtml}<button class="btn-secondary" data-close="modalDetail">Fechar</button>`;
    footerEl.querySelectorAll("[data-close]").forEach((btn) => {
      btn.addEventListener("click", () => closeModal(btn.dataset.close));
    });
    const footerEditBtn = footerEl.querySelector("#detailEditBtn");
    if (footerEditBtn) {
      footerEditBtn.addEventListener("click", () => {
        closeModal("modalDetail");
        openEditRequest(Number(footerEditBtn.dataset.editId));
      });
    }
  }

  function buildHistoryHtml(r) {
    if (!r.history || r.history.length === 0) return "";

    const actionLabels = {
      create: "📋 Criou a solicitação",
      edit: "✏️ Editou a solicitação",
      status_change: "🔄 Alterou o status",
      published: "🚀 Publicou o artigo",
    };

    const items = [...r.history]
      .reverse()
      .map((h) => {
        let changesHtml = "";
        const changes = h.changes || [];

        if (h.action === "edit" && changes.length > 0) {
          changesHtml = changes
            .map((c) => {
              const fl = fieldLabel(c.field);
              const fromVal =
                c.field === "status" ? statusLabel(c.from) : c.from || "—";
              const toVal =
                c.field === "status" ? statusLabel(c.to) : c.to || "—";
              return `<div class="history-change"><span class="field-name">${escapeHtml(fl)}:</span> <span class="old-val">${escapeHtml(String(fromVal))}</span> → <span class="new-val">${escapeHtml(String(toVal))}</span></div>`;
            })
            .join("");
        }

        if (h.action === "status_change" && changes.length > 0) {
          const c = changes[0];
          changesHtml = `<div class="history-change"><span class="old-val">${escapeHtml(statusLabel(c.from))}</span> → <span class="new-val">${escapeHtml(statusLabel(c.to))}</span></div>`;
        }

        if (h.action === "published" && h.url) {
          changesHtml = `<div class="history-change"><a href="${escapeHtml(h.url)}" target="_blank" style="color:#00d2be;font-size:0.72rem;text-decoration:none">${escapeHtml(h.url)}</a></div>`;
        }

        const userName = h.user_name || "Sistema";

        return `
        <div class="history-item action-${h.action}">
          <div class="history-header">
            <span class="history-user">${escapeHtml(userName)}</span>
            <span class="history-date">${formatDateTime(h.created_at)}</span>
          </div>
          <div class="history-action">
            ${actionLabels[h.action] || h.action}
            ${changesHtml}
          </div>
        </div>`;
      })
      .join("");

    return `
      <div class="detail-section">
        <h4>📜 Histórico de Alterações</h4>
        <div class="history-timeline">${items}</div>
      </div>`;
  }

  async function updateRequestStatus(id, newStatus, extraData = {}) {
    try {
      await apiPut("requests.php?action=status", {
        id,
        status: newStatus,
        ...extraData,
      });
      await loadAll();
      refreshCurrentView();
      openDetail(id);
    } catch (err) {
      alert("Erro: " + err.message);
    }
  }

  async function deleteRequest(id) {
    const r = requests.find((req) => req.id == id);
    if (!r) return;
    const canSoftDelete =
      is("admin") ||
      is("revisor") ||
      is("redator") ||
      (r.status === "pending" && r.requested_by_id == currentUser.id);
    if (!canSoftDelete) return;
    if (!confirm("Mover esta solicitação para a lixeira?")) return;
    try {
      // Always soft delete from requests page (force=0)
      await apiDelete(`requests.php?id=${id}&force=0`);
      await loadAll();
      refreshCurrentView();
    } catch (err) {
      alert("Erro: " + err.message);
    }
  }

  async function hardDeleteRequest(id) {
    if (!canDelete()) return; // Only true admins can hard delete
    try {
      await apiDelete(`requests.php?id=${id}&force=1`);
      deletedRequests = await apiGet("requests.php?action=deleted");
      refreshCurrentView();
    } catch (err) {
      alert("Erro: " + err.message);
    }
  }

  // ============================================
  //  PUBLISH WITH LINK VALIDATION
  // ============================================
  function openPublishModal(reqId) {
    const r = requests.find((req) => req.id == reqId);
    if (!r) return;

    if (r.status !== "done") {
      alert(
        'O artigo precisa estar com status "Concluído" antes de ser publicado.',
      );
      return;
    }

    $("#publishRequestId").value = reqId;
    $("#publishUrl").value = "";
    $("#publishUrl").className = "publish-url-input";

    const domainUrl = r.domain_url || "";
    if (domainUrl) {
      try {
        const expectedHost = new URL(domainUrl).hostname;
        $("#expectedHost").textContent = expectedHost;
      } catch {
        $("#expectedHost").textContent = domainUrl;
      }
    } else {
      $("#expectedHost").textContent = "(domínio não encontrado)";
    }

    const statusEl = $("#publishStatus");
    statusEl.className = "publish-status";
    statusEl.classList.remove("show");
    $("#publishStatusText").textContent = "";
    $("#publishSpinner").style.display = "";
    $("#btnSubmitPublish").disabled = false;

    closeModal("modalDetail");
    openModal("modalPublish");
  }

  // ============================================
  //  DONE STATUS WITH WP EDIT URL
  // ============================================
  function openDoneModal(reqId) {
    const r = requests.find((req) => req.id == reqId);
    if (!r) return;

    $("#doneRequestId").value = reqId;
    $("#doneWpUrl").value = "";
    $("#doneWpUrl").className = "publish-url-input";

    const domainUrl = r.domain_url || "";
    if (domainUrl) {
      try {
        const expectedHost = new URL(domainUrl).hostname;
        $("#doneExpectedHost").textContent = expectedHost;
      } catch {
        $("#doneExpectedHost").textContent = domainUrl;
      }
    } else {
      $("#doneExpectedHost").textContent = "(domínio não encontrado)";
    }

    const statusEl = $("#doneStatus");
    statusEl.className = "publish-status";
    statusEl.classList.remove("show");
    $("#doneStatusText").textContent = "";
    $("#btnSubmitDone").disabled = false;

    closeModal("modalDetail");
    openModal("modalDone");
  }

  async function submitDone() {
    const reqId = Number($("#doneRequestId").value);
    const urlInput = $("#doneWpUrl");
    const url = urlInput.value.trim();
    const statusEl = $("#doneStatus");
    const statusText = $("#doneStatusText");
    const submitBtn = $("#btnSubmitDone");

    if (!url) {
      urlInput.focus();
      return;
    }

    // Client-side URL validation
    try {
      new URL(url);
    } catch {
      statusEl.className = "publish-status show error";
      statusText.textContent =
        "URL inválida. Insira uma URL completa (ex: https://...) ";
      urlInput.className = "publish-url-input invalid";
      return;
    }

    // Client-side hostname check
    const r = requests.find((req) => req.id == reqId);
    if (r && r.domain_url) {
      try {
        const expectedHost = new URL(r.domain_url).hostname;
        const providedHost = new URL(url).hostname;
        if (expectedHost !== providedHost) {
          statusEl.className = "publish-status show error";
          statusText.textContent = `Domínio "${providedHost}" não corresponde ao esperado "${expectedHost}".`;
          urlInput.className = "publish-url-input invalid";
          return;
        }
      } catch {
        /* ignore */
      }
    }

    statusEl.className = "publish-status show loading";
    statusText.textContent = "Validando...";
    submitBtn.disabled = true;
    urlInput.className = "publish-url-input";

    try {
      await updateRequestStatus(reqId, "done", { wp_edit_url: url });
      closeModal("modalDone");
    } catch (err) {
      statusEl.className = "publish-status show error";
      statusText.textContent = err.message;
      submitBtn.disabled = false;
      urlInput.className = "publish-url-input invalid";
    }
  }

  async function submitPublish() {
    const reqId = Number($("#publishRequestId").value);
    const urlInput = $("#publishUrl");
    const url = urlInput.value.trim();
    const statusEl = $("#publishStatus");
    const statusText = $("#publishStatusText");
    const spinner = $("#publishSpinner");
    const submitBtn = $("#btnSubmitPublish");

    if (!url) {
      urlInput.focus();
      return;
    }

    // Client-side URL validation
    let parsedUrl;
    try {
      parsedUrl = new URL(url);
    } catch {
      showPublishError("URL inválida. Por favor, insira uma URL completa.");
      urlInput.className = "publish-url-input invalid";
      return;
    }

    statusEl.className = "publish-status show loading";
    spinner.style.display = "";
    statusText.textContent = "Validando link...";
    submitBtn.disabled = true;
    urlInput.className = "publish-url-input";

    try {
      await apiPut("requests.php?action=publish", { id: reqId, url });

      statusEl.className = "publish-status show success";
      spinner.style.display = "none";
      statusText.textContent = "✅ Artigo publicado com sucesso!";
      urlInput.className = "publish-url-input valid";

      setTimeout(async () => {
        closeModal("modalPublish");
        await loadAll();
        refreshCurrentView();
      }, 1500);
    } catch (err) {
      showPublishError(err.message);
      submitBtn.disabled = false;
    }
  }

  function showPublishError(msg) {
    const statusEl = $("#publishStatus");
    const spinner = $("#publishSpinner");
    const statusText = $("#publishStatusText");
    statusEl.className = "publish-status show error";
    spinner.style.display = "none";
    statusText.textContent = "❌ " + msg;
  }

  // ---- User CRUD (admin) ----
  function openNewUser() {
    $("#modalUserTitle").textContent = "👤 Novo Usuário";
    $("#formUser").reset();
    $("#userEditId").value = "";
    const pwField = $("#formUser").querySelector('[name="password"]');
    pwField.required = true;
    pwField.placeholder = "Mínimo 6 caracteres";
    openModal("modalUser");
  }

  function openEditUser(id) {
    const u = users.find((usr) => usr.id == id);
    if (!u) return;
    $("#modalUserTitle").textContent = "✏️ Editar Usuário";
    const form = $("#formUser");
    form.querySelector('[name="name"]').value = u.name;
    form.querySelector('[name="email"]').value = u.email;
    form.querySelector('[name="role"]').value = u.role;
    const pwField = form.querySelector('[name="password"]');
    pwField.required = false;
    pwField.value = "";
    pwField.placeholder = "Deixe vazio para manter";
    $("#userEditId").value = u.id;
    openModal("modalUser");
  }

  async function submitUser() {
    if (_submitting) return;
    const form = $("#formUser");
    if (!form.checkValidity()) {
      form.reportValidity();
      return;
    }

    _submitting = true;
    $("#btnSubmitUser").disabled = true;
    const fd = new FormData(form);
    const editId = Number($("#userEditId").value);

    try {
      if (editId) {
        const data = {
          id: editId,
          name: fd.get("name"),
          email: fd.get("email"),
          role: fd.get("role"),
        };
        if (fd.get("password")) data.password = fd.get("password");
        await apiPut("users.php", data);
      } else {
        await apiPost("users.php", {
          name: fd.get("name"),
          email: fd.get("email"),
          password: fd.get("password"),
          role: fd.get("role"),
        });
      }
      closeModal("modalUser");
      users = await apiGet("users.php");
      renderUsers();
    } catch (err) {
      alert("Erro: " + err.message);
    } finally {
      _submitting = false;
      $("#btnSubmitUser").disabled = false;
    }
  }

  async function deleteUser(id) {
    if (id == currentUser.id) {
      alert("Você não pode excluir sua própria conta.");
      return;
    }
    if (!confirm("Tem certeza que deseja excluir este usuário?")) return;
    try {
      await apiDelete(`users.php?id=${id}`);
      users = await apiGet("users.php");
      renderUsers();
    } catch (err) {
      alert("Erro: " + err.message);
    }
  }

  // ---- Domain CRUD (admin) ----
  function openNewDomain() {
    $("#modalDomainTitle").textContent = "🌐 Novo Domínio";
    $("#formDomain").reset();
    $("#domainEditId").value = "";
    openModal("modalDomain");
  }

  function openEditDomain(id) {
    const d = domains.find((dom) => dom.id == id);
    if (!d) return;
    $("#modalDomainTitle").textContent = "✏️ Editar Domínio";
    const form = $("#formDomain");
    form.querySelector('[name="blogName"]').value = d.blog_name;
    form.querySelector('[name="url"]').value = d.url;
    form.querySelector('[name="niche"]').value = d.niche;
    form.querySelector('[name="active"]').value = d.active ? "true" : "false";
    $("#domainEditId").value = d.id;
    openModal("modalDomain");
  }

  async function submitDomain() {
    if (_submitting) return;
    const form = $("#formDomain");
    if (!form.checkValidity()) {
      form.reportValidity();
      return;
    }

    _submitting = true;
    $("#btnSubmitDomain").disabled = true;
    const fd = new FormData(form);
    const editId = Number($("#domainEditId").value);

    const colors = [
      "#7f5af0",
      "#2cb67d",
      "#39a0ed",
      "#f0a500",
      "#e53170",
      "#ff6b6b",
      "#48bfe3",
      "#f72585",
    ];

    try {
      if (editId) {
        await apiPut("domains.php", {
          id: editId,
          blogName: fd.get("blogName"),
          url: fd.get("url"),
          niche: fd.get("niche"),
          active: fd.get("active") === "true",
        });
      } else {
        await apiPost("domains.php", {
          blogName: fd.get("blogName"),
          url: fd.get("url"),
          niche: fd.get("niche"),
          active: fd.get("active") === "true",
          color: colors[Math.floor(Math.random() * colors.length)],
        });
      }
      closeModal("modalDomain");
      domains = await apiGet("domains.php");
      renderDomains();
    } catch (err) {
      alert("Erro: " + err.message);
    } finally {
      _submitting = false;
      $("#btnSubmitDomain").disabled = false;
    }
  }

  async function deleteDomain(id) {
    if (!confirm("Tem certeza que deseja excluir este domínio?")) return;
    try {
      await apiDelete(`domains.php?id=${id}`);
      domains = await apiGet("domains.php");
      renderDomains();
    } catch (err) {
      alert("Erro: " + err.message);
    }
  }

  // ============================================
  //  THEME TOGGLE
  // ============================================
  function applyThemeFromPrefs(prefs) {
    const theme = prefs?.theme || "dark";
    document.documentElement.setAttribute("data-theme", theme);
    const btn = $("#themeToggle");
    if (btn) btn.textContent = theme === "dark" ? "☀️" : "🌙";
  }

  async function toggleTheme() {
    const current =
      document.documentElement.getAttribute("data-theme") || "dark";
    const next = current === "dark" ? "light" : "dark";
    document.documentElement.setAttribute("data-theme", next);
    const btn = $("#themeToggle");
    if (btn) btn.textContent = next === "dark" ? "☀️" : "🌙";

    // Save to API
    try {
      await apiPut("preferences.php", { theme: next });
    } catch (e) {
      /* silent */
    }
  }

  // ============================================
  //  LANGUAGES VIEW (ADMIN)
  // ============================================
  function renderLanguages() {
    const tbody = $("#languagesTableBody");
    tbody.innerHTML = languages
      .map(
        (l) => `
      <tr>
        <td><strong>${escapeHtml(l.name)}</strong></td>
        <td><code>${escapeHtml(l.code)}</code></td>
        <td><span class="status-badge ${l.active ? "done" : "pending"}">${l.active ? "Ativo" : "Inativo"}</span></td>
        <td>
          <div class="row-actions">
            <button class="row-action-btn" data-edit-lang="${l.id}" title="Editar">✏️</button>
            <button class="row-action-btn btn-delete-row" data-delete-lang="${l.id}" title="Excluir">🗑</button>
          </div>
        </td>
      </tr>`,
      )
      .join("");

    tbody.querySelectorAll("[data-edit-lang]").forEach((btn) => {
      btn.addEventListener("click", () =>
        openEditLanguage(Number(btn.dataset.editLang)),
      );
    });
    tbody.querySelectorAll("[data-delete-lang]").forEach((btn) => {
      btn.addEventListener("click", () =>
        deleteLanguage(Number(btn.dataset.deleteLang)),
      );
    });
  }

  function openNewLanguage() {
    $("#languageModalTitle").textContent = "Novo Idioma";
    $("#languageId").value = "";
    $("#formLanguage").reset();
    openModal("modalLanguage");
  }

  function openEditLanguage(id) {
    const l = languages.find((x) => x.id == id);
    if (!l) return;
    $("#languageModalTitle").textContent = "Editar Idioma";
    $("#languageId").value = l.id;
    const form = $("#formLanguage");
    form.querySelector('[name="name"]').value = l.name;
    form.querySelector('[name="code"]').value = l.code;
    form.querySelector('[name="active"]').value = l.active ? "1" : "0";
    openModal("modalLanguage");
  }

  async function submitLanguage() {
    if (_submitting) return;
    const form = $("#formLanguage");
    if (!form.checkValidity()) {
      form.reportValidity();
      return;
    }

    _submitting = true;
    $("#btnSubmitLanguage").disabled = true;
    const fd = new FormData(form);
    const id = $("#languageId").value;
    const data = {
      name: fd.get("name"),
      code: fd.get("code"),
      active: fd.get("active") === "1",
    };
    try {
      if (id) {
        data.id = Number(id);
        await apiPut("languages.php", data);
      } else {
        await apiPost("languages.php", data);
      }
      closeModal("modalLanguage");
      await loadAll();
      renderLanguages();
    } catch (err) {
      alert("Erro: " + err.message);
    } finally {
      _submitting = false;
      $("#btnSubmitLanguage").disabled = false;
    }
  }

  async function deleteLanguage(id) {
    if (!confirm("Excluir este idioma?")) return;
    try {
      await apiDelete(`languages.php?id=${id}`);
      await loadAll();
      renderLanguages();
    } catch (err) {
      alert("Erro: " + err.message);
    }
  }

  // ============================================
  //  NICHES VIEW (ADMIN)
  // ============================================
  function renderNiches() {
    const tbody = $("#nichesTableBody");
    tbody.innerHTML = niches
      .map(
        (n) => `
      <tr>
        <td><strong>${escapeHtml(n.name)}</strong></td>
        <td><span class="status-badge ${n.active ? "done" : "pending"}">${n.active ? "Ativo" : "Inativo"}</span></td>
        <td>
          <div class="row-actions">
            <button class="row-action-btn" data-edit-niche="${n.id}" title="Editar">✏️</button>
            <button class="row-action-btn btn-delete-row" data-delete-niche="${n.id}" title="Excluir">🗑</button>
          </div>
        </td>
      </tr>`,
      )
      .join("");

    tbody.querySelectorAll("[data-edit-niche]").forEach((btn) => {
      btn.addEventListener("click", () =>
        openEditNiche(Number(btn.dataset.editNiche)),
      );
    });
    tbody.querySelectorAll("[data-delete-niche]").forEach((btn) => {
      btn.addEventListener("click", () =>
        deleteNiche(Number(btn.dataset.deleteNiche)),
      );
    });
  }

  function openNewNiche() {
    $("#nicheModalTitle").textContent = "Novo Nicho";
    $("#nicheId").value = "";
    $("#formNiche").reset();
    openModal("modalNiche");
  }

  function openEditNiche(id) {
    const n = niches.find((x) => x.id == id);
    if (!n) return;
    $("#nicheModalTitle").textContent = "Editar Nicho";
    $("#nicheId").value = n.id;
    const form = $("#formNiche");
    form.querySelector('[name="name"]').value = n.name;
    form.querySelector('[name="active"]').value = n.active ? "1" : "0";
    openModal("modalNiche");
  }

  async function submitNiche() {
    if (_submitting) return;
    const form = $("#formNiche");
    if (!form.checkValidity()) {
      form.reportValidity();
      return;
    }

    _submitting = true;
    $("#btnSubmitNiche").disabled = true;
    const fd = new FormData(form);
    const id = $("#nicheId").value;
    const data = { name: fd.get("name"), active: fd.get("active") === "1" };
    try {
      if (id) {
        data.id = Number(id);
        await apiPut("niches.php", data);
      } else {
        await apiPost("niches.php", data);
      }
      closeModal("modalNiche");
      await loadAll();
      renderNiches();
    } catch (err) {
      alert("Erro: " + err.message);
    } finally {
      _submitting = false;
      $("#btnSubmitNiche").disabled = false;
    }
  }

  async function deleteNiche(id) {
    if (!confirm("Excluir este nicho?")) return;
    try {
      await apiDelete(`niches.php?id=${id}`);
      await loadAll();
      renderNiches();
    } catch (err) {
      alert("Erro: " + err.message);
    }
  }

  // ============================================
  //  NOTIFICATIONS
  // ============================================
  function updateNotifBadge() {
    const unread = notifications.filter((n) => !n.is_read && !n.read).length;
    const badge = $("#notifCount");
    if (badge) {
      badge.textContent = unread > 0 ? unread : "";
      badge.setAttribute("data-count", unread);
    }
  }

  function renderNotifDropdown() {
    const list = $("#notifList");
    if (notifications.length === 0) {
      list.innerHTML = '<div class="notif-empty">🔔 Nenhuma notificação</div>';
      return;
    }
    const sorted = [...notifications].sort(
      (a, b) => new Date(b.created_at) - new Date(a.created_at),
    );
    list.innerHTML = sorted
      .slice(0, 20)
      .map(
        (n) => `
      <div class="notif-item ${!n.is_read && !n.read ? "unread" : ""}" data-notif-id="${n.id}">
        <div class="notif-item-body">
          <div class="notif-item-text">${escapeHtml(n.message)}</div>
          <div class="notif-item-time">${formatDateTime(n.created_at)}</div>
        </div>
      </div>
    `,
      )
      .join("");

    list.querySelectorAll(".notif-item").forEach((el) => {
      el.addEventListener("click", async () => {
        const nId = Number(el.dataset.notifId);
        const notif = notifications.find((n) => n.id == nId);
        if (notif) {
          try {
            await apiPut("notifications.php", { id: nId });
            notif.is_read = 1;
            notif.read = true;
            updateNotifBadge();
            renderNotifDropdown();
            if (notif.related_id && notif.type !== "new_message") {
              closeNotifDropdown();
              openDetail(notif.related_id);
            } else if (notif.type === "new_message") {
              closeNotifDropdown();
              navigateTo("messages");
            }
          } catch (e) {
            /* silent */
          }
        }
      });
    });
  }

  function toggleNotifDropdown() {
    const dd = $("#notifDropdown");
    const isOpen = dd.classList.toggle("open");
    if (isOpen) renderNotifDropdown();
  }

  function closeNotifDropdown() {
    $("#notifDropdown").classList.remove("open");
  }

  async function markAllNotifsRead() {
    try {
      await apiPut("notifications.php?action=read_all", {});
      notifications.forEach((n) => {
        n.is_read = 1;
        n.read = true;
      });
      updateNotifBadge();
      renderNotifDropdown();
    } catch (e) {
      /* silent */
    }
  }

  // ============================================
  //  MESSAGES
  // ============================================
  function updateMsgBadge() {
    const unread = notifications.filter(
      (n) => n.type === "new_message" && !n.is_read && !n.read,
    ).length;
    // Also count from messages
    const badge = $("#navBadgeMessages");
    if (badge) badge.textContent = unread > 0 ? unread : "0";
  }

  // ============================================
  //  LOGS VIEW
  // ============================================
  async function renderLogs() {
    const dateInput = $("#filterLogDate");
    const userSelect = $("#filterLogUser");
    const userGroup = $("#filterLogUserGroup");

    // Set default date to today if empty
    if (!dateInput.value) {
      dateInput.value = today();
    }

    let filterUser = userSelect.value;

    // Admin sees all users, revisor sees only themselves
    if (!is("admin")) {
      if (userGroup) userGroup.style.display = "none";
      filterUser = currentUser.id;
    } else {
      if (userGroup) userGroup.style.display = "flex";
      // Populate user filter for admins
      const currentVal = userSelect.value;
      userSelect.innerHTML = '<option value="">Todos os Usuários</option>';
      users.forEach((u) => {
        userSelect.innerHTML += `<option value="${u.id}" ${u.id == currentVal ? "selected" : ""}>${escapeHtml(u.name)} (${roleLabel(u.role)})</option>`;
      });
      filterUser = userSelect.value;
    }

    const filterDate = dateInput.value;

    let url = `logs.php?date=${filterDate}`;
    if (filterUser) url += `&user_id=${filterUser}`;

    try {
      const logs = await apiGet(url);
      const tbody = $("#logsTableBody");
      const emptyEl = $("#logsEmpty");

      if (logs.length === 0) {
        tbody.innerHTML = "";
        emptyEl.style.display = "";
        return;
      }
      emptyEl.style.display = "none";

      const actionLabels = {
        status_change: "Alteração de Status",
        edit: "Edição",
        published: "Publicação",
        created: "Criação",
      };

      tbody.innerHTML = logs
        .map((log) => {
          const dateStr = formatDateTime(log.created_at);
          const userName = log.user_name || "—";
          const userRole = log.user_role
            ? ` <span class="role-tag ${log.user_role}">${roleLabel(log.user_role)}</span>`
            : "";
          const keyword = log.keyword || "—";
          const blogName = log.blog_name || "—";
          const action = actionLabels[log.action] || log.action;

          // Extract status from/to from changes
          let statusFrom = "—";
          let statusTo = "—";
          const changes = log.changes || [];
          const statusChange = changes.find((c) => c.field === "status");
          if (statusChange) {
            statusFrom = `<span class="status-badge ${statusChange.from}">${statusLabel(statusChange.from)}</span>`;
            statusTo = `<span class="status-badge ${statusChange.to}">${statusLabel(statusChange.to)}</span>`;
          } else if (log.action === "edit") {
            const fields = changes.map((c) => fieldLabel(c.field)).join(", ");
            statusFrom = fields || "—";
            statusTo = "—";
          }

          return `
          <tr>
            <td><span style="white-space:nowrap">${dateStr}</span></td>
            <td>${escapeHtml(userName)}${userRole}</td>
            <td>${escapeHtml(keyword)}</td>
            <td>${escapeHtml(blogName)}</td>
            <td>${action}</td>
            <td>${statusFrom}</td>
            <td>${statusTo}</td>
          </tr>`;
        })
        .join("");
    } catch (e) {
      console.error("Erro ao carregar logs:", e);
    }
  }

  // ============================================
  //  COMPLIANCE ANALYSIS VIEW (ADMIN)
  // ============================================
  function periodicRowHtml(r) {
    const status = r.status_compliance || "";
    const hasResumo =
      r.resumo_analise && String(r.resumo_analise).trim() !== "";
    const key = `${r.dominio}::${r.id_post ?? ""}`;
    const d = domains.find((x) => x.blog_name && x.blog_name === r.dominio);
    const domainColor = d ? d.color : "#7f5af0";
    const typeLabel =
      { post: "Post", page: "Página" }[r.post_type] || r.post_type;
    const typeBadge = typeLabel
      ? `<span class="type-badge ${escapeHtml(r.post_type)}">${escapeHtml(typeLabel)}</span>`
      : "—";
    const dateStr = formatDateTime(r.created_at);
    const isSelected = selectedPeriodicKeys.has(key);
    return `
      <tr data-periodic-key="${escapeAttr(key)}" class="${isSelected ? "is-selected" : ""}">
        <td style="white-space:nowrap; position:relative; padding-left:28px;">
          <input type="checkbox" class="periodic-checkbox row-hover-checkbox" data-periodic-key="${escapeAttr(key)}" ${isSelected ? "checked" : ""}>
          <span class="periodic-date">${dateStr}</span>
        </td>
        <td><div class="blog-name"><span class="blog-dot" style="background:${domainColor}"></span>${escapeHtml(r.dominio)}</div></td>
        <td>${r.id_post != null ? r.id_post : "—"}</td>
        <td>${typeBadge}</td>
        <td>${
          status
            ? `<span class="status-badge ${escapeHtml(status)}${hasResumo ? " compliance-clickable" : ""}" ${hasResumo ? `data-key="${escapeAttr(key)}" title="Ver resumo e histórico da análise"` : ""}>${complianceStatusLabel(status)}</span>`
            : "—"
        }</td>
        <td>${publishStatusBadge(r.publish_status)}</td>
        <td>${periodicPostLink(r.dominio_url, r.id_post, true)}</td>
        <td>${periodicPostLink(r.dominio_url, r.id_post, false)}</td>
      </tr>`;
  }

  function periodicSentinelRow() {
    return `<tr id="periodicScrollSentinel" class="scroll-sentinel"><td colspan="8"><div class="scroll-sentinel-inner"><span class="spinner"></span> Carregando mais análises…</div></td></tr>`;
  }

  // Reconstrói a lista visível filtrada a partir do cache em memória (sem rede).
  // periodicAnalysisAll = latest row por grupo; filtros aplicados client-side.
  async function fetchNextPeriodicPage() {
    const statusFilter = $("#filterPeriodicStatus")?.value || "";
    const typeFilter = $("#filterPeriodicType")?.value || "";
    const domainFilter = $("#filterPeriodicDomain")?.value || "";
    const filtered = periodicAnalysisAll.filter(
      (r) =>
        (!statusFilter || r.status_compliance === statusFilter) &&
        (!typeFilter || r.post_type === typeFilter) &&
        (!domainFilter || r.dominio === domainFilter),
    );
    periodicAnalysisVisible = filtered;
    periodicAnalysisTotal = filtered.length;
    return filtered;
  }

  async function renderPeriodicChunk() {
    const tbody = $("#periodicAnalysisBody");
    if (!tbody) return;

    // Se já tem dados em Visible não renderizados, renderiza chunk local
    if (periodicAnalysisLoaded < periodicAnalysisVisible.length) {
      const sentinel = document.getElementById("periodicScrollSentinel");
      if (sentinel) sentinel.remove();
      const chunk = periodicAnalysisVisible.slice(periodicAnalysisLoaded, periodicAnalysisLoaded + PERIODIC_PAGE_SIZE);
      const holder = document.createElement("tbody");
      holder.innerHTML = chunk.map(periodicRowHtml).join("");
      while (holder.firstChild) tbody.appendChild(holder.firstChild);
      periodicAnalysisLoaded += chunk.length;
      $("#periodicAnalysisInfo").textContent = `Mostrando ${periodicAnalysisLoaded} de ${periodicAnalysisTotal} análises`;
      updateBulkUI();
      if (periodicAnalysisLoaded < periodicAnalysisTotal) {
        tbody.insertAdjacentHTML("beforeend", periodicSentinelRow());
        if (periodicSentinelObserver) periodicSentinelObserver.disconnect();
        periodicSentinelObserver = new IntersectionObserver(
          (entries) => {
            if (entries.some((e) => e.isIntersecting)) renderPeriodicChunk();
          },
          { rootMargin: PERIODIC_SENTINEL_MARGIN },
        );
        periodicSentinelObserver.observe(document.getElementById("periodicScrollSentinel"));
      } else {
        if (periodicSentinelObserver) {
          periodicSentinelObserver.disconnect();
          periodicSentinelObserver = null;
        }
        const s = document.getElementById("periodicScrollSentinel");
        if (s) s.remove();
      }
      return;
    }

    // Se Visible esgotado mas ainda há mais no backend, busca próxima página (imperceptível)
    if (periodicAnalysisLoaded < periodicAnalysisTotal) {
      const sentinel = document.getElementById("periodicScrollSentinel");
      if (sentinel) sentinel.remove();
      tbody.insertAdjacentHTML("beforeend", periodicSentinelRow());
      try {
        await fetchNextPeriodicPage();
        const s2 = document.getElementById("periodicScrollSentinel");
        if (s2) s2.remove();
        // Recursivo: agora tem dados em Visible, renderiza
        renderPeriodicChunk();
      } catch (e) {
        console.error("Erro ao paginar análises:", e);
        const s2 = document.getElementById("periodicScrollSentinel");
        if (s2) s2.remove();
      }
      return;
    }

    // Fim
    if (periodicSentinelObserver) {
      periodicSentinelObserver.disconnect();
      periodicSentinelObserver = null;
    }
    const sentinel = document.getElementById("periodicScrollSentinel");
    if (sentinel) sentinel.remove();
  }

  async function renderComplianceAnalysis(opts = {}) {
    const tbody = $("#periodicAnalysisBody");
    if (!tbody) return;
    // Garante dados em memória (preload em loadAll ou fallback de rede, uma única vez)
    await ensurePeriodicLoaded();
    const hadPreviousData = periodicAnalysisVisible.length > 0 && tbody.querySelectorAll("tr").length > 0 && !tbody.querySelector("#periodicScrollSentinel");
    const previousHTML = hadPreviousData ? tbody.innerHTML : "";
    // Reset paginação (NÃO zera groups — groups são a fonte em memória).
    // Seleção só é limpa em carga explícita do usuário; em refresh de fundo é preservada.
    periodicAnalysisVisible = [];
    periodicAnalysisLoaded = 0;
    periodicAnalysisTotal = 0;
    if (!opts.preserveSelection) selectedPeriodicKeys.clear();
    updateBulkUI();
    if (periodicSentinelObserver) {
      periodicSentinelObserver.disconnect();
      periodicSentinelObserver = null;
    }
    // Silencioso se já tinha dados (filtro): mantém tabela visível, sem spinner
    if (!hadPreviousData) {
      tbody.innerHTML = `<tr><td colspan="8"><div style="text-align:center; padding:2rem; color:var(--text-muted)"><span class="spinner"></span> Carregando análises...</div></td></tr>`;
    }
    try {
      // Filtros distintos derivados do cache em memória (sem rede)
      const domainSelect = $("#filterPeriodicDomain");
      const currentDomain = domainSelect.value;
      const typeSelect = $("#filterPeriodicType");
      const currentType = typeSelect.value;
      const domainOpts = periodicDomainOptionsCache || [...new Set(periodicAnalysisAll.map((r) => r.dominio))].filter(Boolean).sort();
      const typeOpts = periodicTypeOptionsCache || [...new Set(periodicAnalysisAll.map((r) => r.post_type))].filter(Boolean).sort();
      domainSelect.innerHTML = '<option value="">Todos Domínios</option>' + domainOpts.sort((a, b) => a.localeCompare(b)).map((d) => `<option value="${escapeHtml(d)}">${escapeHtml(d)}</option>`).join("");
      domainSelect.value = currentDomain;
      const typeLabels = { post: "Post", page: "Página" };
      typeSelect.innerHTML = '<option value="">Todos Tipos</option>' + typeOpts.sort((a, b) => a.localeCompare(b)).map((t) => `<option value="${escapeHtml(t)}">${escapeHtml(typeLabels[t] || t)}</option>`).join("");
      typeSelect.value = currentType;

      // Constrói lista visível filtrada em memória (instantâneo)
      await fetchNextPeriodicPage();
      if (!periodicAnalysisVisible.length) {
        tbody.innerHTML = `<tr><td colspan="8"><div class="empty-state"><div class="empty-icon">📭</div><p>Nenhuma análise encontrada.</p></div></td></tr>`;
        $("#periodicAnalysisInfo").textContent = "Nenhuma análise";
        return;
      }
      if (hadPreviousData) {
        // Silencioso: substitui sem mostrar vazio/spinner, usuário nem percebe recarregando
        const holder = document.createElement("tbody");
        const toRender = periodicAnalysisVisible.slice(0, Math.min(PERIODIC_PAGE_SIZE, periodicAnalysisVisible.length));
        holder.innerHTML = toRender.map(periodicRowHtml).join("");
        tbody.innerHTML = "";
        while (holder.firstChild) tbody.appendChild(holder.firstChild);
        periodicAnalysisLoaded = toRender.length;
        const infoEl = document.getElementById("periodicAnalysisInfo");
        if (infoEl) infoEl.textContent = `Mostrando ${periodicAnalysisLoaded} de ${periodicAnalysisTotal} análises`;
        if (periodicAnalysisLoaded < periodicAnalysisTotal) {
          tbody.insertAdjacentHTML("beforeend", periodicSentinelRow());
          if (periodicSentinelObserver) periodicSentinelObserver.disconnect();
          periodicSentinelObserver = new IntersectionObserver(
            (entries) => {
              if (entries.some((e) => e.isIntersecting)) renderPeriodicChunk();
            },
            { rootMargin: PERIODIC_SENTINEL_MARGIN },
          );
          periodicSentinelObserver.observe(document.getElementById("periodicScrollSentinel"));
        } else {
          if (periodicSentinelObserver) {
            periodicSentinelObserver.disconnect();
            periodicSentinelObserver = null;
          }
        }
        updateBulkUI();
      } else {
        tbody.innerHTML = "";
        renderPeriodicChunk();
      }
    } catch (e) {
      console.error("Erro ao carregar análises periódicas:", e);
      if (hadPreviousData && previousHTML) {
        tbody.innerHTML = previousHTML;
      } else {
        tbody.innerHTML = `<tr><td colspan="8"><div style="text-align:center; padding:2rem; color:var(--accent-danger)">Erro ao carregar</div></td></tr>`;
      }
    }
  }

  async function renderMessages() {
    const listEl = $("#msgListBody");
    try {
      const visible = await apiGet(`messages.php?tab=${currentMsgTab}`);
      messages = visible;

      if (visible.length === 0) {
        listEl.innerHTML =
          '<div class="empty-state"><div class="empty-icon">📭</div><p>Nenhuma mensagem.</p></div>';
        $("#msgInfo").textContent = "Nenhuma mensagem";
        return;
      }

      listEl.innerHTML = visible
        .map((m) => {
          const isInbox = currentMsgTab === "inbox";
          const otherName = isInbox ? m.from_name || "—" : m.to_name || "—";
          const unread = isInbox && !m.is_read;
          return `
          <div class="msg-item ${unread ? "unread" : ""}" data-msg-id="${m.id}">
            <div class="msg-avatar">${getInitials(otherName)}</div>
            <div class="msg-body">
              <div class="msg-from">${isInbox ? "De" : "Para"}: ${escapeHtml(otherName)}</div>
              <div class="msg-subject">${escapeHtml(m.subject)}</div>
              <div class="msg-preview">${escapeHtml(m.body.substring(0, 80))}${m.body.length > 80 ? "..." : ""}</div>
            </div>
            <div class="msg-time">${formatDateTime(m.created_at)}</div>
            ${unread ? '<div class="msg-unread-dot"></div>' : ""}
          </div>`;
        })
        .join("");

      $("#msgInfo").textContent =
        `${visible.length} mensagen${visible.length === 1 ? "" : "s"}`;

      listEl.querySelectorAll(".msg-item").forEach((el) => {
        el.addEventListener("click", () =>
          openMsgDetail(Number(el.dataset.msgId)),
        );
      });
    } catch (e) {
      listEl.innerHTML =
        '<div class="empty-state"><div class="empty-icon">⚠️</div><p>Erro ao carregar mensagens.</p></div>';
    }
  }

  async function openMsgDetail(id) {
    const m = messages.find((msg) => msg.id == id);
    if (!m) return;

    // Mark as read
    if (currentMsgTab === "inbox" && !m.is_read) {
      try {
        await apiPut("messages.php", { id });
        m.is_read = 1;
        await renderMessages();
        notifications = await apiGet("notifications.php");
        updateNotifBadge();
        updateMsgBadge();
      } catch (e) {
        /* silent */
      }
    }

    const fromName = m.from_name || "—";
    const toName = m.to_name || "—";

    const content = $("#msgDetailContent");
    content.innerHTML = `
      <div class="msg-detail-header">
        <div class="msg-detail-subject">${escapeHtml(m.subject)}</div>
        <div class="msg-detail-meta">
          <span><strong>De:</strong> ${escapeHtml(fromName)}</span>
          <span><strong>Para:</strong> ${escapeHtml(toName)}</span>
          <span>${formatDateTime(m.created_at)}</span>
        </div>
      </div>
      <div class="msg-detail-body">${escapeHtml(m.body)}</div>
    `;

    const replyBtn = $("#btnReplyMessage");
    replyBtn.onclick = () => {
      closeModal("modalMsgDetail");
      openCompose(m.from_id, `Re: ${m.subject}`);
    };

    openModal("modalMsgDetail");
  }

  async function openCompose(toId, subject) {
    // Load users for compose select if needed
    let allUsers = users;
    if (allUsers.length === 0) {
      try {
        allUsers = await apiGet("users.php");
        users = allUsers;
      } catch (e) {
        allUsers = [];
      }
    }

    const select = $("#composeToId");
    select.innerHTML =
      '<option value="">Selecione...</option>' +
      allUsers
        .filter((u) => u.active && u.id != currentUser.id)
        .map(
          (u) =>
            `<option value="${u.id}" ${u.id == toId ? "selected" : ""}>${escapeHtml(u.name)} (${roleLabel(u.role)})</option>`,
        )
        .join("");

    const form = $("#formCompose");
    form.reset();
    if (toId) select.value = toId;
    if (subject) form.querySelector('[name="subject"]').value = subject;

    openModal("modalCompose");
  }

  async function sendMessage() {
    const form = $("#formCompose");
    if (!form.checkValidity()) {
      form.reportValidity();
      return;
    }

    const fd = new FormData(form);
    try {
      await apiPost("messages.php", {
        toId: Number(fd.get("toId")),
        subject: fd.get("subject"),
        body: fd.get("body"),
      });

      closeModal("modalCompose");
      notifications = await apiGet("notifications.php");
      updateNotifBadge();
      updateMsgBadge();
      refreshCurrentView();
    } catch (err) {
      alert("Erro: " + err.message);
    }
  }

  // ============================================
  //  REFRESH
  // ============================================
  function refreshCurrentView() {
    const active = $(".nav-link.active[data-view]");
    const view = active ? active.dataset.view : "dashboard";
    if (view === "dashboard") renderDashboard();
    if (view === "requests") renderRequests();
    if (view === "users") renderUsers();
    if (view === "domains") renderDomains();
    if (view === "messages") renderMessages();
    if (view === "compliance-analysis") renderComplianceAnalysis();
  }

  // ============================================
  //  HEADER DATE
  // ============================================
  function setHeaderDate() {
    const now = new Date();
    const options = {
      weekday: "long",
      day: "numeric",
      month: "long",
      year: "numeric",
    };
    $("#headerDate").textContent = now.toLocaleDateString("pt-BR", options);
  }

  // ============================================
  //  EVENT BINDING
  // ============================================
  function bindEvents() {
    // Login
    $("#loginForm").addEventListener("submit", handleLogin);
    $("#btnLogout").addEventListener("click", (e) => {
      e.preventDefault();
      handleLogout();
    });

    // Navigation
    $$(".nav-link[data-view]").forEach((link) => {
      link.addEventListener("click", (e) => {
        e.preventDefault();
        navigateTo(link.dataset.view);
      });
    });

    // Dashboard stat cards: clicar leva à tela Solicitações com filtro de status
    $$(".stat-card[data-stat-filter]").forEach((card) => {
      card.addEventListener("click", () => {
        const filter = card.getAttribute("data-stat-filter") || "";
        navigateTo("requests", { statusFilter: filter });
      });
    });

    // New request buttons
    $("#btnNewRequestDash").addEventListener("click", openNewRequest);
    $("#btnNewRequest").addEventListener("click", openNewRequest);
    $("#btnSubmitRequest").addEventListener("click", (e) => {
      e.preventDefault();
      submitRequest();
    });

    // Edit request
    $("#btnSubmitEdit").addEventListener("click", (e) => {
      e.preventDefault();
      submitEditRequest();
    });

    // Publish
    $("#btnSubmitPublish").addEventListener("click", (e) => {
      e.preventDefault();
      submitPublish();
    });
    // Done (WP Edit URL)
    $("#btnSubmitDone").addEventListener("click", (e) => {
      e.preventDefault();
      submitDone();
    });

    // User CRUD
    $("#btnNewUser").addEventListener("click", openNewUser);
    $("#btnSubmitUser").addEventListener("click", (e) => {
      e.preventDefault();
      submitUser();
    });

    // Domain CRUD
    $("#btnNewDomain").addEventListener("click", openNewDomain);
    $("#btnSubmitDomain").addEventListener("click", (e) => {
      e.preventDefault();
      submitDomain();
    });

    // Filters
    $("#filterStatus").addEventListener("change", renderRequests);
    $("#filterPriority").addEventListener("change", renderRequests);
    $("#filterBlog").addEventListener("change", renderRequests);
    $("#filterWriter").addEventListener("change", renderRequests);
    $("#filterRequester").addEventListener("change", renderRequests);

    // Log filters
    $("#filterLogDate").addEventListener("change", renderLogs);
    $("#filterLogUser").addEventListener("change", renderLogs);

    // Periodic analysis filters
    $("#filterPeriodicStatus").addEventListener(
      "change",
      renderComplianceAnalysis,
    );
    $("#filterPeriodicType").addEventListener(
      "change",
      renderComplianceAnalysis,
    );
    $("#filterPeriodicDomain").addEventListener(
      "change",
      renderComplianceAnalysis,
    );

    let searchTimeout;
    $("#globalSearch").addEventListener("input", () => {
      clearTimeout(searchTimeout);
      searchTimeout = setTimeout(() => {
        const active = $(".nav-link.active[data-view]");
        if (active && active.dataset.view === "requests") renderRequests();
      }, 250);
    });

    // Close buttons for all modals
    $$("[data-close]").forEach((btn) => {
      btn.addEventListener("click", () => closeModal(btn.dataset.close));
    });

    // Download / Delete image (modal imagem)
    const dlBtn = $("#btnDownloadImage");
    if (dlBtn) dlBtn.addEventListener("click", downloadCurrentImage);
    const delImgBtn = $("#btnDeleteImage");
    if (delImgBtn) delImgBtn.addEventListener("click", deleteCurrentImage);

    // Overlay clicks
    $$(".modal-overlay").forEach((overlay) => {
      overlay.addEventListener("click", (e) => {
        if (e.target === overlay) closeAllModals();
      });
    });

    // Escape key
    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape") {
        closeAllModals();
        closeNotifDropdown();
      }
    });

    // Mobile toggle
    $("#mobileToggle").addEventListener("click", () => {
      $("#sidebar").classList.toggle("open");
    });

    // Theme toggle
    $("#themeToggle").addEventListener("click", toggleTheme);

    // Languages CRUD
    $("#btnNewLanguage").addEventListener("click", openNewLanguage);
    $("#btnSubmitLanguage").addEventListener("click", (e) => {
      e.preventDefault();
      submitLanguage();
    });

    // Niches CRUD
    $("#btnNewNiche").addEventListener("click", openNewNiche);
    $("#btnSubmitNiche").addEventListener("click", (e) => {
      e.preventDefault();
      submitNiche();
    });

    // Notification bell
    $("#notifBell").addEventListener("click", (e) => {
      e.stopPropagation();
      toggleNotifDropdown();
    });
    $("#notifMarkAll").addEventListener("click", markAllNotifsRead);

    // Close notif dropdown on outside click
    document.addEventListener("click", (e) => {
      if (!e.target.closest(".notif-wrapper")) {
        closeNotifDropdown();
      }
    });

    // Messages
    $$("[data-msg-tab]").forEach((tab) => {
      tab.addEventListener("click", () => {
        currentMsgTab = tab.dataset.msgTab;
        $$("[data-msg-tab]").forEach((t) => t.classList.remove("active"));
        tab.classList.add("active");
        renderMessages();
      });
    });
    $("#btnCompose").addEventListener("click", () => openCompose());
    $("#btnSendMessage").addEventListener("click", (e) => {
      e.preventDefault();
      sendMessage();
    });
  }

  // ============================================
  //  INIT
  // ============================================
  function init() {
    bindEvents();
    initLogin();
    const verEl = $("#appVersion");
    if (verEl) verEl.textContent = `v${APP_VERSION}`;
  }

  document.addEventListener("DOMContentLoaded", init);
})();
