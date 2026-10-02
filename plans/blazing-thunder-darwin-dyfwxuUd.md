# Melhoria do Registro de Presença — ArticleHub

## Contexto

O sistema de presença (heartbeat por aba + `presence_sessions`/`presence_tabs`) foi entregue
na sessão anterior e funciona, mas uma auditoria linha a linha revelou **bugs reais de wiring**
e **pontos fracos de lógica**. O usuário pediu: *"melhore o máximo possível, incluindo a lógica
e outros pontos"*.

Achado mais grave: **o checklist de view nova (convenção do projeto) não foi aplicado
completamente** — `handleLogout()` não para a presença, e `presence-log` não está no `titles`,
no guard de rota admin, no `ensureViewData` nem no `refreshCurrentView`. Ou seja: o usuário
continua online por até 150 s depois de sair, o cabeçalho mostra "Dashboard", **qualquer perfil
consegue abrir a tela de histórico** e o `<select>` de usuários pode nascer vazio.

## Decisões confirmadas com o usuário

| Tema | Decisão |
|---|---|
| Retenção | **Configurável pelo admin** — nova chave `presence_retention_days` (0 = nunca expurgar, default 180) |
| Histórico | **Páginas de 100 + botão "Carregar mais"**, com `total` e totais agregados do período |
| Aba duplicada | **Resolver com claim em `localStorage`** (Chrome copia o `sessionStorage` ao duplicar aba) |

## Arquivos

| Arquivo | Mudança |
|---|---|
| `api/presence.php` | Reescrito em ~70 %: settings em 1 query, gate de manutenção, gate de existência, dedupe de sessões, retenção, graça anti-F5 sem logout, upsert com `user_id`, filtros `from`/`to`/`reason`, paginação + totais, envelopes com metadado |
| `api/settings.php` | Seed + regra de `presence_retention_days` |
| `database/schema.sql` | Índice `idx_ps_user_exited`, bloco de migração manual, comentário de retenção |
| `app.js` | Constantes, `handleLogout`, núcleo da presença, `loadPresenceOnline`/`presenceStatusHtml`/`renderUsers`, `renderPresenceLog` reescrito, `navigateTo`, `ensureViewData`, `refreshCurrentView`, `bindEvents`, `renderSettings`/`saveSettings` |
| `index.html` | Filtros De/Até + motivo, botão "Carregar mais", campo de retenção em Configurações |
| `style.css` | `.presence-dot.unknown` |
| `.verify/check-presence-php.cjs` | Atualizar ~7 asserções + ~10 novas |
| `.verify/check-presence-view.cjs` | **Novo** — asserções de wiring do front |
| `ARCHITECTURE.md` | Linha de `api/presence.php` na tabela de endpoints |

---

## Fase 1 — Backend: sobrevivência e custo (sem mudança de schema)

`api/presence.php`

1. **Constantes novas** ao lado das existentes:
   `PRESENCE_REOPEN_GRACE_MIN = 60`, `PRESENCE_REOPEN_GRACE_MAX = 300`,
   `PRESENCE_RETENTION_DEFAULT = 180`, `PRESENCE_RETENTION_MAX = 3650`,
   `PRESENCE_SWEEP_EVERY = 60`, `PRESENCE_SCHEMA_EVERY = 600`,
   `PRESENCE_HISTORY_PAGE = 100`, `PRESENCE_HISTORY_MAX = 500`.

2. **`presenceSettings()` em uma consulta** (hoje são 2 SELECTs por batida). A mesma query
   traz `UNIX_TIMESTAMP()` e `TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), NOW())` — o offset do
   relógio do MySQL, que o front precisa para formatar `DATETIME` sem depender do fuso do
   navegador. Retorna `['interval','timeout','limbo','grace','retention_days','tz_offset_minutes','now_ts']`.
   - `retention_days`: `is_numeric` **e nunca `if ($raw)`** — `0` é valor válido ("nunca expurgar").
   - `grace = min(max(60, interval), 300)`: o piso é fixo porque o buraco do F5 depende do
     tempo de carga da página, não do intervalo.

