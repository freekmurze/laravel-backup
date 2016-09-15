<?php

namespace Spatie\Backup\Test\Integration\Notifications;

use Exception;
use Notification;
use Spatie\Backup\BackupDestination\BackupDestinationFactory;
use Spatie\Backup\Events\BackupHasFailed;
use Spatie\Backup\Notifications\Notifiable;
use Spatie\Backup\Notifications\Notifications\BackupHasFailed as BackupHasFailedNotification;
use Spatie\Backup\Test\Integration\TestCase;

class EventHandlerTest extends TestCase
{
    public function setUp()
    {
        parent::setUp();

        Notification::fake();
    }

    /** @test */
    public function it_will_send_a_notification_by_default_when_a_backup_has_failed()
    {
        $this->fireBackupHasFailedEvent();

        Notification::assertSentTo(new Notifiable(), BackupHasFailedNotification::class);
    }

    /**
     * @test
     *
     * @dataProvider channelProvider
     *
     * @param array $expectedChannels
     */
    public function it_will_not_send_a_notification_when_the_channels_for_that_event_are_empty(array $expectedChannels)
    {
        $this->app['config']->set('laravel-backup.notifications.notifications.'.BackupHasFailedNotification::class, $expectedChannels);

        $this->fireBackupHasFailedEvent();

        Notification::assertNotSentTo(new Notifiable(), BackupHasFailedNotification::class, function ($notification, $usedChannels) use ($expectedChannels) {
            $this->assertSame($expectedChannels, $usedChannels);
        });
    }

    public function channelProvider()
    {
        return [
            [[]],
            [['mail']],
            [['mail', 'slack']],
        ];
    }

    protected function fireBackupHasFailedEvent()
    {
        $exception = new Exception('Dummy exception');

        $backupDestination = BackupDestinationFactory::createFromArray(config('laravel-backup.backup'))->first();

        event(new BackupHasFailed($exception, $backupDestination));
    }
}
