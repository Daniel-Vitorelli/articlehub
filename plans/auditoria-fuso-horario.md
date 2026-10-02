# Auditoria de fuso horário — ArticleHub

> **STATUS: todos os itens P1–P5 foram CORRIGIDOS em 2026-10-02.** Este documento fica como
> registro do diagnóstico. Abaixo, cada item traz a marca do que foi feito. A auditoria original
> (descrição dos problemas) foi mantida para contexto.

**Premissa do pedido:** nem o servidor web (PHP) nem o MySQL estão configurados em
`America/Sao_Paulo`. Portanto **qualquer** valor de data/hora que dependa de "que horas são
agora" precisa de tratamento explícito. Este documento lista onde isso já é feito, onde é
feito pela metade e onde **não** é feito.

**Modelo de tempo do projeto (o que está correto e deve ser preservado):**

| Camada | Relógio | Uso |
|---|---|---|
| MySQL | relógio do servidor de banco | **fonte única de verdade** para `created_at`, `entered_at`, etc. |
| PHP | relógio do container | só metadado não persistido (SSE, webhook) |
| Navegador | relógio da máquina do usuário | só formatação, convertendo para São Paulo no display |

Regra da casa: **evento persistido nunca recebe timestamp gerado no PHP.** Quem grava é o
`DEFAULT CURRENT_TIMESTAMP` / `NOW()` do MySQL, para que todos os valores sejam comparáveis
entre si (mesmo relógio). Está aplicado corretamente em `requests`, `request_history`,
`periodic_analysis`, `periodic_analysis_comments`, `periodic_reanalysis_log`,
`presence_sessions`, `presence_tabs`.

---

## ✅ P1 — CORRIGIDO: data de "hoje" dos Logs de Status

**Arquivos:** `api/logs.php:22` + `app.js:5692-5693`

**O que foi feito:**
- `api/config.php` ganhou o helper `applySaoPauloDayFilter($where, $params, $coluna, $dia)`, que
  traduz o dia de São Paulo em um intervalo **half-open** `[00:00 SP, 00:00 SP do dia seguinte)`
  reexpresso no fuso do banco, via `DateTimeZone` (sem `CONVERT_TZ`, sem tocar no banco).
- `api/logs.php` usa o helper na coluna `rh.created_at` e o default "hoje" passou de
  `date('Y-m-d')` (relógio do container) para `saoPauloToday($db)` (relógio do MySQL).
- `api/reanalysis_log.php` recebeu o mesmo tratamento.

O diagnóstico original segue abaixo, para referência.

---

O front **sempre** manda `date=`, calculado no fuso de São Paulo. Mas o PHP aplicava esse valor
como **dia do servidor**:

```php
// api/logs.php:30
$where = ["DATE(rh.created_at) = ?"];
```

E `rh.created_at` é `TIMESTAMP` gravado no **relógio do servidor**, que não é São Paulo.

**Consequência:** sempre que São Paulo e o servidor estiverem em dias de calendário diferentes,
a tela de Logs abre **vazia** mesmo havendo atividade. Exemplo com servidor em UTC:

| Evento | Relógio do servidor (UTC) | Data em SP | `date=` enviado | Aparece? |
|---|---|---|---|---|
| 20:00 SP | 2026-10-03 23:00 | 2026-10-03 | 2026-10-03 | ❌ (está em 10-03 no servidor: sim) |
| 22:00 SP | 2026-10-04 01:00 | 2026-10-04 | 2026-10-04 | ❌ (está em 10-04? não — gravou 10-04 01:00, casa) |

Reformulando com o caso que quebra de fato (servidor em UTC, 21:00–23:59 em SP):
um log criado às **21:30 de 03/10 em SP** é gravado como **00:30 de 04/10 no servidor**.
A tela de Logs pede `date=2026-10-03` (o "hoje" do usuário) → `DATE(created_at) = '2026-10-03'`
não casa nada. **O log do dia inteiro desaparece da tela.**

A sub-aba `reanalysis-log` tem **exatamente o mesmo defeito** (`api/reanalysis_log.php:42`).