3. **`presenceMaintenance($db, $cfg, $forceSweep = false)`** — novo. Guarda em
   `$_SESSION['presence_schema_at']` / `$_SESSION['presence_sweep_at']` (por cookie, não por
   aba) usando o relógio do MySQL. Antes: 2 `CREATE TABLE` + 3 escritas do sweep **a cada
   batida de cada aba**; depois: ~1 varredura/min e ~1 checagem de schema/10 min por usuário.
   Atrasar a varredura **não** deixa ninguém online além da conta na tela, porque as leituras
   (`?online=1`, histórico) reaplicam o predicado de timeout e varrem sempre.

4. **`presenceHeartbeat()`**: passa a usar `presenceMaintenance()`; se a tabela não existe e o
   DDL falhou, responde `200 {ok:false, unavailable:true, interval:MAX}` em vez de estourar
   `PDOException` não capturada (500 em cada batida). Envolve o corpo em `try/catch
   (PDOException)` → `503` JSON + `unset($_SESSION['presence_schema_at'])` para a próxima
   batida tentar recriar. Resposta passa a incluir `server_utc_offset_minutes`.

5. **`sweepPresence()`** — acrescentar ao fim:
   - **Dedupe**: para cada `user_id` com mais de uma sessão aberta, mantém a mais antiga
     (`MIN(id)`) e fecha as extras com `exit_reason='timeout'`. Auto-cura sem constraint.
   - **Retenção**: `DELETE ... WHERE exited_at IS NOT NULL AND exited_at < NOW() - INTERVAL n DAY
     ORDER BY exited_at LIMIT 1000` (em lote, para o expurgo nunca virar um DELETE gigante no
     meio de uma batida). `0` desliga.

6. **`presenceOpenSession()`**: a graça anti-F5 passa a exigir `exit_reason <> 'logout'` —
   sair é explícito, reabrir apagaria o motivo e fundiria duas visitas numa só. A graça
   também passa a ser recebida por parâmetro (`$cfg['grace']`) em vez de hardcoded 60.

7. **Upsert de `presence_tabs`**: incluir `user_id = VALUES(user_id)` no
   `ON DUPLICATE KEY UPDATE`. Consequência real do bug: logout de um usuário e login de outro
   na mesma aba reaproveita o token do `sessionStorage`, e o `DELETE` do `?action=offline`
   (que filtra por `user_id`) não achava a linha → sessão nunca fechava no logout.

**Checkpoint:** `node .verify/php-lint.cjs`.

---

## Fase 2 — Backend: leitura (filtros, paginação, totais)

1. **`presenceDateParam($key)`** — valida `Y-m-d` com `preg_match` + `checkdate`; devolve
   `null` se inválido. Nada de aritmética de dia no PHP.

2. **Filtro sargável** — trocar `DATE(ps.entered_at) = ?` (full scan + dependente de
   `@@time_zone`) por intervalo **half-open**:
   `ps.entered_at >= ?` / `ps.entered_at < DATE_ADD(?, INTERVAL 1 DAY)`.
   Aceita `from`/`to` (precedência) e continua aceitando `date` (dia único) para compatibilidade.

3. **Filtro por motivo** — allow-list `['closed','timeout','logout']` + `'open'`
   (`exited_at IS NULL`, que não é um `exit_reason`).

4. **Paginação** — seguir a convenção de `api/periodic_analysis.php:246-279`: `$total` só
   quando `offset === 0`, `LIMIT ? OFFSET ?` com `bindValue(..., PDO::PARAM_INT)`.
   `limit` clampado a `PRESENCE_HISTORY_MAX`.

5. **Totais do período** (só em `offset === 0`): sessões, usuários distintos, segundos somados
   e quantas em aberto.

6. **Envelope** — `jsonResponse(200, ['data'=>..., 'total'=>..., 'totals'=>...,
   'server_utc_offset_minutes'=>...])`. Mutação de contrato deliberada (o único consumidor é
   `renderPresenceLog`, reescrito na Fase 5).

7. **`duration_estimated`** — `(ps.exit_reason = 'timeout')`, **derivado, sem coluna nova**: a
   duração de quem caiu por timeout é um limite superior (`last_seen_at + limbo`), não um valor
   exato. O front mostra `≈`.

8. **`presenceOnlineNow()` / `presenceHistory()`** — gate `presenceTableExists()` antes de
   ler; sem tabela devolve vazio (não 500). No `?online=1`, agregar com
   `GROUP BY s.user_id, u.name, u.role` (sessões duplicadas faziam o mesmo usuário aparecer 2×;
   compatível com `ONLY_FULL_GROUP_BY`).

