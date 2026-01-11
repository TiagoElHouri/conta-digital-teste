# Case Técnico — Saque PIX (Hyperf + MySQL + Mailhog)

API para realizar **saque PIX** (imediato) e **agendamento de saque** (processado via cron), com persistência em MySQL e notificação por e-mail via Mailhog.

## Stack
- PHP + Hyperf (Swoole)
- MySQL 8
- Mailhog
- Docker + Docker Compose

**Portas (padrão):**
- API: http://127.0.0.1:9501
- Mailhog (UI): http://127.0.0.1:8025
- MySQL (host): 127.0.0.1:3307 (container: mysql:3306)

---

## Como rodar

### 1) Subir os containers
```bash
docker compose up -d --build
```

### 2) Rodar migrations
```bash
docker compose exec app php bin/hyperf.php migrate
```

### 3) Popular dados de teste (seed)
```bash
docker compose exec app php bin/hyperf.php seed:test-data
```

### 4) Healthcheck
```bash
curl http://127.0.0.1:9501/ping
```

---

## Endpoint principal

### POST /account/{accountId}/balance/withdraw

**Regras:**
- `schedule = null` => processa **imediatamente** (deduz saldo, grava withdraw, marca `done`, envia e-mail)
- `schedule != null` => **agenda** (grava pendente, não deduz saldo, não envia e-mail)
- Não permite agendar no passado.
- Não permite sacar acima do saldo (saldo nunca fica negativo).

**Exemplo (imediato):**
```bash
curl -i -X POST "http://127.0.0.1:9501/account/<ACCOUNT_ID>/balance/withdraw" \
  -H "Content-Type: application/json" \
  -d '{
    "method": "PIX",
    "amount": "10.50",
    "pix": { "type": "email", "key": "cliente@exemplo.com" },
    "schedule": null
  }'
```

**Exemplo (agendado):**
```bash
curl -i -X POST "http://127.0.0.1:9501/account/<ACCOUNT_ID>/balance/withdraw" \
  -H "Content-Type: application/json" \
  -d '{
    "method": "PIX",
    "amount": "12.34",
    "pix": { "type": "email", "key": "cliente@exemplo.com" },
    "schedule": "2099-01-01 10:00"
  }'
```

---

## E-mail (Mailhog)

- UI: http://127.0.0.1:8025
- O e-mail é enviado **somente quando o saque é efetivado** (`done=1` e `error=0`).

Variáveis usadas (no `.env`):
- `MAILER_DSN` (ex.: `smtp://mailhog:1025`)
- `MAIL_FROM_ADDRESS`
- `MAIL_FROM_NAME`

---

## Cron / Processar saques agendados

Command:
```bash
docker compose exec app php bin/hyperf.php withdraw:process-scheduled
```

O cron:
- busca saques pendentes com `scheduled_for <= now`
- tenta debitar saldo de forma atômica
- se sucesso: marca `done=1` e envia e-mail
- se saldo insuficiente: marca `done=1`, `error=1`, `error_reason=insufficient_funds` (sem e-mail)

---

## O que foi feito além do pedido (resumo)
- Colunas extras para auditoria/controle: `created_at`, `updated_at`, `processed_at`, `processing`, `processing_started_at`.
- Índices para melhorar consultas por conta e varredura do cron.
- Débito atômico no MySQL para evitar race condition e saldo negativo.
- Envio de e-mail compatível com HTTP e CLI (coroutine no Swoole).

---

## Comandos úteis

Logs:
```bash
docker compose logs -f app
```

Reset total (inclui banco):
```bash
docker compose down -v --remove-orphans
docker compose up -d --build
```