**Correção recomendada:** a lista de logs é "do meu dia", então ou (a) o backend converte o dia
recebido do fuso de apresentação para o intervalo half-open correto no relógio do banco
(como `presenceDateFilter()` já faz), ou (b) o dia é derivado **do próprio MySQL**, para que
front e backend nunca discordem:

```php
// Opção (b) — nada de PHP no meio do caminho:
$filterDate = $_GET['date'] ?? $db->query("SELECT CURDATE()")->fetchColumn();
```

(a) é o correto se a intenção é "o dia do usuário em São Paulo"; (b) é o correto se a
intenção é "o dia do servidor". Hoje o front afirma a primeira e o backend entrega a segunda.

---

## 🟠 P2 — Front escreve timestamp com o relógio do **navegador**

Seis pontos geram `created_at`/`updated_at` no cliente, e o valor aparece na tela antes de o
servidor responder (linha otimista). Se a máquina do usuário não estiver em São Paulo, esse
valor fica errado **e** é formatado por `formatDateTime()` como se fosse São Paulo — ou seja,
o erro não se cancela, ele se soma.

| Local | O que faz |
|---|---|
| `app.js:3159` | linha otimista de reanálise: `now.getFullYear()/getMonth()/...` (hora **local**, provavelmente UTC) |
| `app.js:3348` | mesmo cálculo no lote de reanálises |
| `app.js:4361-4362` | `created_at`/`updated_at` de solicitação nova: `new Date().toISOString()` (**UTC explícito**) |
| `app.js:4479` | `updated_at` na edição de solicitação: `new Date().toISOString()` |
| `app.js:3159`/`3348` | string `"YYYY-MM-DD HH:MM:SS"` sem offset |

Note a **inconsistência interna**: `4348`+`3159` geram a mesma informação de dois jeitos
diferentes — uma em hora local, outra em UTC. As duas convivem no mesmo array `requests`.

**Impacto:** enquanto a resposta do servidor não chega, o usuário vê uma hora errada na
coluna "Criado em" / "Última análise". Em `periodic_analysis`, a linha otimista **também
define a ordenação do grupo**, então uma criação feita fora de SP pode posicionar a linha
no lugar errado da lista.

**Correção recomendada:** não gerar timestamp no front. Como a ordenação depende só de ordem
relativa, use um sentinela que ordene como "agora" mas não seja exibido
(ex.: `created_at: null` + tratar `null` como "agora" no comparador, ou manter o temp id
negativo que já existe). Se o valor precisar aparecer, gere-o **no formato de São Paulo**
para casar com `formatDateTime()`:

```js
// São Paulo, no formato que o backend usa no DEFAULT CURRENT_TIMESTAMP
function nowInSaoPaulo() {
  const p = new Intl.DateTimeFormat("sv-SE", {
    timeZone: "America/Sao_Paulo",
    year: "numeric", month: "2-digit", day: "2-digit",
    hour: "2-digit", minute: "2-digit", second: "2-digit",
  }).formatToParts(new Date()).reduce((a, x) => (a[x.type] = x.value, a), {});
  return `${p.year}-${p.month}-${p.day} ${p.hour}:${p.minute}:${p.second}`;
}
```

(sv-SE dá `YYYY-MM-DD HH:MM:SS` direto; `today()` em `app.js:201` já usa essa técnica para a data.)

---

## 🟠 P3 — `logs.php` não encontra a coluna `date` no `dedupe`

`api/logs.php:22` tem fallback `date('Y-m-d')` — o dia **do container PHP**, um terceiro
relógio, que pode divergir tanto do navegador quanto do MySQL. Some-se ao P1: o filtro de
Logs tem **três relógios candidatos** (navegador → PHP → MySQL) e nenhum deles é declarado
como autoritativo. O fallback quase nunca dispara (o front sempre manda), mas é uma
landmine para qualquer cliente que chame a API direto.

---

## 🟡 P4 — Pontos que dependem do fuso do navegador

