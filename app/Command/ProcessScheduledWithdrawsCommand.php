<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\WithdrawEmailNotifier;
use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\DbConnection\Db;
use Psr\Log\LoggerInterface;

#[Command(name: 'withdraw:process-scheduled', description: 'Processa saques agendados prontos para execução.')]
class ProcessScheduledWithdrawsCommand extends HyperfCommand
{
    public function __construct(
        private LoggerInterface $logger,
        private WithdrawEmailNotifier $emailNotifier,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $requestId = 'cron-' . date('YmdHis');
        $now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');

        $this->logger->info('cron.withdraw.start', [
            'request_id' => $requestId,
            'now' => $now,
        ]);

        // Ajuste de lote para não carregar demais
        $batchSize = 50;

        // Pega candidatos (sem lock ainda)
        $candidates = Db::table('account_withdraw')
            ->select(['id', 'account_id', 'amount'])
            ->where('scheduled', 1)
            ->where('done', 0)
            ->where('processing', 0)
            ->where('scheduled_for', '<=', $now)
            ->orderBy('scheduled_for', 'asc')
            ->limit($batchSize)
            ->get();

        if ($candidates->isEmpty()) {
            $this->logger->info('cron.withdraw.noop', [
                'request_id' => $requestId,
            ]);
            return 0;
        }

        $processed = 0;

        foreach ($candidates as $row) {
            $withdrawId = (string) $row->id;

            // 1) Claim atômico (impede 2 instâncias processarem o mesmo item)
            $claimed = Db::table('account_withdraw')
                ->where('id', $withdrawId)
                ->where('processing', 0)
                ->where('done', 0)
                ->update([
                    'processing' => 1,
                    'processing_started_at' => $now,
                    'updated_at' => $now,
                ]);

            if ($claimed !== 1) {
                continue; // alguém já pegou
            }

            try {
                $this->processOne($requestId, $withdrawId, $now);
                $processed++;
            } catch (\Throwable $e) {
                // Estratégia: loga e libera para tentar novamente depois
                $this->logger->error('cron.withdraw.process.exception', [
                    'request_id' => $requestId,
                    'withdraw_id' => $withdrawId,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);

                Db::table('account_withdraw')
                    ->where('id', $withdrawId)
                    ->update([
                        'processing' => 0,
                        'processing_started_at' => null,
                        'updated_at' => $now,
                    ]);
            }
        }

        $this->logger->info('cron.withdraw.finish', [
            'request_id' => $requestId,
            'processed' => $processed,
        ]);

        return 0;
    }

    private function processOne(string $requestId, string $withdrawId, string $now): void
    {
        // Carrega withdraw + pix
        $withdraw = Db::table('account_withdraw')->where('id', $withdrawId)->first();
        if (! $withdraw) {
            return;
        }

        $pix = Db::table('account_withdraw_pix')->where('account_withdraw_id', $withdrawId)->first();

        $accountId = (string) $withdraw->account_id;
        $amount = (string) $withdraw->amount;
        $pixEmail = $pix ? (string) $pix->key : null;

        $this->logger->info('cron.withdraw.process.start', [
            'request_id' => $requestId,
            'withdraw_id' => $withdrawId,
            'account_id' => $accountId,
            'amount' => $amount,
        ]);

        Db::transaction(function () use ($requestId, $withdrawId, $accountId, $amount, $pixEmail, $now) {
            // Tenta debitar saldo
            $affected = Db::update(
                'UPDATE account SET balance = balance - ? WHERE id = ? AND balance >= ?',
                [$amount, $accountId, $amount]
            );

            if ($affected !== 1) {
                // Sem saldo: marca erro e finaliza
                Db::table('account_withdraw')->where('id', $withdrawId)->update([
                    'done' => 1,
                    'error' => 1,
                    'error_reason' => 'insufficient_funds',
                    'processed_at' => $now,
                    'processing' => 0,
                    'processing_started_at' => null,
                    'updated_at' => $now,
                ]);

                $this->logger->warning('cron.withdraw.process.insufficient_funds', [
                    'request_id' => $requestId,
                    'withdraw_id' => $withdrawId,
                    'account_id' => $accountId,
                    'amount' => $amount,
                ]);

                return;
            }

            // OK: finaliza
            Db::table('account_withdraw')->where('id', $withdrawId)->update([
                'done' => 1,
                'error' => 0,
                'error_reason' => null,
                'processed_at' => $now,
                'processing' => 0,
                'processing_started_at' => null,
                'updated_at' => $now,
            ]);

            $this->logger->info('cron.withdraw.process.done', [
                'request_id' => $requestId,
                'withdraw_id' => $withdrawId,
                'account_id' => $accountId,
                'amount' => $amount,
            ]);
        });

        // Envia email APENAS se foi efetivado
        $fresh = Db::table('account_withdraw')->where('id', $withdrawId)->first();
        if ($fresh && (int) $fresh->done === 1 && (int) $fresh->error === 0 && $pixEmail) {
            $this->emailNotifier->sendWithdrawDoneEmail(
                requestId: $requestId,
                withdrawId: $withdrawId,
                toEmail: $pixEmail,
                amount: $amount,
                processedAt: $now
            );
        }
    }
}
