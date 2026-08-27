# ArticleHub — Arquitetura do Sistema

> **Objetivo**: Documento de referência rápida para humanos e IAs sobre a estrutura completa do sistema. Leia este arquivo ANTES de explorar o código.
> **Atualizado**: 2026-08-27 — reflete lazy load, imagem BLOB, automacao_imagem, request_pendencies e paginação periódica.

---

## Visão Geral

ArticleHub é um sistema de gestão de solicitações de artigos para blogs/sites. Gestores criam pedidos, redatores produzem, revisores validam e o conteúdo é publicado via WordPress.

**Stack**: PHP puro (API REST) + JavaScript Vanilla (SPA) + MySQL 8.0 + Docker + Traefik

---

## Estrutura de Diretórios

```
articlehub/
├── index.html          # SPA — HTML (login, 10 views, 14 modais) (~1186 linhas)
├── app.js              # Lógica frontend IIFE (~3400 linhas, lazy load)
├── style.css           # CSS com dark/light, glassmorphism (~2529 linhas)
├── api/                # Backend PHP — cada arquivo = endpoint REST
│   ├── config.php      # PDO singleton, helpers (jsonResponse, requireAuth, requireRole, checkDuplicate)
│   ├── auth.php        # login/logout/check (sessão + preferences)
│   ├── requests.php    # CRUD + status + publish + image/history/detail/clear_image (~700 linhas, mais complexo)
│   ├── users.php       # CRUD usuários
│   ├── domains.php     # CRUD domínios + automacao_imagem (checkbox admin)
│   ├── languages.php   # CRUD idiomas
│   ├── niches.php      # CRUD nichos
│   ├── pendencies.php  # Pendências por request (unresolved/resolved)
│   ├── messages.php    # Mensagens internas
│   ├── notifications.php # Notificações (LIMIT 50)
│   ├── logs.php        # Logs de request_history por data
│   ├── compliance.php  # Histórico compliance por request
│   ├── periodic_analysis.php # Periódica com paginação LIMIT/OFFSET + distinct + history
│   └── preferences.php # PUT tema (GET é via auth.php)
├── database/
│   └── schema.sql      # Schema completo + seed + índices lazy (~500 linhas)
├── Dockerfile          # php:8.2-apache, pdo_mysql, intl
├── docker-compose.yml  # Prod: ghcr.io/daniel-vitorelli/articlehub:latest (Traefik)
├── docker-compose.dev.yml # Dev: build . → ahteste.ai-equinox.com
├── docker-compose.local.yml # Local: db mysql:8.0 + app 8080
└── .github/workflows/docker-image.yml # CI/CD → ghcr.io + Portainer webhook
```

---

## Banco de Dados (MySQL)

### Tabelas Principais

| Tabela | Colunas-chave | Observações |
|--------|--------------|-------------|
| `users` | id, name, email, password, **role**, active | 4 roles, senha texto plano dev |
| `domains` | id, blog_name, url, color, niche, language, bloco_anuncio, **automacao_imagem TINYINT(1)**, active | `automacao_imagem` = checkbox admin em Domínios |
| `requests` | id, keyword, domain_id, writer_id, requested_by_id, **status**, priority, wordcount, deadline, **instructions TEXT**, language, purpose, content_type, niche_id, published_url, **wp_edit_url**, **status_compliance**, **resumo_analise TEXT**, **imagem MEDIUMBLOB**, **imagem_nome VARCHAR(255)** | Central. `imagem` lazy via `has_imagem` flag + `?action=image` |
| `request_history` | id, request_id, user_id, action, changes JSON, url | Log status/edit/published |
| `request_pendencies` | id, request_id, user_id, description TEXT, status ENUM(unresolved/resolved), created_at, resolved_at | Pendências por request |
| `notifications` | id, user_id, type, message, related_id, is_read | `LIMIT 50` + índice `(user_id, created_at)` |
| `messages` | id, from_id, to_id, subject, body TEXT, is_read | Índices `(from_id, created_at)` |
| `languages` | id, name, code, active | `pt-br, en, es` |
| `niches` | id, name, active | 10 seeds |
| `user_preferences` | user_id, theme, sidebar_collapsed | Dark/light |
| `compliance_history` | id, request_id, status_compliance, resumo_analise TEXT | Histórico IA |
| `periodic_analysis` | id, id_post, post_type, status_compliance, resumo_analise TEXT, dominio, publish_status, created_at | Sem PK antiga corrigida para `AUTO_INCREMENT`, índices `dominio, status` |
| `periodic_analysis_status` | id, dominio, start_in, finished_in | Controle crawler |

