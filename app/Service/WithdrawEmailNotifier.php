<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;
use function Hyperf\Support\env;

class WithdrawEmailNotifier
{
    public function __construct(
        private LoggerInterface $logger,
    ) {}

    public function sendWithdrawDoneEmail(
        string $requestId,
        string $withdrawId,
        string $toEmail,
        string $amount,
        string $processedAt
    ): void {
        $dsn = (string) env('MAILER_DSN', 'smtp://mailhog:1025');
        $fromAddress = (string) env('MAIL_FROM_ADDRESS', 'no-reply@contadigital.local');
        $fromName = (string) env('MAIL_FROM_NAME', 'Conta Digital');

        $subject = 'Saque efetuado';
        $html = $this->renderHtml($amount, $processedAt, $toEmail);

        $email = (new Email())
            ->from(sprintf('%s <%s>', $fromName, $fromAddress))
            ->to($toEmail)
            ->subject($subject)
            ->html($html);

        $send = function () use ($dsn, $email): void {
            $transport = Transport::fromDsn($dsn);
            $mailer = new Mailer($transport);
            $mailer->send($email);
        };

        try {
            if (\Hyperf\Coroutine\Coroutine::inCoroutine()) {
                $send();
            } else {
                \Swoole\Coroutine\run(function () use ($send) {
                    $send();
                });
            }

            $this->logger->info('withdraw.email.sent', [
                'request_id' => $requestId,
                'withdraw_id' => $withdrawId,
                'to' => $toEmail,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('withdraw.email.failed', [
                'request_id' => $requestId,
                'withdraw_id' => $withdrawId,
                'to' => $toEmail,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function renderHtml(string $amount, string $processedAt, string $toEmail): string
    {
        $amount = htmlspecialchars($amount, ENT_QUOTES, 'UTF-8');
        $processedAt = htmlspecialchars($processedAt, ENT_QUOTES, 'UTF-8');
        $toEmail = htmlspecialchars($toEmail, ENT_QUOTES, 'UTF-8');

        return <<<HTML
        <h3>Saque efetuado</h3>
        <p><strong>Data/Hora:</strong> {$processedAt}</p>
        <p><strong>Valor:</strong> R$ {$amount}</p>
        <p><strong>PIX:</strong> email — {$toEmail}</p>
        HTML;
    }
}
