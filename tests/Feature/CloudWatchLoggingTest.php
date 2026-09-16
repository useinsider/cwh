<?php

namespace Insider\Cwh\Tests\Feature;

use Aws\CloudWatchLogs\CloudWatchLogsClient;
use Insider\Cwh\Handler\CloudWatch;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end feature coverage: records logged through a real Monolog Logger
 * must reach the CloudWatch client as a single PutLogEvents batch when the
 * handler is closed. Group/stream provisioning is disabled so the test
 * exercises the buffering + flush pipeline without any AWS round-trips.
 */
class CloudWatchLoggingTest extends TestCase
{
    private string $groupName = 'group';
    private string $streamName = 'stream';

    public function testLoggerFlushesBufferedRecordsToCloudWatchOnClose(): void
    {
        /** @var MockObject|CloudWatchLogsClient $clientMock */
        $clientMock = $this
            ->getMockBuilder(CloudWatchLogsClient::class)
            ->addMethods(['PutLogEvents'])
            ->disableOriginalConstructor()
            ->getMock();

        $clientMock
            ->expects($this->once())
            ->method('PutLogEvents')
            ->with($this->callback(function (array $data): bool {
                $this->assertSame($this->groupName, $data['logGroupName']);
                $this->assertSame($this->streamName, $data['logStreamName']);
                $this->assertCount(2, $data['logEvents']);

                return true;
            }));

        $handler = new CloudWatch(
            $clientMock,
            $this->groupName,
            $this->streamName,
            14,
            10000,
            [],
            Level::Debug,
            true,
            false,
            false
        );

        $logger = new Logger('feature', [$handler]);
        $logger->info('first message');
        $logger->warning('second message');

        $handler->close();
    }
}
