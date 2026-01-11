# Case Técnico — Conta Digital (Hyperf + MySQL + Mailhog)

API para simular saques de uma conta (imediato e agendado), com persistência em MySQL, envio de e-mail via SMTP (Mailhog) e processamento de agendados via cron/command.

---

## Sumário
- [Stack](#stack)
- [Requisitos atendidos do case](#requisitos-atendidos-do-case)
- [Extras implementados (além do pedido)](#extras-implementados-além-do-pedido)
- [Pré-requisitos](#pré-requisitos)
- [Como rodar o projeto](#como-rodar-o-projeto)
- [Banco de dados: migrations](#banco-de-dados-migrations)
- [Popular dados de teste (seed)](#popular-dados-de-teste-seed)
- [Endpoints](#endpoints)
- [Exemplos de respostas JSON](#exemplos-de-respostas-json)
- [Testes rápidos (cURL)](#testes-rápidos-curl)
- [E-mail (Mailhog)](#e-mail-mailhog)
- [Cron / Processamento de agendados](#cron--processamento-de-agendados)
- [Decisões técnicas](#decisões-técnicas)
- [Logs e observabilidade](#logs-e-observabilidade)
- [Troubleshooting](#troubleshooting)
- [Checklist de validação rápida](#checklist-de-validação-rápida)

---

## Stack
- **PHP + Hyperf** (Swoole)
- **MySQL 8**
- **Mailhog** (SMTP + UI Web)
- Docker + Docker Compose

Portas padrão (conforme `docker-compose.yml`):
- API: `http://127.0.0.1:9501`
- Mailhog UI: `http://127.0.0.1:8025`
- MySQL externo: `127.0.0.1:3307` (internamente no docker: `mysql:3306`)

---

## Requisitos atendidos do case
- Tabelas: `account`, `account_withdraw`, `account_withdraw_pix`
- Endpoint:
  - `POST /account/{accountId}/balance/withdraw`
- Regras:
  - `method=PIX`
  - `pix.type=email` e `pix.key` deve ser e-mail válido
  - `schedule=null` ⇒ saque imediato
  - `schedule != null` ⇒ apenas agenda (não debita e não envia e-mail)
  - agendamento não pode ser no passado
  - saldo não pode ficar negativo
- E-mail:
  - envio após saque **efetivado**
- Cron:
  - processa saques agendados quando `scheduled_for <= now`

---

## Extras implementados (além do pedido)
Melhorias para qualidade (performance, observabilidade, escalabilidade horizontal e segurança):

### Banco / Schema
- **Campos adicionais**:
  - `created_at`, `updated_at` (auditoria)
  - `processed_at` (data/hora efetiva do processamento)
  - `processing`, `processing_started_at` (claim/lock de job via DB para múltiplas instâncias)
- **Índices**:
  - por conta (`account_id`, `created_at`)
  - para cron (`scheduled`, `done`, `processing`, `scheduled_for`)

### Regra de saldo (concorrência)
- **Débito atômico**:
  - `UPDATE account SET balance = balance - ? WHERE id = ? AND balance >= ?`
  - impede saldo negativo mesmo com múltiplas requisições simultâneas

### API / Segurança / Observabilidade
- **Validação forte** do payload (method, amount, pix, schedule)
- **Respostas padronizadas** (sucesso/erro) e sem vazamento de stack trace
- **Logs estruturados** com `request_id`, `withdraw_id`, `account_id`, status e `error_reason`
- **E-mail compatível com HTTP + CLI** (coroutine no Swoole)

---

## Pré-requisitos
- Docker
- Docker Compose

---

## Como rodar o projeto

### 1) Subir containers
```bash
docker compose up -d --build
