# ArticleHub — Arquitetura do Sistema

> **Objetivo**: Documento de referência rápida para orientar humanos e IAs sobre a estrutura completa do sistema. Leia este arquivo ANTES de explorar o código.

---

## Visão Geral

ArticleHub é um sistema de gestão de solicitações de artigos para blogs/sites. Gestores criam pedidos de artigos, redatores os produzem, revisores validam e o conteúdo é publicado via WordPress.

**Stack**: PHP puro (API REST) + JavaScript Vanilla (SPA) + MySQL + Docker

---

## Estrutura de Diretórios

```
articlehub/
├── index.html          # SPA — todo o HTML (login, views, modais) (~920 linhas)
├── app.js              # Toda lógica frontend em IIFE (~1850 linhas)
├── style.css           # CSS completo com dark/light themes (~2200 linhas)
├── api/                # Backend PHP — cada arquivo = um endpoint REST
│   ├── config.php      # Conexão MySQL, helpers (jsonResponse, requireAuth, requireRole)
│   ├── auth.php        # Login/logout/check (sessão PHP)
│   ├── requests.php    # CRUD de solicitações + status + publish (~390 linhas, mais complexo)
│   ├── users.php       # CRUD de usuários (~160 linhas)
│   ├── domains.php     # CRUD de domínios/blogs
│   ├── languages.php   # CRUD de idiomas
│   ├── niches.php      # CRUD de nichos
│   ├── messages.php    # Sistema de mensagens internas
│   ├── notifications.php # Notificações (leitura e marcar como lido)
│   └── preferences.php # Preferências do usuário (tema, notificações)
├── database/
│   └── schema.sql      # Schema completo + seed data (~350 linhas)
├── Dockerfile          # Imagem PHP/Apache
├── docker-compose.yml  # APP + MySQL
└── test_db.php         # Teste de conexão com o banco
```

---

## Banco de Dados (MySQL)

### Tabelas Principais

| Tabela | Colunas-chave | Observações |
|--------|--------------|-------------|
| `users` | id, name, email, password, **role**, active | Senhas em texto plano (dev) |
| `domains` | id, blog_name, url, color, active | Blogs/sites destino |
| `requests` | id, keyword, domain_id, writer_id, requested_by_id, **status**, priority, wordcount, deadline, instructions, language, purpose, content_type, niche_id, published_url, **wp_edit_url** | Tabela central |
| `request_history` | id, request_id, user_id, action, changes (JSON), url | Log de todas as alterações |
| `notifications` | id, user_id, type, message, related_id, is_read | Sistema de notificações |
| `messages` | id, sender_id, recipient_id, subject, body, is_read | Mensagens internas |
| `languages` | id, name, code, active | Idiomas disponíveis |
| `niches` | id, name, active | Categorias/nichos |
| `user_preferences` | user_id, theme, email_notifications | Preferências individuais |

### Roles (ENUM)

| Role | Descrição | Permissões |
|------|-----------|------------|
| `admin` | Administrador | Tudo: gerenciar usuários, domínios, idiomas, nichos; ver todos os pedidos |
| `gestor` | Gestor de Tráfego | Criar pedidos, ver seus próprios pedidos, atribuir redatores |
| `revisor` | Revisor | Ver **todos** os pedidos, editar, alterar status, marcar como "revisado"; **sem** acesso à administração |
| `redator` | Redator | Ver pedidos atribuídos, alterar status dos seus pedidos |

### Fluxo de Status (ENUM)

```
pending → in-progress → review → done → published → revisado
  │          │            │        │        │           │
  │          │            │        │        │           └─ Apenas admin/revisor
  │          │            │        │        └─ Requer published_url (validada por domínio)
  │          │            │        └─ Requer wp_edit_url (validada por domínio)
  │          │            └─ Em revisão editorial
  │          └─ Redator em produção
  └─ Aguardando início
```

**Regras de transição**:
- `done` → exige `wp_edit_url` (modal `#modalDone`)
- `published` → exige `published_url` (modal `#modalPublish`), só a partir de `done`
- `revisado` → só a partir de `published`, apenas admin/revisor
- `revisado` → status final, não pode ser alterado

---

## Backend (API PHP)

### Padrão de cada endpoint

Todos os arquivos em `api/` seguem o mesmo padrão:
1. `require_once 'config.php'`
2. Switch por `$_SERVER['REQUEST_METHOD']` (GET/POST/PUT/DELETE)
3. Cada case chama uma função específica
4. Funções usam `requireAuth()` ou `requireRole('admin', ...)` para controle de acesso
5. Resposta sempre via `jsonResponse($statusCode, $data)`

### Arquivo mais complexo: `api/requests.php`

