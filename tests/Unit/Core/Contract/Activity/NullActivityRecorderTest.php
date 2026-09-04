<?php

declare(strict_types=1);

namespace Trilobit\Tests\Unit\Core\Contract\Activity;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Contract\Activity\ActivityRecord;
use Trilobit\Core\Contract\Activity\NullActivityRecorder;
use Trilobit\Core\Contract\Party\PartyRef;

#[CoversClass(NullActivityRecorder::class)]
final class NullActivityRecorderTest extends TestCase
{
    public function testRecordingAnActivityDoesNothingAndDoesNotFail(): void
    {
        $recorder = new NullActivityRecorder();

        $recorder->record(new ActivityRecord(
            subject: new PartyRef('crm.contact', '1'),
            type: 'shop.order.placed',
            title: 'Order placed',
            occurredAt: new DateTimeImmutable(),
        ));

        $this->expectNotToPerformAssertions();
    }
}