**Checkpoint:** lint + `check-presence-php.cjs` atualizado.

---

## Fase 3 — Frontend: wiring e acessibilidade (barato, independente)

1. **`handleLogout()` (app.js:943)** — `stopPresence("logout")` **antes** de anything, mais
   limpeza de `presenceOnlineMap`, `presenceOnlineMapAt`, `presenceTabToken` e
   `sessionStorage.removeItem("ah_tab_token")` (o token sobrevive ao login; sem limpar, outro
   usuário na mesma aba herdaria o token).
2. **Guard de rota (app.js:1368)** — incluir `presence-log` na lista admin-only.
3. **`titles` (app.js:1404)** — `"presence-log": "Registro de Presença"`.
4. **`ensureViewData()` (app.js:801)** — garantir `users` para `presence-log`.
5. **`refreshCurrentView()` (app.js:6199)** — cobrir `presence-log`.
6. **Acessibilidade** — `aria-hidden="true"` na bolinha, `title` no rótulo; novo estado
   `Desconhecido` (`.presence-dot.unknown`) quando o mapa está expirado — a coluna deixa de
   **afirmar** "Offline" sem dado.

---

## Fase 4 — Frontend: núcleo da presença

1. **`serverUtcOffsetMinutes`** + `parseServerDateTime()`/`formatPresenceDateTime()`:
   `DATETIME` do MySQL vem como `"2026-10-01 12:00:00"` no fuso do servidor; `new Date()` com
   essa string interpreta no fuso do **navegador** → fora de SP a hora desloca. O parser monta
   o instante com `Date.UTC` + offset e só então formata em `America/Sao_Paulo`.
2. **Guard de batida em voo** (`presenceBeatInFlight`) — `visibilitychange` + timer + evento
   `online` podiam disparar batidas sobrepostas.
3. **`window.addEventListener("online", ...)`** — sem isso, Wi-Fi que cai e volta deixa até
   10 min de **offline falso** (teto do backoff). Handler: zera falhas, sai do backoff, bate
   na hora. Backoff também passa a ser limitado a 60 s quando `navigator.onLine === false`.
4. **Throttle do `?online=1`** (máx. 1 a cada 20 s) e **`updatePresenceCells()`** — atualiza só
   as células `td[data-presence-for]` em vez de `renderUsers()` inteiro (que perdia scroll,
   seleção e menus abertos a cada batida).
5. **Expiração do mapa** (`PRESENCE_STATUS_MAX_AGE_MS = 180000`) — o `catch` de
   `loadPresenceOnline` preserva o mapa de propósito, mas agora o mapa velho vira
   "Desconhecido" em vez de um "Online" obsoleto sem limite.
6. **Claim de aba em `localStorage`** (`ah_tab_claims = {token: ts}`): se o token lido do
   `sessionStorage` já foi reivindicado por outra aba viva, gera token novo. Envolve tudo em
   `try/catch`; se `localStorage` falhar, comportamento atual.

---

## Fase 5 — Frontend: tela de Registro de Presença + Configurações

1. **`index.html`** — trocar o par Data/Usuário por **De / Até / Usuário / Motivo**
   (`filterPresenceFrom`, `filterPresenceTo`, `filterPresenceUser`, `filterPresenceReason`) e
   adicionar `<button id="btnPresenceLoadMore" hidden>` no `.table-footer`.
2. **`renderPresenceLog()` reescrito**:
   - monta o `<select>` com `map().join("")` — o `innerHTML +=` dentro do `forEach` era O(n²);
   - estado de "Carregando…", `presenceLogSeq` para descartar resposta fora de ordem,
     `presenceLogBusy` para não empilhar requisições;
   - `debounce` de 250 ms nos inputs de data (novo helper no topo — não existe hoje em `app.js`);
   - rodapé: `"X de Y acesso(s) · N usuário(s) · <tempo> no período · M em aberto"`;
   - duração de timeout mostrada como `≈`.
3. **Configurações** — campo `presence_retention_days` (`#settingPresenceRetention`, 0–3650,
   help text "0 = nunca expurgar"), preenchido em `renderSettings()`, validado em
   `saveSettings()` **junto com os outros** (a função já valida tudo antes de salvar), e salvo
   com um 5º `apiPut`.

---

## Fase 6 — Schema (o único delta)

**Nenhuma coluna nova.** `duration_estimated` e `still_open` são derivados.

