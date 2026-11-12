<?php

use App\Models\App;
use App\Services\Push\Contracts\MessagingFactory;
use App\Services\Push\FcmPushSender;
use App\Services\Push\PushMessage;
use App\Services\Push\PushResult;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\Messaging\InvalidMessage;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Exception\Messaging\ServerUnavailable;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\MessageTarget;
use Kreait\Firebase\Messaging\MulticastSendReport;
use Kreait\Firebase\Messaging\SendReport;

it('maps FCM results to delivery results without real network access', function () {
    $messaging = Mockery::mock(Messaging::class);
    $messaging->shouldReceive('sendMulticast')->once()->withArgs(function ($message, $tokens) {
        $payload = $message->jsonSerialize();

        return $message instanceof CloudMessage
            && $tokens === ['t-ok', 't-unregistered', 't-invalid', 't-unavailable']
            && $payload['notification']['title'] === 'T'
            && $payload['data']['eevee_campaign_id'] === '9'
            && $payload['apns']['payload']['aps']['mutable-content'] === 1;
    })->andReturn(MulticastSendReport::withItems([
        SendReport::success(MessageTarget::with(MessageTarget::TOKEN, 't-ok'), ['name' => 'projects/p/messages/1']),
        SendReport::failure(MessageTarget::with(MessageTarget::TOKEN, 't-unregistered'), NotFound::becauseTokenNotFound('t-unregistered')),
        SendReport::failure(MessageTarget::with(MessageTarget::TOKEN, 't-invalid'), new InvalidMessage('The registration token is not a valid FCM registration token')),
        SendReport::failure(MessageTarget::with(MessageTarget::TOKEN, 't-unavailable'), new ServerUnavailable('unavailable')),
    ]));

    $factory = Mockery::mock(MessagingFactory::class);
    $factory->shouldReceive('for')->andReturn($messaging);

    $results = (new FcmPushSender($factory))->send(new App, new PushMessage('T', 'B', 'https://example.com/a.png', ['eevee_campaign_id' => '9'], [
        11 => 't-ok', 12 => 't-unregistered', 13 => 't-invalid', 14 => 't-unavailable',
    ]));

    $byId = collect($results)->keyBy('deliveryId');
    expect($byId[11]->status)->toBe(PushResult::SENT)
        ->and($byId[11]->messageId)->toBe('projects/p/messages/1')
        ->and($byId[12]->status)->toBe(PushResult::INVALID_TOKEN)
        ->and($byId[12]->errorCode)->toBe('UNREGISTERED')
        ->and($byId[13]->status)->toBe(PushResult::INVALID_TOKEN)
        ->and($byId[14]->status)->toBe(PushResult::RETRYABLE);
});

it('retries the whole chunk when FCM cannot be reached', function () {
    $factory = Mockery::mock(MessagingFactory::class);
    $factory->shouldReceive('for')->andThrow(new RuntimeException('Firebase credentials are not configured'));
    $results = (new FcmPushSender($factory))->send(new App, new PushMessage('T', 'B', null, [], [1 => 'a', 2 => 'b']));
    expect(collect($results)->pluck('status')->unique()->all())->toBe([PushResult::RETRYABLE]);
});