### Roles (ENUM)

| Role | Descrição | Permissões |
|------|-----------|------------|
| `admin` | Administrador | Tudo + automacao_imagem checkbox, hard delete lixeira |
| `gestor` | Gestor de Tráfego | Criar, ver seus, editar seus, soft delete pending |
| `revisor` | Revisor | Ver todos, editar, status, revisado; sem admin |
| `redator` | Redator | Ver atribuídos + criados, status, pendências |

### Fluxo de Status (ENUM)

```
pending → in-progress → review → done → published → revisado
  │          │            │        │        │           │
  │          │            │        │        │           └─ Apenas admin/revisor, final
  │          │            │        │        └─ Requer published_url + compliance aprovado, limpa imagem/imagem_nome
  │          │            │        └─ Requer wp_edit_url (modal #modalDone) + hostname check
  │          │            └─ Em revisão
  │          └─ Em produção
  └─ Pendente (soft delete só aqui)
```

**Regras**:
- `done` exige `wp_edit_url` validada vs `domains.url` hostname
- `published` exige `done` + `compliance aprovado` + `published_url` validada
- `revisado` só de `published`, só admin/revisor, final
- `published` limpa `imagem, imagem_nome` (libera BLOB)

---

## Backend (API PHP)

### Padrão de cada endpoint

1. `require_once 'config.php'`
2. `switch ($_SERVER['REQUEST_METHOD'])` (GET/POST/PUT/DELETE)
3. `requireAuth()` / `requireRole('admin')`
4. `jsonResponse($code, $data)`

### `api/config.php` — Helpers

| Função | Descrição |
|--------|-----------|
| `getDB()` | Singleton PDO `mysql:host ...;charset=utf8mb4` |
| `jsonResponse()` | JSON + exit |
| `getInput()` | `php://input` JSON |
| `requireAuth()` | 401 se sem `$_SESSION['user']` |
| `requireRole(...$roles)` | 403 se role não permitida |
| `getAction()` | `$_GET['action']` |
| `normalizeStr()` / `checkDuplicate()` | Duplicatas accent/case insensitive (full scan) |

### `api/requests.php` — Mais complexo

| Função | Descrição | Lazy |
|--------|-----------|------|
| `getRequestPublicFields()` | Lista explícita leve: sem `imagem` BLOB, sem `instructions`/`resumo_analise` TEXT; retorna `has_imagem` `(IS NOT NULL)`, `has_resumo` e `imagem_nome` | ✅ |
| `getHistoryPublicFields()` | `rh.id, request_id, user_id, action, changes, url, created_at` | — |
| `getRequestInternalFields()` | Campos para validação interna (com `instructions`, `resumo_analise`, `imagem_nome` mas sem BLOB) | — |
| `listRequests()` | Filtra por role (admin/revisor tudo; gestor `requested_by_id`; redator `writer_id OR requested_by_id`), `LEFT JOIN domains/users` + subquery `unresolved_pendencies_count`, `ORDER BY FIELD(status)` | Sem `history` (foi N+1 removido) |
| `listDeletedRequests()` | `status=deleted`, admin vê tudo, outros só seus | — |
| `getRequestHistory()` | `GET ?action=history&id=` — lazy só ao abrir detalhe, decodifica `changes` JSON | ✅ |
| `getRequestDetail()` | `GET ?action=detail&id=` — lazy com `instructions`+`resumo_analise` + joins + `has_imagem` | ✅ |
| `getRequestImage()` | `GET ?action=image&id=` — só BLOB quando clica `🖼️`, detecta MIME via `finfo`, suporta `data:image` ou base64, retorna `{image:b64,mime,filename:imagem_nome}` | ✅ |
| `clearImage()` | `PUT ?action=clear_image` — `SET imagem=NULL, imagem_nome=NULL` + history, permissão admin/revisor/redator ou dono | ✅ |
| `createRequest()` | `POST` — cria + history `create` + notificação writer | — |
| `updateRequest()` | `PUT` — diff `fieldMap` + history `edit` | — |
| `updateStatus()` | `PUT ?action=status` — valida transições, `wp_edit_url` hostname, `status_compliance=nao_analisado` | — |
| `publishRequest()` | `PUT ?action=publish` — valida `done` + `aprovado` + `url` hostname, `SET status=published, published_url, imagem=NULL, imagem_nome=NULL` | Limpa BLOB |
| `deleteRequest()` | `DELETE ?id&force=0/1` — soft `deleted` ou hard (admin `force=1`) | — |
| `restoreRequest()` | `PUT ?action=restore` — de `deleted` para `pending` | — |
| `resetCompliance()` | `PUT ?action=reset_compliance` — `nao_analisado, resumo=NULL` | — |

