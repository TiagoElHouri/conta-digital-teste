<?php

declare(strict_types=1);

namespace App\Service;

use Hyperf\DbConnection\Db;
use Hyperf\Stringable\Str;
use Psr\Log\LoggerInterface;

class WithdrawService
{
    public function __construct(
        private LoggerInterface $logger,
    ) {}

    /**
     * @return array{status:string, withdraw_id:string, processed_at?:string, scheduled_for?:string, reason?:string}
     */
    public function createWithdraw(string $requestId, string $accountId, array $payload): array
    {
        $method = $payload['method'];                 // PIX
        $amountStr = (string) $payload['amount'];     // manter string ajuda com DECIMAL
        $pixType = $payload['pix']['type'];
        $pixKey  = $payload['pix']['key'];
        $schedule = $payload['schedule'] ?? null;

        $scheduled = false;
        $scheduledFor = null;

        if ($schedule !== null) {
            $scheduledFor = $this->parseSchedule($schedule);

            // não pode agendar no passado
            $now = new \DateTimeImmutable('now');
            if ($scheduledFor <= $now) {
                throw new \InvalidArgumentException('schedule must be in the future');
            }

            $scheduled = true;
        }

        $withdrawId = (string) Str::uuid();
        $nowStr = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');

        $this->logger->info('withdraw.create.start', [
            'request_id' => $requestId,
            'withdraw_id' => $withdrawId,
            'account_id' => $accountId,
            'method' => $method,
            'amount' => $amountStr,
            'scheduled' => $scheduled,
            'scheduled_for' => $scheduledFor?->format('Y-m-d H:i:s'),
            'pix_type' => $pixType,
        ]);

        return Db::transaction(function () use (
            $requestId, $withdrawId, $accountId, $method, $amountStr, $pixType, $pixKey,
            $scheduled, $scheduledFor, $nowStr
        ) {
            // 1) registra o saque
            Db::table('account_withdraw')->insert([
                'id' => $withdrawId,
                'account_id' => $accountId,
                'method' => $method,
                'amount' => $amountStr,
                'scheduled' => $scheduled ? 1 : 0,
                'scheduled_for' => $scheduledFor ? $scheduledFor->format('Y-m-d H:i:s') : null,
                'done' => 0,
                'error' => 0,
                'error_reason' => null,

                'processing' => 0,
                'processing_started_at' => null,
                'processed_at' => null,

                'created_at' => $nowStr,
                'updated_at' => $nowStr,
            ]);

            Db::table('account_withdraw_pix')->insert([
                'account_withdraw_id' => $withdrawId,
                'type' => $pixType,
                'key' => $pixKey,
                'created_at' => $nowStr,
                'updated_at' => $nowStr,
            ]);

            // 2) se agendado: não executa aqui
            if ($scheduled) {
                $this->logger->info('withdraw.create.scheduled', [
                    'request_id' => $requestId,
                    'withdraw_id' => $withdrawId,
                    'account_id' => $accountId,
                    'scheduled_for' => $scheduledFor->format('Y-m-d H:i:s'),
                ]);

                return [
                    'status' => 'scheduled',
                    'withdraw_id' => $withdrawId,
                    'scheduled_for' => $scheduledFor->format('Y-m-d H:i:s'),
                ];
            }

            // 3) imediato: débito atômico (não permite saldo negativo)
            $affected = Db::update(
                'UPDATE account SET balance = balance - ? WHERE id = ? AND balance >= ?',
                [$amountStr, $accountId, $amountStr]
            );

            if ($affected !== 1) {
                // marca como finalizado com erro (sem saldo)
                Db::table('account_withdraw')->where('id', $withdrawId)->update([
                    'done' => 1,
                    'error' => 1,
                    'error_reason' => 'insufficient_funds',
                    'processed_at' => $nowStr,
                    'updated_at' => $nowStr,
                ]);

                $this->logger->warning('withdraw.create.failed_insufficient_funds', [
                    'request_id' => $requestId,
                    'withdraw_id' => $withdrawId,
                    'account_id' => $accountId,
                    'amount' => $amountStr,
                ]);

                return [
                    'status' => 'failed',
                    'withdraw_id' => $withdrawId,
                    'reason' => 'insufficient_funds',
                ];
            }

            // marca como concluído
            Db::table('account_withdraw')->where('id', $withdrawId)->update([
                'done' => 1,
                'error' => 0,
                'processed_at' => $nowStr,
                'updated_at' => $nowStr,
            ]);

            $this->logger->info('withdraw.create.done', [
                'request_id' => $requestId,
                'withdraw_id' => $withdrawId,
                'account_id' => $accountId,
                'amount' => $amountStr,
                'processed_at' => $nowStr,
            ]);

            return [
                'status' => 'done',
                'withdraw_id' => $withdrawId,
                'processed_at' => $nowStr,
                'pix_email' => $pixKey,
                'amount' => $amountStr,
            ];
        });
    }

    private function parseSchedule(string $schedule): \DateTimeImmutable
    {
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i', $schedule);
        if (! $dt) {
            throw new \InvalidArgumentException('schedule must be in format Y-m-d H:i');
        }
        return $dt;
    }
}