- `INDEX idx_ps_user_exited (user_id, exited_at)` — atende a graça anti-F5 e o histórico por
  usuário (hoje só há `(user_id, entered_at)`).
- Aplicação: no `CREATE TABLE IF NOT EXISTS` (instalações novas) **e** em
  `ensurePresenceIndexes()` com gate `SHOW INDEX ... WHERE Key_name = 'idx_ps_user_exited'` +
  `ALTER` idempotente (instalações existentes) — roda dentro do gate de 10 min.
- `database/schema.sql`: índice no `CREATE` do topo, `ALTER` comentado no bloco de migração
  manual, e comentário registrando `presence_retention_days` (0 = nunca expurgar).
- **Não fazer**: `UNIQUE` parcial com coluna gerada para impedir sessão duplicada. É DDL em
  tabela que já pode ter dados e transformaria uma corrida benigna em `INSERT` falho; o dedupe
  da varredura resolve o sintoma.

---

## Fase 7 — Documentação e verificação

1. `ARCHITECTURE.md` — linha de `api/presence.php` na tabela de endpoints (hoje não lista nem
   `presence.php`, nem `users.php`, nem `periodic_comments.php`).
2. **Atualizar** `.verify/check-presence-php.cjs` — asserções que quebram:
   - `DATE(ps.entered_at) = ?` → `ps.entered_at >= \?` e `DATE_ADD(?, INTERVAL 1 DAY)`;
     nova: zero ocorrências de `DATE(ps.` no SQL.
   - `LIMIT 500` → `LIMIT ? OFFSET ?` + `bindValue(..., PDO::PARAM_INT)`.
   - graça: `INTERVAL {grace} SECOND` + nova `exit_reason <> 'logout'`.
   - upsert: incluir `user_id = VALUES(user_id)`.
   - contagem de chamadas de `sweepPresence`/`ensurePresenceTables` muda de lugar.
   - `chamadas("date") === 0` continua valendo (`checkdate` é outro nome — sem falso positivo).
   - novas: retenção com `LIMIT 1000`; `duration_estimated`; `server_utc_offset_minutes` nos
     dois envelopes; `unavailable`; `SHOW INDEX` + `idx_ps_user_exited`; `GROUP BY s.user_id`.
3. **Novo** `.verify/check-presence-view.cjs` — regex sobre `app.js`/`index.html`:
   `titles` tem `presence-log`; guard de `navigateTo` tem `presence-log`; `ensureViewData` tem
   `presence-log`; `refreshCurrentView` tem `presence-log`; `handleLogout` chama
   `stopPresence("logout")` **antes** do `apiPost` de logout; zero `innerHTML +=` dentro de
   `renderPresenceLog`; existe `addEventListener("online"`; `PRESENCE_LOG_PAGE_SIZE ===
   PRESENCE_HISTORY_PAGE`; `index.html` tem os 4 filtros e o botão; `data-presence-for` existe.
4. Rodar: `node .verify/php-lint.cjs` (após cada fase), `check-presence-php.cjs`,
   `check-presence-view.cjs`, `test-presence.cjs`, suíte completa, `node --check app.js`.
   Prévias/screenshots só se sobrar escopo.
5. **QA manual** (ambiente dev; **nunca** o banco remoto 5.189.166.47): F5 não cria linha
   nova; logout seguido de login cria linha nova; 2 abas → fechar 1 não desconecta; login de
   outro usuário na mesma aba; logout tira do `?online=1` na hora; Wi-Fi off/on recupera;
   navegador em fuso ≠ SP mostra a hora de SP; não-admin via `navigateTo("presence-log")`
   cai em Dashboard; `?from=&to=&reason=timeout` e paginação com `total`.

---

## Riscos e fora de escopo

- **Mudança de contrato** da resposta do histórico (array → `{data,total,totals}`): seguro
  porque há um único consumidor, que é reescrito na mesma rodada.
- **Presença não é auditoria** — continua degradando em silêncio quando a tabela não existe.
  A regra *fail-closed* continua valendo para `periodic_reanalysis_log`, não para `presence_*`.
- **Não mexer** em `api/config.php` (CORS) nem no fuso do MySQL: converter colunas
  `DATETIME` existentes mudaria o significado das linhas já gravadas.
- Fora de escopo: senhas em texto no seed, `ARCHITECTURE.md` inteiro desatualizado.