### Outros endpoints

| Arquivo | Método | Descrição | Lazy |
|---------|--------|-----------|------|
| `domains.php` | GET/POST/PUT/DELETE | `listDomains` normaliza `automacao_imagem 0/1`; `create/update` aceita `automacao_imagem` (fallback se coluna não existe) | `SELECT *` ainda, mas com normalização |
| `languages.php` | GET/POST/PUT/DELETE | CRUD | — |
| `niches.php` | GET/POST/PUT/DELETE | CRUD | — |
| `pendencies.php` | GET `?request_id` / POST / PUT `update_status` | `p.*, u.name` | Lazy por modal `openPendenciesModal` |
| `messages.php` | GET `?tab=inbox/sent` | `m.*, u.name` sem LIMIT (poderia paginar) | Lazy só em `navigateTo(messages)` |
| `notifications.php` | GET | `WHERE user_id ORDER BY created_at DESC LIMIT 50` | ✅ |
| `logs.php` | GET `?date=&user_id=` | `rh.*` sem LIMIT, `DATE()` mata índice | Poderia `LIMIT 100` |
| `compliance.php` | GET `?request_id` | `ch.id, status, resumo` | Lazy em `toggleComplianceHistory` |
| `periodic_analysis.php` | GET `?limit&offset&status&post_type&dominio` / `?distinct=dominio/post_type` / `?history=1&dominio&id_post` | Agrupado `MAX(id) GROUP BY dominio,id_post` paginado, total `COUNT(*)` | ✅ Infinite scroll |
| `preferences.php` | PUT | `theme` toggle (GET morto, via `auth.php`) | — |
| `auth.php` | POST login/logout, GET check | Retorna `preferences` junto | — |

---

## Frontend (JavaScript — `app.js` ~3400 linhas)

### Arquitetura

IIFE `(function(){"use strict";})()` sem modules. Estado global + helpers + fetch + views + modais + `bindEvents` + `init` em `DOMContentLoaded`. `API="api"`, `POLL_INTERVAL_MS=15000`, `PERIODIC_PAGE_SIZE=50`, `APP_VERSION`.

### Estado Global

```javascript
let users, requests, deletedRequests, domains, languages, niches, notifications, messages = [];
let currentUser, currentMsgTab='inbox', pollInterval, complianceHistoryProvider;
let currentImageData = {id,mime,b64,filename} // só enquanto modal imagem aberto
let periodicAnalysisGroups, periodicAnalysisVisible, periodicAnalysisLoaded, periodicAnalysisTotal, periodicSentinelObserver;
```

### Helpers

`$`, `$$`, `escapeHtml`, `escapeAttr`, `formatDate`, `formatDateTime` (America/Sao_Paulo), `statusLabel`, `priorityLabel`, `roleLabel`, `getInitials`, `today`, `fieldLabel`, `statusBadge`, `complianceStatusLabel`, `publishStatusBadge`, `periodicPostLink`

### API Fetch

`apiGet/Post/Put/Delete` — `fetch` com `credentials:"include"`, `Content-Type: application/json`, throw `err.error`.

### Data Loading (lazy, sem mudar visual)

- `loadAll()` — só essencial `requests.php + notifications.php` (2 fetches) + `loadDeferred()` em background para `domains/languages/niches/users/deleted` (não bloqueia dashboard). Antes era 7 paralelos bloqueantes.
- `loadDeferred()` — `deferredLoading` singleton, `Promise.all` só do que ainda `length===0`.
- `ensureViewData(view)` — `await` antes de `render` se view precisa de `domains/users/etc` ainda não carregados.
- Polling `startPolling()` — `setInterval 15s` com `if(document.hidden) return` (pausa em aba oculta), fetcha `notifications + requests` leves (sem history).

### Permissões

`is(role)`, `canCreate()`, `canDelete()`, `canManageUsers/Domains()`, `canChangeStatus(r)`, `canEdit(r)`, `canManagePendency(r)`, `canSeeRevisado()`, `getVisibleRequests()` (já filtrado server-side)

