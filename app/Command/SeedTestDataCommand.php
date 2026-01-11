<?php

namespace App\Command;

use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\DbConnection\Db;
use Hyperf\Stringable\Str;

#[Command(name: 'seed:test-data', description: 'Insere dados de teste (accounts e withdrawals) no banco.')]
class SeedTestDataCommand extends HyperfCommand
{
    public function handle(): int
    {
        // Ajuste esses valores se quiser
        $accounts = [
            ['name' => 'Conta Principal', 'balance' => 1000.00],
            ['name' => 'Conta Secundária', 'balance' => 50.00],
        ];

        $now = new \DateTimeImmutable('now');

        // Cria contas
        $createdAccounts = [];
        foreach ($accounts as $acc) {
            $id = (string) Str::uuid();

            Db::table('account')->insert([
                'id' => $id,
                'name' => $acc['name'],
                'balance' => $acc['balance'],
                'created_at' => $now->format('Y-m-d H:i:s'),
                'updated_at' => $now->format('Y-m-d H:i:s'),
            ]);

            $createdAccounts[] = ['id' => $id, 'name' => $acc['name'], 'balance' => $acc['balance']];
        }

        // Cria 2 saques agendados para testar o cron depois:
        // 1) Um com saldo suficiente (deve processar com sucesso)
        // 2) Um com saldo insuficiente (deve marcar error_reason=insufficient_funds)
        // OBS: scheduled_for <= now para o cron pegar "na hora" quando você rodar o job.
        $accOk = $createdAccounts[0]['id'];   // Conta Principal (1000)
        $accLow = $createdAccounts[1]['id'];  // Conta Secundária (50)

        $this->createScheduledPixWithdraw(
            withdrawId: (string) Str::uuid(),
            accountId: $accOk,
            amount: 200.00,
            scheduledFor: $now->modify('-2 minutes'),
            pixType: 'email',
            pixKey: 'cliente.ok@exemplo.com',
            now: $now
        );

        $this->createScheduledPixWithdraw(
            withdrawId: (string) Str::uuid(),
            accountId: $accLow,
            amount: 200.00, // maior que o saldo (50)
            scheduledFor: $now->modify('-2 minutes'),
            pixType: 'email',
            pixKey: 'cliente.sem.saldo@exemplo.com',
            now: $now
        );

        $this->info('Seed concluído.');
        $this->line('Contas criadas:');
        foreach ($createdAccounts as $acc) {
            $this->line("- {$acc['name']} | id={$acc['id']} | balance={$acc['balance']}");
        }

        $this->line('');
        $this->line('Foram criados 2 saques agendados (scheduled_for no passado) para testar o cron:');
        $this->line('- 1 deve processar com sucesso');
        $this->line('- 1 deve falhar por saldo insuficiente (insufficient_funds)');

        return 0;
    }

    private function createScheduledPixWithdraw(
        string $withdrawId,
        string $accountId,
        float $amount,
        \DateTimeImmutable $scheduledFor,
        string $pixType,
        string $pixKey,
        \DateTimeImmutable $now
    ): void {
        Db::table('account_withdraw')->insert([
            'id' => $withdrawId,
            'account_id' => $accountId,
            'method' => 'PIX',
            'amount' => $amount,
            'scheduled' => 1,
            'scheduled_for' => $scheduledFor->format('Y-m-d H:i:s'),
            'done' => 0,
            'error' => 0,
            'error_reason' => null,
            'processing' => 0,
            'processing_started_at' => null,
            'processed_at' => null,

            'created_at' => $now->format('Y-m-d H:i:s'),
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ]);

        Db::table('account_withdraw_pix')->insert([
            'account_withdraw_id' => $withdrawId,
            'type' => $pixType,
            'key' => $pixKey,
            'created_at' => $now->format('Y-m-d H:i:s'),
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ]);
    }
}