| Local | Problema |
|---|---|
| `app.js:6548-6556` `setHeaderDate()` | `now.toLocaleDateString("pt-BR")` **sem** `timeZone` → usa o fuso da máquina. É o único display de data do app que ignora São Paulo. Inconsistente com `formatDateTime()`/`today()`, que fixam SP. |
| `app.js:4322-4324` | prazo padrão = `hoje + 7`: `d.setDate(d.getDate()+7)` sobre a data **local**, depois `toISOString()` (UTC). Dupla conversão; a data sugerida pode cair 1 dia fora perto da meia-noite. |
| `app.js:4361` / `4479` | `new Date().toISOString()` → hora **UTC**, renderizada por `formatDateTime()` **como SP**. Não se cancela. |
| `app.js:1201` | `formatPresenceDateTime()` faz `formatDateTime(d.toISOString())` — o `toISOString()` é UTC e o `formatDateTime` converte para SP. Este está **correto** (é o padrão), só vale registrar que a corretude depende do par inteiro. |

---

## 🟡 P5 — Correção de presença depende de uma conexão que talvez não exista

`api/presence.php:183` devolve o offset para o front:

```sql
TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), NOW()) AS tz_offset_minutes
```

e `app.js` o guarda em `serverUtcOffsetMinutes` (`app.js:1250`, `4104`, `6001`), usado por
`parseServerDateTime()` (`app.js:1189`) para converter o DATETIME **do relógio do banco** em
instante absoluto — e daí para São Paulo.

O problema: **`serverUtcOffsetMinutes` só é preenchido dentro de `presenceBeat()`**
(`app.js:1249`). E `presenceBeat()` não roda se o usuário estiver com `track_presence = 0`
(`app.js:1285` `startPresence()` aborta para não rastreados). Nesse caso o offset fica `null`
→ `parseServerDateTime` faz `ms - 0` → interpreta o relógio do servidor **como se fosse UTC**
→ o "Online desde" na tabela de usuários e o Registro de Presença saem deslocados.

Agravante: o offset é capturado uma vez e **nunca expira**. Se o servidor de banco entrar em
horário de verão (ou o `@@time_zone` mudar), o valor cacheado fica obsoleto pelo resto da sessão.

**Correção recomendada:** tratar o offset como dado do endpoint, não como efeito colateral do
heartbeat. `presenceLog` e `users?action=list` já devolvem o campo — basta lerem-no também
(hoje `presenceLog` lê em `6000`, `loadPresenceOnline` lê em `4104`; o buraco é o fallback
`null`). Enquanto `null`, exibir com aviso em vez de assumir UTC.

---

## 🟡 P6 — Outros sinais

- `api/presence.php:576` documenta que o dia do Registro de Presença é **o dia do servidor**
  ("é o fuso em que `entered_at` foi gravado") e o front formata o display em SP. É uma escolha
  coerente, mas significa que **o filtro "De/Até" da tela fala um fuso e a coluna exibida fala
  outro** — o usuário que filtra por "01/10" pode ver linhas datadas de "30/09" na tabela.
- `api/settings.php:18` — `updated_at ... ON UPDATE CURRENT_TIMESTAMP` (relógio do banco). OK.
- `api/requests.php:370` e `:446` — `created_at`/`updated_at` **corretamente omitidos** do
  INSERT/UPDATE; quem grava é o `DEFAULT`. É o padrão certo; os P2 acima são só a cópia
  otimista do front.
- `api/realtime.php` e `api/webhook.php` usam `date('c')`/`time()` (relógio PHP) — **aceitável**:
  são metadados de mensagem efêmera (SSE / arquivo temporário), não vão para o banco.
- `api/auth.php:43` — senha em texto plano (`$user['password'] !== $password`). Fora do escopo
  desta auditoria, mas é risco mais grave que qualquer fuso.

---

## O que está correto e não deve ser mexido

- `presenceDateFilter()` (`api/presence.php:578`) — monta intervalo **half-open** no MySQL com
  `DATE_ADD(?, INTERVAL 1 DAY)` e mantém a sargabilidade do índice. É o único filtro de data do
  backend que **não** mistura relógios. **Este é o modelo a copiar para `logs.php`.**