| Função | Linhas | Descrição |
|--------|--------|-----------|
| `listRequests()` | 35–95 | Filtra por role: admin/revisor vêem tudo; gestor vê os seus; redator vê atribuídos |
| `createRequest()` | 97–154 | Qualquer autenticado pode criar |
| `updateRequest()` | 156–217 | Editar campos (admin, revisor, ou gestor dono) |
| `updateStatus()` | 219–317 | **Mais complexo**: validações, transição `done` salva `wp_edit_url`, histórico, notificações |
| `publishRequest()` | 319–376 | Valida URL, verifica domínio, atualiza status e `published_url` |
| `deleteRequest()` | 378–391 | Admin only |

### `api/config.php` — Helpers globais

| Função | Descrição |
|--------|-----------|
| `getDB()` | Singleton PDO (MySQL) |
| `jsonResponse($code, $data)` | Resposta JSON + exit |
| `getInput()` | Parse JSON do body |
| `requireAuth()` | Retorna `$_SESSION['user']` ou 401 |
| `requireRole(...$roles)` | Verifica role ou 403 |
| `normalizeStr($str)` | Remove acentos para comparação |
| `checkDuplicate(...)` | Verifica duplicatas (case/accent insensitive) |

---

## Frontend (JavaScript — `app.js`)

### Arquitetura

O arquivo usa uma IIFE `(function() { ... })()` com escopo fechado. **Não há módulos/imports**.

### Estado Global (variáveis no topo)

```javascript
let users, requests, domains, languages, niches, notifications, messages = [];
let currentUser = null;      // Objeto do usuário logado
let currentMsgTab = 'inbox'; // Tab ativa de mensagens
let pollInterval = null;     // ID do setInterval para auto-refresh (15s)
```

### Funções Organizadas por Seção

#### Helpers (L24–83)
`$()`, `$$()`, `escapeHtml()`, `formatDate()`, `formatDateTime()`, `statusLabel()`, `priorityLabel()`, `roleLabel()`, `getInitials()`, `today()`, `fieldLabel()`

#### API Fetch (L85–127)
`apiGet()`, `apiPost()`, `apiPut()`, `apiDelete()` — wrappers de `fetch()` com tratamento de erro

#### Data Loading (L129–149)
`loadAll()` — carrega users, requests, domains, languages, niches, notifications, messages em paralelo via `Promise.all`

#### Permissões (L151–179)
| Função | Regra |
|--------|-------|
| `is(role)` | Verifica role do `currentUser` |
| `canCreate()` | Qualquer autenticado |
| `canDelete()` | Admin only |
| `canManageUsers()` | Admin only |
| `canManageDomains()` | Admin only |
| `canChangeStatus(r)` | Admin, redator, revisor, ou dono do pedido |
| `canEdit(r)` | Admin, revisor, ou gestor dono |
| `canSeeRevisado()` | Admin ou revisor |

#### Login/Logout (L181–267)
`initLogin()`, `showLogin()`, `showApp()`, `handleLogin()`, `handleLogout()`, `startPolling()`, `stopPolling()`

#### Role UI & Navigation (L276–328)
- `applyRoleUI()` — aplica classes CSS por role, toggle `.admin-only`, `.gestor-admin-only`, `.gestor-col`
- `navigateTo(viewName, options)` — SPA navigation; restringe views admin para não-admins

#### Dashboard (L330–389)
`renderDashboard()` — stat cards + tabela dos últimos pedidos; card "Revisados" visível apenas admin/revisor

#### Requests View (L391–512)
`populateRequestFilters()`, `renderRequests()` — tabela com filtros (status, prioridade, blog, redator, gestor)

#### CRUD Views — Admin (L514–569)
`renderUsers()`, `renderDomains()`, `renderLanguages()` (L1324+), `renderNiches()` (L1408+)

#### Modais (L571–762)
- `openModal()`, `closeModal()`, `closeAllModals()`
- `openNewRequest()`, `submitRequest()`
- `openEditRequest()`, `fillEditForm()`, `submitEditRequest()`

#### **Detail Modal — `openDetail(id)` (L764–904)**
> **Função mais complexa do frontend**. Monta HTML completo com:
> - Info do pedido (keyword, blog, redator, prioridade, deadline, etc.)
> - Link WP Edit (`wp_edit_url`) para status `done`/`revisado`/`published`
> - Link Publicado (`published_url`) para status `published`
> - Fluxo de status (steps clicáveis — `revisado` só para admin/revisor)
> - Histórico de alterações (`buildHistoryHtml`)
> - Botão editar (se permitido)

#### Status Change (L960–1155)
- `updateRequestStatus()` — chamada genérica à API
- `openPublishModal()` / `submitPublish()` — validação e publicação com URL
- `openDoneModal()` / `submitDone()` — validação e conclusão com WP Edit URL

#### User/Domain CRUD (L1157–1301)
`openNewUser()`, `openEditUser()`, `submitUser()`, `deleteUser()`, `openNewDomain()`, etc.

#### Theme (L1303–1322)
`applyThemeFromPrefs()`, `toggleTheme()` — dark/light toggle salvo via API

