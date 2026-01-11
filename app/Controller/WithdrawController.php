<?php

namespace App\Controller;

use App\Request\WithdrawRequest;
use App\Service\WithdrawService;
use Hyperf\HttpServer\Contract\ResponseInterface as HttpResponse;
use Hyperf\Stringable\Str;
use Psr\Log\LoggerInterface;

class WithdrawController
{
    public function __construct(
        private WithdrawService $service,
        private HttpResponse $response,
        private LoggerInterface $logger,
    ) {}

    public function withdraw(string $accountId, WithdrawRequest $request)
    {
        $requestId = (string) Str::uuid();

        try {
            $payload = $request->validated();
            $result = $this->service->createWithdraw($requestId, $accountId, $payload);

            // Status codes:
            // - scheduled -> 202
            // - done -> 200
            // - failed(insufficient) -> 409
            $status = match ($result['status']) {
                'scheduled' => 202,
                'failed' => 409,
                default => 200,
            };

            return $this->response->json([
                'success' => $result['status'] !== 'failed',
                'request_id' => $requestId,
                'data' => $result,
            ])->withStatus($status);
        } catch (\InvalidArgumentException $e) {
            // erro “do usuário” (ex.: schedule passado)
            return $this->response->json([
                'success' => false,
                'request_id' => $requestId,
                'error' => [
                    'code' => 'invalid_request',
                    'message' => $e->getMessage(),
                ],
            ])->withStatus(422);
        } catch (\Throwable $e) {
            // erro interno: loga detalhe, responde seguro
            $this->logger->error('withdraw.create.unhandled_exception', [
                'request_id' => $requestId,
                'account_id' => $accountId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return $this->response->json([
                'success' => false,
                'request_id' => $requestId,
                'error' => [
                    'code' => 'internal_error',
                    'message' => 'Unexpected error.',
                ],
            ])->withStatus(500);
        }
    }
}
