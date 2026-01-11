<?php

namespace App\Service;

use Hyperf\Stringable\Str;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;
use function Hyperf\Support\env;

class WithdrawEmailNotifier
{
    private Mailer $mailer;

    public function __construct(
        private LoggerInterface $logger,
    ) {
        $dsn = env('MAILER_DSN', 'smtp://mailhog:1025');
        $transport = Transport::fromDsn($dsn);
        $this->mailer = new Mailer($transport);
    }

    public function sendWithdrawDoneEmail(
        string $requestId,
        string $withdrawId,
        string $toEmail,
        string $amount,
        string $processedAt
    ): void {
        $fromAddress = (string) env('MAIL_FROM_ADDRESS', 'no-reply@contadigital.local');
        $fromName = (string) env('MAIL_FROM_NAME', 'Conta Digital');

        $subject = 'Saque efetuado';
        $html = $this->renderHtml($amount, $processedAt, $toEmail);

        try {
            $email = (new Email())
                ->from(sprintf('%s <%s>', $fromName, $fromAddress))
                ->to($toEmail)
                ->subject($subject)
                ->html($html);

            $this->mailer->send($email);

            $this->logger->info('withdraw.email.sent', [
                'request_id' => $requestId,
                'withdraw_id' => $withdrawId,
                'to' => $toEmail,
            ]);
        } catch (\Throwable $e) {
            // Não “falhar” o saque por erro de email
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
