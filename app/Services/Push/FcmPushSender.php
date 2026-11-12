<?php

namespace App\Services\Push;

use App\Models\App;
use App\Services\Push\Contracts\MessagingFactory;
use App\Services\Push\Contracts\PushSender;
use Kreait\Firebase\Exception\Messaging\ApiConnectionFailed;
use Kreait\Firebase\Exception\Messaging\InvalidArgument;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Exception\Messaging\QuotaExceeded;
use Kreait\Firebase\Exception\Messaging\ServerError;
use Kreait\Firebase\Exception\Messaging\ServerUnavailable;
use Kreait\Firebase\Exception\MessagingException;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use Kreait\Firebase\Messaging\SendReport;
use Throwable;

/**
 * FCM HTTP v1 で送信する。
 */
class FcmPushSender implements PushSender
{
    public function __construct(private readonly MessagingFactory $factory) {}

    public function send(App $app, PushMessage $message): array
    {
        if ($message->tokens === []) {
            return [];
        }

        $cloudMessage = CloudMessage::new()
            ->withNotification(Notification::create($message->title, $message->body, $message->imageUrl))
            ->withData($message->data)
            ->withAndroidConfig(['priority' => 'high', 'notification' => ['sound' => 'default']])
            ->withApnsConfig(['payload' => ['aps' => array_filter([
                'sound' => 'default',
                // 画像付き通知は Notification Service Extension で表示するため mutable-content を立てる
                'mutable-content' => $message->imageUrl !== null ? 1 : null,
            ])]]);

        $byToken = [];
        foreach ($message->tokens as $deliveryId => $token) {
            $byToken[$token][] = (int) $deliveryId;
        }

        try {
            $report = $this->factory->for($app)->sendMulticast($cloudMessage, array_keys($byToken));
        } catch (Throwable $e) {
            // 認証エラーやネットワーク障害: チャンク全体を再試行
            return array_map(
                fn (int $id) => new PushResult($id, PushResult::RETRYABLE, errorCode: class_basename($e), errorMessage: self::safeMessage($e)),
                array_map('intval', array_keys($message->tokens)),
            );
        }

        $results = [];
        foreach ($report->getItems() as $item) {
            $token = $item->target()->value();
            foreach ($byToken[$token] ?? [] as $deliveryId) {
                $results[] = $this->toResult($deliveryId, $item);
            }
        }

        return $results;
    }

    private function toResult(int $deliveryId, SendReport $item): PushResult
    {
        if ($item->isSuccess()) {
            $name = $item->result()['name'] ?? null;

            return new PushResult($deliveryId, PushResult::SENT, messageId: is_string($name) ? $name : null);
        }

        $error = $item->error();
        if ($item->messageWasSentToUnknownToken() || $item->messageTargetWasInvalid() || $error instanceof NotFound) {
            return new PushResult($deliveryId, PushResult::INVALID_TOKEN, errorCode: $error instanceof NotFound ? 'UNREGISTERED' : 'INVALID_ARGUMENT', errorMessage: self::safeMessage($error));
        }
        if ($error instanceof ServerUnavailable || $error instanceof ServerError || $error instanceof QuotaExceeded || $error instanceof ApiConnectionFailed) {
            return new PushResult($deliveryId, PushResult::RETRYABLE, errorCode: class_basename($error), errorMessage: self::safeMessage($error));
        }
        if ($error instanceof InvalidArgument) {
            // トークン以外の不正（ペイロードなど）。再試行しても成功しない
            return new PushResult($deliveryId, PushResult::FAILED, errorCode: 'INVALID_ARGUMENT', errorMessage: self::safeMessage($error));
        }

        return new PushResult($deliveryId, PushResult::FAILED, errorCode: $error instanceof MessagingException ? class_basename($error) : 'UNKNOWN', errorMessage: self::safeMessage($error));
    }

    private static function safeMessage(?Throwable $e): ?string
    {
        // トークンや認証情報をログ・DBに残さないよう、メッセージだけを短く保存する
        return $e === null ? null : mb_substr(preg_replace('/[A-Za-z0-9_\-:]{100,}/', '[redacted]', $e->getMessage()) ?? '', 0, 500);
    }
}