#### Notifications (L1490–1559)
`updateNotifBadge()`, `renderNotifDropdown()`, `toggleNotifDropdown()`, `markAllNotifsRead()`

#### Messages (L1561–1692)
`updateMsgBadge()`, `renderMessages()`, `openMsgDetail()`, `openCompose()`, `sendMessage()`

#### Event Binding (L1716–1838)
`bindEvents()` — todos os event listeners centralizados (nav, modals, filtros, CRUD, etc.)

#### Init (L1840–1850)
`init()` → `bindEvents()` + `initLogin()`, chamado no `DOMContentLoaded`

---

## Frontend (HTML — `index.html`)

### Estrutura de Seções

| Seção | ID | Descrição |
|-------|----|-----------|
| Login | `#loginScreen` | Formulário de login com hints de credenciais |
| App Wrapper | `#appWrapper` | Container principal (sidebar + content) |
| Sidebar | `#sidebar` | Navegação com links `.nav-link[data-view]` |
| Dashboard | `#viewDashboard` | Stat cards + tabela recente |
| Requests | `#viewRequests` | Filtros + tabela completa |
| Users | `#viewUsers` | Admin: tabela de usuários |
| Domains | `#viewDomains` | Admin: tabela de domínios |
| Languages | `#viewLanguages` | Admin: tabela de idiomas |
| Niches | `#viewNiches` | Admin: tabela de nichos |
| Messages | `#viewMessages` | Inbox/Sent com tabs |

### Modais Importantes

| Modal ID | Propósito |
|----------|-----------|
| `#modalDetail` | Detalhes do pedido (+ fluxo de status) |
| `#modalRequest` | Criar novo pedido |
| `#modalEdit` | Editar pedido existente |
| `#modalPublish` | Publicar artigo (input URL publicada) |
| `#modalDone` | Concluir artigo (input WP Edit URL) |
| `#modalUser` | Criar/editar usuário |
| `#modalDomain` | Criar/editar domínio |
| `#modalLanguage` | Criar/editar idioma |
| `#modalNiche` | Criar/editar nicho |
| `#modalCompose` | Compor mensagem |
| `#modalMsgDetail` | Visualizar mensagem |

---

## Estilo (CSS — `style.css`)

### Sistema de Design

- **Temas**: dark (padrão) e light via `[data-theme="light"]`
- **CSS Variables**: todas em `:root` — cores, radii, shadows, transitions
- **Fonte**: Inter (Google Fonts)

### Classes CSS Importantes

| Padrão | Uso |
|--------|-----|
| `.admin-only` | Elementos visíveis apenas para admin (toggle via JS) |
| `.gestor-admin-only` | Visível para quem pode criar pedidos |
| `.gestor-col` | Coluna "Gestor" (visível para admin/revisor) |
| `.status-badge.{status}` | Badge colorido de status |
| `.role-tag.{role}` | Badge de role na tabela de usuários |
| `.user-role-badge.role-{role}` | Badge de role no sidebar |
| `.status-step` | Steps do fluxo de status no modal de detalhe |
| `.published-link` | Container do link publicado |
| `.wp-edit-link` | Container do link WP Edit |

### Cores por Status/Role

| Status | Cor |
|--------|-----|
| pending | amber/warning |
| in-progress | azul/info |
| review | roxo/primary |
| done | verde/secondary |
| published | teal (#00d2be) |
| revisado | amber/warning |

| Role | Cor |
|------|-----|
| admin | rosa/danger |
| gestor | azul/info |
| revisor | amber/warning |
| redator | verde/secondary |

---

## Deploy

- **Docker**: `docker-compose up` (PHP/Apache + MySQL)
- **DB Config**: `api/config.php` (host, db, user, pass)
- **Seed Data**: `database/schema.sql` contém inserções iniciais

### Usuários de Teste

| Role | Email | Senha |
|------|-------|-------|
| Admin | admin@hub.com | admin123 |
| Gestor | fernando@hub.com | gestor123 |
| Revisor | revisor@hub.com | revisor123 |
| Redator | ana@hub.com | redator123 |

---

## Padrões e Convenções

1. **Sem frameworks**: JS vanilla, PHP puro, CSS puro
2. **SPA sem router**: navegação via `navigateTo()` + toggle de `.view-panel`
3. **API RESTful**: GET/POST/PUT/DELETE, respostas JSON
4. **Sessão PHP**: autenticação via `$_SESSION['user']`
5. **Polling**: auto-refresh de dados a cada 15s via `setInterval`
6. **Modais**: abrir com `openModal(id)`, fechar com `closeModal(id)` ou `data-close`
7. **Filtros server-side**: requests já vêm filtrados por role no backend
8. **Histórico**: toda alteração de request gera entrada em `request_history`
9. **Notificações**: criadas automaticamente no backend ao alterar status
10. **Duplicatas**: validadas via `checkDuplicate()` com normalização de acentos