### Login/Logout

`initLogin()` → `apiGet("auth.php?action=check")` + `applyThemeFromPrefs`, `showApp()` → `loadAll()` → `navigateTo("dashboard")` → `startPolling()`, `handleLogin()` `apiPost("auth.php?action=login")`, `handleLogout()` `apiPost("auth.php?action=logout")`

### Role UI & Navigation

`applyRoleUI()` toggle `.admin-only`, `navigateTo(view,opts)` agora `async` + `await ensureViewData(view)` antes de `render*` (sem trocar layout, só evita tabela vazia), `capitalize()`

### Views

| View | Função | Lazy |
|------|--------|------|
| Dashboard | `renderDashboard()` — 6 stats + `slice(0,5)` recentes, `has_resumo` via `has_resumo` flag | Stats varrem `requests` em memória |
| Requests | `renderRequests()` — `populateRequestFilters()` + filtros `status/priority/blog/writer/requester/search` + sort `statusOrder`+`deadline`, 10 cols (inclui `Imagem` `has_imagem` `🖼️/—` e `has_resumo`), `colspan 10` | Filtros client, mas `has_resumo` flag evita TEXT |
| Trash | `renderTrash()` — `deletedRequests` | Lazy `deleted` |
| Users/Domains/Languages/Niches | `renderUsers/Domains/Languages/Niches()` — `map` full | `Domains` com checkbox `automacao_imagem` `data-automacao-id` `PUT domains.php` admin only |
| Logs | `renderLogs()` — `apiGet(logs.php?date=&user_id)` | Sem LIMIT |
| Compliance Periodic | `renderComplianceAnalysis()` + `fetchNextPeriodicPage()` + `renderPeriodicChunk()` — `IntersectionObserver 300px` sentinel, `LIMIT/OFFSET` backend, `distinct` para filtros | ✅ Backend paginado, history via `?history=1` |
| Messages | `renderMessages()` — `apiGet(messages.php?tab=)` | Lazy só em view |
| Detail Modal | `openDetail(id)` `async` — lazy `detail` (`instructions`, `resumo_analise`) + lazy `history` (`?action=history`) com placeholder spinner, `Object.assign(r,detail)` cache | ✅ |
| Compliance Modal | `openComplianceModal(id,opts)` `async` — lazy `detail` se `has_resumo` mas sem texto, `toggleComplianceHistory` → `compliance.php` | ✅ |
| Image Modal | `openImageModal(id)` `async` — `apiGet(?action=image)` → `data:mime;base64`, `object-fit:contain`, `downloadCurrentImage()` com `imagem_nome` ou `solicitacao-{id}.ext`, `deleteCurrentImage()` → `PUT ?action=clear_image` | ✅ |
| Periodic Focus | `periodicBodyEl` click `tr.is-focused` + `document` click fora/modal → `is-focused` `box-shadow inset 3px` `style.css` | Visual |

### Modais

`openModal/closeModal/closeAllModals` (`body overflow hidden`, `clearImageModal` se `modalImage`), 14 modais: `modalDetail`, `modalNew/Edit`, `modalPublish/Done`, `modalUser/Domain/Language/Niche`, `modalCompose/MsgDetail`, `modalCompliance/ComplianceDetail`, `modalPendencies`, `modalImage` (com `imageLoader`, `modalImageEl` `max-height 75vh` `object-fit:contain`, `btnDownloadImage`, `btnDeleteImage`)

### CRUD Admin

`submitUser/DeleteUser`, `submitDomain/DeleteDomain` (com `automacao_imagem`), `submitLanguage/DeleteLanguage`, `submitNiche/DeleteNiche` — `apiGet` após mutação + `render*`

### Theme/Notifications/Messages

`applyThemeFromPrefs`, `toggleTheme` → `apiPut("preferences.php",{theme})`, `updateNotifBadge`, `renderNotifDropdown`, `updateMsgBadge`, `renderMessages`, `openPendenciesModal` → `pendencies.php`

### Event Binding

`bindEvents()` — nav `data-view`, stat cards → `navigateTo(requests, statusFilter)`, `globalSearch` debounce 250ms, `modal close` `[data-close]`, overlay click, `Escape`, `periodicAnalysisBody [data-key]` + row focus, `btnResetCompliance` routing periódico vs request, `btnDownloadImage/DeleteImage`

### Init

`init()` → `bindEvents()` + `initLogin()` em `DOMContentLoaded`, `defer` em `index.html:12`

