-- ============================================
-- ArticleHub — MySQL Database Schema
-- Execute este script no phpMyAdmin
-- ============================================

-- Cria o banco de dados (se ainda não existir)
CREATE DATABASE IF NOT EXISTS articlehub
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE articlehub;

-- ============================================
-- TABELA: users
-- Armazena todos os usuários do sistema
-- ============================================
CREATE TABLE users (
  id          INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(150)    NOT NULL,
  email       VARCHAR(200)    NOT NULL UNIQUE,
  password    VARCHAR(255)    NOT NULL COMMENT 'Hash bcrypt',
  role        ENUM('admin','gestor','revisor','redator') NOT NULL DEFAULT 'redator',
  active      TINYINT(1)      NOT NULL DEFAULT 1,
  created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  INDEX idx_users_email  (email),
  INDEX idx_users_role   (role),
  INDEX idx_users_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================
-- TABELA: domains
-- Blogs / domínios cadastrados
-- ============================================
CREATE TABLE domains (
  id          INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
  blog_name   VARCHAR(150)    NOT NULL,
  url         VARCHAR(500)    NOT NULL,
  niche       VARCHAR(100)    NOT NULL,
  color       VARCHAR(7)      NOT NULL DEFAULT '#7f5af0' COMMENT 'Cor hexadecimal',
  active      TINYINT(1)      NOT NULL DEFAULT 1,
  created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  INDEX idx_domains_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================
-- TABELA: languages
-- Idiomas disponíveis para solicitações
-- ============================================
CREATE TABLE languages (
  id          INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(100)    NOT NULL,
  code        VARCHAR(10)     NOT NULL UNIQUE,
  active      TINYINT(1)      NOT NULL DEFAULT 1,
  created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  INDEX idx_languages_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================
-- TABELA: niches
-- Nichos disponíveis para solicitações
-- ============================================
CREATE TABLE niches (
  id          INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(100)    NOT NULL,
  active      TINYINT(1)      NOT NULL DEFAULT 1,
  created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  INDEX idx_niches_active (active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================
-- TABELA: requests
-- Solicitações de artigos
-- ============================================
CREATE TABLE requests (
  id                 INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
  keyword            VARCHAR(300)    NOT NULL,
  domain_id          INT UNSIGNED    NOT NULL COMMENT 'Blog destino',
  writer_id          INT UNSIGNED    NULL     COMMENT 'Redator atribuído (NULL = a definir)',
  requested_by_id    INT UNSIGNED    NOT NULL COMMENT 'Gestor que criou',
  status             ENUM('pending','in-progress','review','done','published','revisado','deleted') NOT NULL DEFAULT 'pending',
  priority           ENUM('alta','media','baixa') NOT NULL DEFAULT 'media',
  wordcount          VARCHAR(20)     NOT NULL COMMENT 'Ex: 800-1200',
  deadline           DATE            NOT NULL,
  instructions       TEXT            NULL,
  language           VARCHAR(10)     NOT NULL DEFAULT 'pt-br' COMMENT 'Idioma: pt-br, en, es',
  purpose            VARCHAR(20)     NOT NULL DEFAULT 'conteudo' COMMENT 'Finalidade: conteudo, arbitragem',
  content_type       VARCHAR(20)     NOT NULL DEFAULT 'artigo' COMMENT 'Tipo: artigo, pagina',
  niche_id           INT UNSIGNED    NULL     COMMENT 'Nicho da solicitação',
  published_url      VARCHAR(500)    NULL     COMMENT 'URL do artigo publicado',
  wp_edit_url        VARCHAR(500)    NULL     COMMENT 'URL de edição do WordPress',
  status_compliance  VARCHAR(15)     NULL     COMMENT 'Análise de compliance: nao_analisado, aprovado, reprovado, revisar, falha',
  resumo_analise     TEXT            NULL     COMMENT 'Resumo feito pela análise de compliance',
  created_at         TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  INDEX idx_requests_status      (status),
  INDEX idx_requests_priority    (priority),
  INDEX idx_requests_deadline    (deadline),
  INDEX idx_requests_writer      (writer_id),
  INDEX idx_requests_requester   (requested_by_id),
  INDEX idx_requests_domain      (domain_id),
  INDEX idx_requests_niche       (niche_id),

  CONSTRAINT fk_requests_domain
    FOREIGN KEY (domain_id)       REFERENCES domains(id) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_requests_writer
    FOREIGN KEY (writer_id)       REFERENCES users(id)   ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_requests_requester
    FOREIGN KEY (requested_by_id) REFERENCES users(id)   ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_requests_niche
    FOREIGN KEY (niche_id)        REFERENCES niches(id)  ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================
-- TABELA: request_history
-- Histórico de alterações de status e logs
-- ============================================
CREATE TABLE request_history (
  id          INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
  request_id  INT UNSIGNED    NOT NULL,
  user_id     INT UNSIGNED    NOT NULL COMMENT 'Quem fez a alteração',
  action      VARCHAR(50)     NOT NULL COMMENT 'status_change, published, message, edit',
  changes     JSON            NULL     COMMENT 'Mudanças (from/to) em formato JSON',
  url         VARCHAR(500)    NULL     COMMENT 'Se fornecida URL no publish',
  created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_history_request (request_id),
  INDEX idx_history_user    (user_id),
  INDEX idx_history_action  (action),

  CONSTRAINT fk_history_request
    FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_history_user
    FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================
-- TABELA: notifications
-- Notificações internas do sistema
-- ============================================
CREATE TABLE notifications (
  id          INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED    NOT NULL COMMENT 'Destinatário',
  type        VARCHAR(50)     NOT NULL COMMENT 'new_request, status_changed, new_message',
  message     VARCHAR(500)    NOT NULL,
  related_id  INT UNSIGNED    NULL     COMMENT 'ID do item relacionado',
  is_read     TINYINT(1)      NOT NULL DEFAULT 0,
  created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_notif_user    (user_id),
  INDEX idx_notif_read    (is_read),
  INDEX idx_notif_created (created_at),

  CONSTRAINT fk_notif_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================
-- TABELA: messages
-- Mensagens internas entre usuários
-- ============================================
CREATE TABLE messages (
  id          INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
  from_id     INT UNSIGNED    NOT NULL COMMENT 'Remetente',
  to_id       INT UNSIGNED    NOT NULL COMMENT 'Destinatário',
  subject     VARCHAR(300)    NOT NULL,
  body        TEXT            NOT NULL,
  is_read     TINYINT(1)      NOT NULL DEFAULT 0,
  created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_msg_from    (from_id),
  INDEX idx_msg_to      (to_id),
  INDEX idx_msg_read    (is_read),
  INDEX idx_msg_created (created_at),

  CONSTRAINT fk_msg_from
    FOREIGN KEY (from_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_msg_to
    FOREIGN KEY (to_id)   REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================
-- TABELA: user_preferences
-- Preferências do usuário (tema, sidebar, etc.)
-- ============================================
CREATE TABLE user_preferences (
  id                INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
  user_id           INT UNSIGNED    NOT NULL UNIQUE,
  theme             ENUM('dark','light') NOT NULL DEFAULT 'dark',
  sidebar_collapsed TINYINT(1)      NOT NULL DEFAULT 0,
  updated_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  CONSTRAINT fk_pref_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ============================================
-- DADOS INICIAIS: Usuários
-- Senhas usando password_hash() do PHP
-- Por enquanto inseridas em texto limpo
-- (substituir por hash bcrypt ao configurar o backend)
-- ============================================
INSERT INTO users (id, name, email, password, role, active) VALUES
  (1, 'Admin Sistema',     'admin@hub.com',    'admin123',   'admin',   1),
  (2, 'Fernando Carvalho', 'fernando@hub.com', 'gestor123',  'gestor',  1),
  (3, 'Lucas Oliveira',    'lucas@hub.com',    'gestor123',  'gestor',  1),
  (4, 'Ana Silva',         'ana@hub.com',      'redator123', 'redator', 1),
  (5, 'Carlos Mendes',     'carlos@hub.com',   'redator123', 'redator', 1),
  (6, 'Juliana Costa',     'juliana@hub.com',  'redator123', 'redator', 1),
  (7, 'Rafael Lima',       'rafael@hub.com',   'redator123', 'redator', 1),
  (8, 'Mariana Santos',    'mariana@hub.com',  'redator123', 'redator', 1),
  (9, 'Paula Revisor',     'revisor@hub.com',  'revisor123', 'revisor', 1);


-- ============================================
-- DADOS INICIAIS: Domínios / Blogs
-- ============================================
INSERT INTO domains (id, blog_name, url, niche, color, active) VALUES
  (1, 'Finanças Plus',  'https://financasplus.com.br',  'Finanças',    '#7f5af0', 1),
  (2, 'Saúde Total',    'https://saudetotal.com.br',    'Saúde',       '#2cb67d', 1),
  (3, 'Tech Review BR', 'https://techreviewbr.com.br',  'Tecnologia',  '#39a0ed', 1),
  (4, 'Mundo Pet',      'https://mundopet.com.br',      'Pets',        '#f0a500', 1),
  (5, 'Casa & Decor',   'https://casaedecor.com.br',    'Decoração',   '#e53170', 1);


-- ============================================
-- DADOS INICIAIS: Solicitações
-- ============================================
INSERT INTO requests (id, keyword, domain_id, writer_id, requested_by_id, status, priority, wordcount, deadline, instructions, created_at) VALUES
  (1,  'melhores investimentos renda fixa 2026',
       1, 4, 2, 'in-progress', 'alta', '1800-2500', '2026-02-20',
       'Focar em CDBs, LCIs e Tesouro Direto. Incluir comparativo de rentabilidade. Tom educativo e acessível. CTA para calculadora de investimentos.',
       '2026-02-10 10:00:00'),

  (2,  'como emagrecer com saúde em 2026',
       2, 5, 2, 'pending', 'alta', '1200-1800', '2026-02-22',
       'Abordar dieta e exercício. Evitar promessas milagrosas. Citar fontes médicas confiáveis.',
       '2026-02-12 10:00:00'),

  (3,  'iphone 17 review completo',
       3, 6, 3, 'review', 'media', '1200-1800', '2026-02-18',
       'Review hands-on. Incluir benchmarks, comparativos com concorrentes e fotos.',
       '2026-02-08 10:00:00'),

  (4,  'ração natural para cachorro',
       4, 7, 2, 'done', 'baixa', '800-1200', '2026-02-15',
       'Listar prós e contras da alimentação natural. Incluir receitas simples.',
       '2026-02-05 10:00:00'),

  (5,  'tendências decoração sala 2026',
       5, 8, 3, 'in-progress', 'media', '1200-1800', '2026-02-25',
       'Mostrar tendências minimalistas e maximalistas.',
       '2026-02-11 10:00:00'),

  (6,  'cartão de crédito sem anuidade',
       1, 4, 2, 'done', 'alta', '1800-2500', '2026-02-14',
       'Comparar os 10 melhores cartões sem anuidade. Usar tabela comparativa.',
       '2026-02-03 10:00:00'),

  (7,  'suplementos para ganho muscular',
       2, 5, 3, 'pending', 'media', '800-1200', '2026-02-28',
       'Whey, creatina, BCAA. Incluir dosagens e contraindicações.',
       '2026-02-14 10:00:00'),

  (8,  'melhor notebook custo benefício',
       3, 6, 2, 'done', 'baixa', '1200-1800', '2026-02-12',
       'Top 8 notebooks até R$4.000. Incluir specs e links de compra.',
       '2026-02-01 10:00:00'),

  (9,  'como adestrar filhote de gato',
       4, 8, 3, 'pending', 'baixa', '500-800', '2026-03-01',
       'Dicas práticas para donos de primeira viagem.',
       '2026-02-13 10:00:00'),

  (10, 'como montar home office pequeno',
       5, 7, 2, 'review', 'media', '800-1200', '2026-02-19',
       'Soluções para espaços de até 6m².',
       '2026-02-09 10:00:00');


-- ============================================
-- DADOS INICIAIS: Histórico das solicitações
-- ============================================
INSERT INTO request_history (request_id, user_id, action, changes, created_at) VALUES
  (1,  2, 'create', NULL, '2026-02-10 10:00:00'),
  (2,  2, 'create', NULL, '2026-02-12 10:00:00'),
  (3,  3, 'create', NULL, '2026-02-08 10:00:00'),
  (4,  2, 'create', NULL, '2026-02-05 10:00:00'),
  (5,  3, 'create', NULL, '2026-02-11 10:00:00'),
  (6,  2, 'create', NULL, '2026-02-03 10:00:00'),
  (7,  3, 'create', NULL, '2026-02-14 10:00:00'),
  (8,  2, 'create', NULL, '2026-02-01 10:00:00'),
  (9,  3, 'create', NULL, '2026-02-13 10:00:00'),
  (10, 2, 'create', NULL, '2026-02-09 10:00:00');


-- ============================================
-- DADOS INICIAIS: Preferências (todos com padrão)
-- ============================================
INSERT INTO user_preferences (user_id, theme, sidebar_collapsed) VALUES
  (1, 'dark', 0),
  (2, 'dark', 0),
  (3, 'dark', 0),
  (4, 'dark', 0),
  (5, 'dark', 0),
  (6, 'dark', 0),
  (7, 'dark', 0),
  (8, 'dark', 0),
  (9, 'dark', 0);


-- ============================================
-- DADOS INICIAIS: Idiomas
-- ============================================
INSERT INTO languages (id, name, code, active) VALUES
  (1, 'Português (BR)', 'pt-br', 1),
  (2, 'Inglês',         'en',    1),
  (3, 'Espanhol',       'es',    1);


-- ============================================
-- DADOS INICIAIS: Nichos
-- ============================================
INSERT INTO niches (id, name, active) VALUES
  (1, 'Finanças',    1),
  (2, 'Saúde',       1),
  (3, 'Tecnologia',  1),
  (4, 'Pets',        1),
  (5, 'Decoração',   1),
  (6, 'Esportes',    1),
  (7, 'Educação',    1),
  (8, 'Moda',        1),
  (9, 'Viagens',     1),
  (10, 'Gastronomia', 1);


-- ============================================
-- TABELA: compliance_history
-- Historico da analise de ia
-- ============================================
CREATE TABLE compliance_history (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    request_id INT UNSIGNED NOT NULL,
    status_compliance VARCHAR(15) NOT NULL,
    resumo_analise TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY fk_compliance_history (request_id),
    CONSTRAINT fk_compliance_history
        FOREIGN KEY (request_id) REFERENCES requests(id)
        ON DELETE RESTRICT
        ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;