- `presenceSettings()` — `UNIX_TIMESTAMP()` e `UTC_TIMESTAMP()` lidos do MySQL; comenta
  explicitamente "nunca o do PHP".
- `app.js:201 today()` — `Intl.DateTimeFormat` com `timeZone: "America/Sao_Paulo"`.
- `app.js:140 formatDateTime()` — sempre formata em `America/Sao_Paulo` no display.
- Uso de `DATETIME` (não `TIMESTAMP`) em `presence_*`: evita a conversão por `@@time_zone`
  e o teto de 2038. A decisão está documentada no `schema.sql`.

---

## Resumo executivo

| # | Onde | Severidade | Efeito | Status |
|---|---|---|---|---|
| P1 | `logs.php:22,30` + `app.js:5693` | 🔴 Alto | Tela de Logs vazia em ~1/8 do dia; log do dia some | ✅ corrigido |
| P1b | `reanalysis_log.php:42` | 🔴 Alto | Mesmo defeito na aba de reanálise | ✅ corrigido |
| P2 | `app.js:3159,3348,4361,4479` | 🟠 Médio | Hora visível errada fora de SP; ordenação indevida | ✅ corrigido |
| P3 | `logs.php:22` fallback | 🟠 Médio | Terceiro relógio candidato, não declarado | ✅ corrigido |
| P4 | `app.js:6548`, `4322`, `4361` | 🟡 Baixo | Header e prazo padrão seguem o fuso da máquina | ✅ corrigido |
| P5 | `app.js:1250` + `1285` | 🟡 Baixo | Presença deslocada p/ usuário não rastreado; offset obsoleto na sessão | ✅ corrigido |
| P6 | `presence.php:576` | 🟡 Baixo | Filtro (dia servidor) ≠ coluna exibida (dia SP) | ⬜ em aberto (decisão de produto) |

## O que foi implementado (2026-10-02)

**`api/config.php`** — novos helpers, todos sem tocar no banco:
- `saoPauloOffsetSeconds($dia)`, `dateParam($chave)`
- `saoPauloDayRange($dia)` — dia de SP → intervalo no fuso do banco (`setTimezone`)
- `applySaoPauloDayFilter(&$where, &$params, $coluna, $dia)` — half-open + sargável
- `saoPauloToday($db)` — "hoje" derivado do relógio do MySQL
- `dbTimezoneName()` / `dbTimezone()` — descobre o fuso do banco (`@@session.time_zone`, com
  fallback para offset via `TIMESTAMPDIFF`); `APP_DB_TIMEZONE` força manualmente
- `checkConnectionTimezone()` — avisa no log se o banco anunciar um fuso que o PHP não conhece

**`api/logs.php` e `api/reanalysis_log.php`** — filtro de dia pelo helper; default do "hoje" pelo
relógio do MySQL.

**`api/requests.php`** — o POST passou a devolver `row` (linha re-lida), para o front substituir a
linha otimista inteira em vez de manter data inventada.

**`app.js`** — `PENDING_DATE` + `compareDateDesc` + `nowSaoPaulo`; os 4 pontos de geração de
timestamp no front usam o sentinela; os 9 comparadores de `created_at` passaram a `compareDateDesc`;
`setHeaderDate` com `timeZone`; prazo padrão sem dupla conversão; `parseServerDateTime` exige offset
e `ensureServerUtcOffset()` busca o offset fora do heartbeat.

## Verificação

- `node --check app.js` ✅ · PHP lint 20/20 ✅ (com controle)
- Novos: `.verify/test-timezone-range.cjs` (15), `test-pending-date.cjs` (24),
  `test-presence-offset.cjs` (12), `check-logs-php.cjs` (22)
- Bateria completa: **21 arquivos, 0 com problema** (antes: 20 arquivos, 3 quebras)
- Prévia visual: `.workbuddy-ai/preview-timezone-fix.html` + `.verify/visual-timezone.cjs`

**Prioridade restante:** só o P6, que é decisão de produto (o Registro de Presença filtra pelo dia
do servidor e exibe em SP; alinhar os dois exigiria escolher qual é a verdade da tela).