---

## Frontend (HTML — `index.html`)

| Seção | ID | Descrição |
|-------|----|-----------|
| Login | `#loginScreen` | hints `admin@hub.com` etc |
| App | `#appWrapper` flex sidebar+main | `preconnect fonts.googleapis.com` `index.html:9`, `style.css` + `app.js defer` `index.html:12` |
| Sidebar | `#sidebar` | `data-view` nav + `roleBadge` |
| Views | `#viewDashboard/Requests/Trash/Users/Domains/Languages/Niches/Messages/Logs/ComplianceAnalysis` | `.view-panel` toggle `active` |
| Requests Table | `#requestsTableBody` | 10 cols: Artigo, Blog, Solicitante, Redator, Status, Compliance (`has_resumo` clique), Prioridade, Prazo, **Imagem `has_imagem`**, Ações |
| Domains Table | `#domainsTableBody` | 6 cols: Blog, URL, Nicho, **Automação Imagem `checkbox data-automacao-id`**, Status, Ações |
| Periodic Table | `#periodicAnalysisBody` | 8 cols + sentinel `#periodicScrollSentinel` + `is-focused` |
| Modais | 14 | `#modalDetail` (history lazy), `#modalNew/Edit`, `#modalPublish/Done`, `#modalUser/Domain`, `#modalImage` (viewer `object-fit:contain` + Baixar/Excluir), etc |

---

## Estilo (CSS — `style.css`)

- **Fonte**: `Inter` via `<link>` + `preconnect` (antes `@import` removido)
- **Temas**: `:root` dark + `[data-theme="light"]` via `var(--bg-*)`
- **Glass**: `rgba(30,30,52,0.6)` + `backdrop-filter: blur(20px)`
- **Classes**: `.admin-only`, `.status-badge.{pending/done/...}`, `.role-tag`, `.is-focused` (`inset 3px` roxo), `.compliance-clickable`, `.image-view-btn`, `.scroll-sentinel`
- **Spinner**: `@keyframes spin` `border-top-color` 0.6s linear

---

## Deploy

- **Prod**: `docker-compose.yml` `image: ghcr.io/daniel-vitorelli/articlehub:latest` + `traefik` `Host(articlehub.ai-equinox.com)` + `volume articlehub-sessions`
- **Dev ahteste**: `docker-compose.dev.yml` `build: .` → `ahteste.ai-equinox.com`
- **Local**: `docker-compose.local.yml` `db mysql:8.0` + `schema.sql:/docker-entrypoint-initdb.d/schema.sql` + `app 8080:80`
- **CI**: `.github/workflows/docker-image.yml` `push main` → `docker/build-push-action` `ghcr.io` + `curl PORTAINER_WEBHOOK`
- **DB Config**: `api/config.php` `APP_ENV=prod/dev` → `5.189.166.47` `articlehub/ahteste`
- **Seed**: `INSERT users (9)`, `domains (5)` com `automacao_imagem 0`, `requests (10)` etc, `ON DUPLICATE KEY UPDATE`

### Usuários Teste

| Role    | Email            | Senha      |
|---------|------------------|------------|
| Admin   | admin@hub.com    | admin123   |
| Gestor  | fernando@hub.com | gestor123  |
| Revisor | revisor@hub.com  | revisor123 |
| Redator | ana@hub.com      | redator123 |

---

## Padrões e Convenções

1. **Sem frameworks**: Vanilla JS IIFE, PHP puro, CSS puro
2. **SPA sem router**: `navigateTo()` + `ensureViewData()` lazy
3. **API REST**: GET/POST/PUT/DELETE JSON, `?action=` para multiplexar `requests.php`
4. **Sessão PHP**: `$_SESSION['user']`, `432000s` (5 dias)
5. **Polling**: 15s com `document.hidden` pause
6. **Lazy load**: `has_imagem/has_resumo` flags + `?action=image/history/detail` + periodic `LIMIT/OFFSET` + `domains` deferred
7. **Modais**: `openModal/closeModal` + `data-close` + overlay click + `Escape`
8. **Histórico**: `request_history` JSON `changes` + lazy no detalhe
9. **Notificações**: backend `INSERT notifications` em `updateStatus/createRequest/publish`
10. **Duplicatas**: `checkDuplicate()` normalizado (full scan, poderia `LOWER(?)`)
11. **Visual imutável**: otimizações não trocam pixels, só network/payload
