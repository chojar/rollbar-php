<?php

namespace Rollbar\Telemetry;

use Closure;
use Exception;
use Rollbar\BaseRollbarTest;
use Rollbar\Payload\Level;
use Rollbar\Payload\TelemetryBody;
use Rollbar\Payload\TelemetryEvent;
use Rollbar\TestHelpers\TestTelemetryFilter;

class TelemeterTest extends BaseRollbarTest
{
    public function testMaxEventConstrains(): void
    {
        $telemeter = new Telemeter(-5);
        self::assertSame(0, $telemeter->getMaxQueueSize());

        $telemeter = new Telemeter(42);
        self::assertSame(42, $telemeter->getMaxQueueSize());

        $telemeter = new Telemeter(105);
        self::assertSame(100, $telemeter->getMaxQueueSize());
    }

    public function testGetLevelFromPsrLevel(): void
    {
        self::assertSame(EventLevel::Critical, Telemeter::getLevelFromPsrLevel(Level::EMERGENCY));
        self::assertSame(EventLevel::Critical, Telemeter::getLevelFromPsrLevel(Level::ALERT));
        self::assertSame(EventLevel::Critical, Telemeter::getLevelFromPsrLevel(Level::CRITICAL));
        self::assertSame(EventLevel::Error, Telemeter::getLevelFromPsrLevel(Level::ERROR));
        self::assertSame(EventLevel::Warning, Telemeter::getLevelFromPsrLevel(Level::WARNING));
        self::assertSame(EventLevel::Info, Telemeter::getLevelFromPsrLevel(Level::NOTICE));
        self::assertSame(EventLevel::Info, Telemeter::getLevelFromPsrLevel(Level::INFO));
        self::assertSame(EventLevel::Debug, Telemeter::getLevelFromPsrLevel(Level::DEBUG));
    }

    public function testScope(): void
    {
        $telemeter = new Telemeter();
        self::assertSame(100, $telemeter->getMaxQueueSize());
        self::assertNull($telemeter->getFilter());
        self::assertTrue($telemeter->shouldIncludeItemsInTelemetry());
        self::assertFalse($telemeter->shouldIncludeIgnoredItemsInTelemetry());

        $telemeter->scope(42, new TestTelemetryFilter(), false, true);
        self::assertSame(42, $telemeter->getMaxQueueSize());
        self::assertInstanceOf(TestTelemetryFilter::class, $telemeter->getFilter());
        self::assertFalse($telemeter->shouldIncludeItemsInTelemetry());
        self::assertTrue($telemeter->shouldIncludeIgnoredItemsInTelemetry());
    }

    public function testPush(): void
    {
        $telemeter = new Telemeter();
        self::assertSame(100, $telemeter->getMaxQueueSize());
        self::assertSame(0, $telemeter->getQueueSize());

        $telemeter->push(new TelemetryEvent(EventType::Log, EventLevel::Info, ['message' => 'foo']));
        self::assertSame(1, $telemeter->getQueueSize());

        $telemeter->push(new TelemetryEvent(EventType::Log, EventLevel::Info, new TelemetryBody('bar')));
        self::assertSame(2, $telemeter->getQueueSize());
    }

    public function testCopyEvents(): void
    {
        $telemeter = new Telemeter();

        $event1 = new TelemetryEvent(EventType::Log, EventLevel::Info, ['message' => 'foo']);
        $event2 = new TelemetryEvent(EventType::Log, EventLevel::Info, new TelemetryBody('bar'));

        $telemeter->push($event1);
        $telemeter->push($event2);

        $events = $telemeter->copyEvents();
        self::assertSame(2, count($events));
        self::assertSame($event1, $events[0]);
        self::assertSame($event2, $events[1]);
    }

    public function testCopyEventsFilter(): void
    {
        $filter = new TestTelemetryFilter();
        $filter->includeFunction = Closure::fromCallable(function (TelemetryEvent $event, int $queueSize): bool {
            return $event->body->message !== 'foo';
        });

        $telemeter = new Telemeter(filter: $filter);

        $event1 = new TelemetryEvent(EventType::Log, EventLevel::Info, ['message' => 'foo']);
        $event2 = new TelemetryEvent(EventType::Log, EventLevel::Info, new TelemetryBody('bar'));

        $telemeter->push($event1);
        $telemeter->push($event2);

        $events = $telemeter->copyEvents();
        self::assertSame(1, count($events));
        self::assertSame($event2, $events[0]);
    }

    public function testCapture(): void
    {
        $telemeter = new Telemeter();
        $telemeter->capture(EventType::Log, EventLevel::Info, ['message' => 'foo']);

        $events = $telemeter->copyEvents();
        self::assertSame(1, count($events));
        self::assertSame('foo', $events[0]->body->message);
        self::assertSame(EventLevel::Info, $events[0]->level);
        self::assertSame(EventType::Log, $events[0]->type);
        self::assertNotNull($events[0]->timestamp);
    }

    public function testCaptureFilter(): void
    {
        $filter = new TestTelemetryFilter();
        $filter->includeFunction = Closure::fromCallable(function (TelemetryEvent $event, int $queueSize): bool {
            return $event->body->message !== 'foo';
        });

        $telemeter = new Telemeter(filter: $filter);
        $telemeter->capture(EventType::Log, EventLevel::Info, ['message' => 'foo']);
        $telemeter->capture(EventType::Log, EventLevel::Info, ['message' => 'bar']);

        // Because the filter is also applied on the copyEvents() call, we want to make sure that the 'foo' event is
        // filtered out, but the 'bar' event is not.
        self::assertSame(1, $telemeter->getQueueSize());

        $events = $telemeter->copyEvents();
        self::assertSame(1, count($events));
        self::assertSame('bar', $events[0]->body->message);
    }

    public function testCaptureError(): void
    {
        $telemeter = new Telemeter();
        $telemeter->captureError('foo');
        $telemeter->captureError(['message' => 'bar'], EventLevel::Warning);
        $telemeter->captureError(['message' => 'baz'], EventLevel::Critical);

        $events = $telemeter->copyEvents();
        self::assertSame(3, count($events));
        self::assertSame('foo', $events[0]->body->message);
        self::assertSame(EventType::Error, $events[0]->type);
        self::assertSame(EventLevel::Error, $events[0]->level);

        self::assertSame('bar', $events[1]->body->message);
        self::assertSame(EventType::Error, $events[1]->type);
        self::assertSame(EventLevel::Warning, $events[1]->level);

        self::assertSame('baz', $events[2]->body->message);
        self::assertSame(EventType::Error, $events[2]->type);
        self::assertSame(EventLevel::Critical, $events[2]->level);
    }

    public function testCaptureLog(): void
    {
        $telemeter = new Telemeter();
        $telemeter->captureLog('foo');
        $telemeter->captureLog('bar', EventLevel::Debug);

        $events = $telemeter->copyEvents();
        self::assertSame(2, count($events));
        self::assertSame('foo', $events[0]->body->message);
        self::assertSame(EventType::Log, $events[0]->type);
        self::assertSame(EventLevel::Info, $events[0]->level);

        self::assertSame('bar', $events[1]->body->message);
        self::assertSame(EventType::Log, $events[1]->type);
        self::assertSame(EventLevel::Debug, $events[1]->level);
    }

    public function testCaptureNetwork(): void
    {
        $telemeter = new Telemeter();
        $telemeter->captureNetwork('POST', 'https://example.com', '200');

        $events = $telemeter->copyEvents();
        self::assertSame(1, count($events));
        self::assertSame('POST', $events[0]->body->method);
        self::assertSame('https://example.com', $events[0]->body->url);
        self::assertSame('200', $events[0]->body->status_code);
    }

    public function testCaptureNavigation(): void
    {
        $telemeter = new Telemeter();
        $telemeter->captureNavigation('https://example.com/foo', 'https://example.com/bar');

        $events = $telemeter->copyEvents();
        self::assertSame(1, count($events));
        self::assertSame('https://example.com/foo', $events[0]->body->from);
        self::assertSame('https://example.com/bar', $events[0]->body->to);
    }

    public function testCaptureRollbarItem(): void
    {
        // Test exclude Rollbar captured item.
        $telemeter = new Telemeter(includeItemsInTelemetry: false);
        self::assertNull($telemeter->captureRollbarItem(Level::INFO, 'foo'));

        // Test exclude ignored Rollbar item.
        $telemeter = new Telemeter(includeIgnoredItemsInTelemetry: false);
        self::assertNull($telemeter->captureRollbarItem(Level::INFO, 'foo', ignored: true));

        // Test non-ignored Rollbar item not excluded.
        $event = $telemeter->captureRollbarItem(Level::INFO, 'bar');
        self::assertSame($event->body->message, 'bar');

        // Test a Throwable in the $context['exception'] is treated as an error.
        $telemeter = new Telemeter();
        $error = new Exception('oops');
        $event = $telemeter->captureRollbarItem(Level::DEBUG, 'baz', context: ['exception' => $error]);
        self::assertSame(EventType::Error, $event->type);
        self::assertSame('oops', $event->body->extra['error_message']);
        self::assertSame('baz', $event->body->message);

        // Test a Throwable $message is treated as an error
        $event = $telemeter->captureRollbarItem(Level::DEBUG, $error);
        self::assertSame(EventType::Error, $event->type);
        self::assertSame('oops', $event->body->message);

        // Test telemetry type dynamically determined from the Rollbar level.
        self::assertSame(EventType::Error, $telemeter->captureRollbarItem(Level::EMERGENCY, 'foo')->type);
        self::assertSame(EventType::Error, $telemeter->captureRollbarItem(Level::ALERT, 'foo')->type);
        self::assertSame(EventType::Error, $telemeter->captureRollbarItem(Level::CRITICAL, 'foo')->type);
        self::assertSame(EventType::Error, $telemeter->captureRollbarItem(Level::ERROR, 'foo')->type);
        self::assertSame(EventType::Error, $telemeter->captureRollbarItem(Level::WARNING, 'foo')->type);
        self::assertSame(EventType::Log, $telemeter->captureRollbarItem(Level::NOTICE, 'foo')->type);
        self::assertSame(EventType::Log, $telemeter->captureRollbarItem(Level::INFO, 'foo')->type);
        self::assertSame(EventType::Manual, $telemeter->captureRollbarItem(Level::DEBUG, 'foo')->type);
        self::assertSame(EventType::Manual, $telemeter->captureRollbarItem('bar', 'foo')->type);

        // Test telemetry level dynamically determined from the Rollbar level.
        self::assertSame(EventLevel::Critical, $telemeter->captureRollbarItem(Level::EMERGENCY, 'foo')->level);
        self::assertSame(EventLevel::Critical, $telemeter->captureRollbarItem(Level::ALERT, 'foo')->level);
        self::assertSame(EventLevel::Critical, $telemeter->captureRollbarItem(Level::CRITICAL, 'foo')->level);
        self::assertSame(EventLevel::Error, $telemeter->captureRollbarItem(Level::ERROR, 'foo')->level);
        self::assertSame(EventLevel::Warning, $telemeter->captureRollbarItem(Level::WARNING, 'foo')->level);
        self::assertSame(EventLevel::Info, $telemeter->captureRollbarItem(Level::NOTICE, 'foo')->level);
        self::assertSame(EventLevel::Info, $telemeter->captureRollbarItem(Level::INFO, 'foo')->level);
        self::assertSame(EventLevel::Debug, $telemeter->captureRollbarItem(Level::DEBUG, 'foo')->level);
        self::assertSame(EventLevel::Info, $telemeter->captureRollbarItem('bar', 'foo')->level);
    }

    public function testGetMaxQueueSize(): void
    {
        $telemeter = new Telemeter(42);
        self::assertSame(42, $telemeter->getMaxQueueSize());
    }

    public function testSetMaxQueueSize(): void
    {
        $telemeter = new Telemeter();
        self::assertSame(100, $telemeter->getMaxQueueSize());

        $telemeter->setMaxQueueSize(10);
        self::assertSame(10, $telemeter->getMaxQueueSize());

        foreach (range(1, 10) as $i) {
            $telemeter->captureLog('foo' . $i);
        }

        self::assertSame(10, $telemeter->getQueueSize());

        $telemeter->setMaxQueueSize(5);
        self::assertSame(5, $telemeter->getMaxQueueSize());
        self::assertSame(5, $telemeter->getQueueSize());
    }

    public function testGetQueueSize(): void
    {
        $telemeter = new Telemeter();
        self::assertSame(0, $telemeter->getQueueSize());

        $telemeter->captureLog('foo');
        self::assertSame(1, $telemeter->getQueueSize());

        $telemeter->captureLog('bar');
        self::assertSame(2, $telemeter->getQueueSize());
    }

    public function testClearQueue(): void
    {
        $telemeter = new Telemeter();
        $telemeter->captureLog('foo');
        $telemeter->captureLog('bar');

        self::assertSame(2, $telemeter->getQueueSize());
        $telemeter->clearQueue();
        self::assertSame(0, $telemeter->getQueueSize());
    }

    public function testShouldIncludeItemsInTelemetry(): void
    {
        $telemeter = new Telemeter();
        self::assertTrue($telemeter->shouldIncludeItemsInTelemetry());

        $telemeter = new Telemeter(includeItemsInTelemetry: false);
        self::assertFalse($telemeter->shouldIncludeItemsInTelemetry());
    }

    public function testSetIncludeItemsInTelemetry(): void
    {
        $telemeter = new Telemeter();
        self::assertTrue($telemeter->shouldIncludeItemsInTelemetry());

        $telemeter->setIncludeItemsInTelemetry(false);
        self::assertFalse($telemeter->shouldIncludeItemsInTelemetry());

        $telemeter->setIncludeItemsInTelemetry(true);
        self::assertTrue($telemeter->shouldIncludeItemsInTelemetry());
    }

    public function testGetFilter(): void
    {
        $filter = new TestTelemetryFilter();
        $filter->includeFunction = Closure::fromCallable(function (TelemetryEvent $event, int $queueSize): bool {
            return $event->body->message !== 'foo';
        });
        $telemeter = new Telemeter(filter: $filter);
        self::assertSame($filter, $telemeter->getFilter());
    }

    public function testSetFilter(): void
    {
        $filter = new TestTelemetryFilter();
        $filter->includeFunction = Closure::fromCallable(function (TelemetryEvent $event, int $queueSize): bool {
            return $event->body->message !== 'foo';
        });
        $telemeter = new Telemeter();
        self::assertNull($telemeter->getFilter());

        $telemeter->setFilter($filter);
        self::assertSame($filter, $telemeter->getFilter());
    }

    public function testShouldIncludeIgnoredItemsInTelemetry(): void
    {
        $telemeter = new Telemeter();
        self::assertFalse($telemeter->shouldIncludeIgnoredItemsInTelemetry());

        $telemeter = new Telemeter(includeIgnoredItemsInTelemetry: false);
        self::assertFalse($telemeter->shouldIncludeIgnoredItemsInTelemetry());

        $telemeter = new Telemeter(includeIgnoredItemsInTelemetry: true);
        self::assertTrue($telemeter->shouldIncludeIgnoredItemsInTelemetry());
    }

    public function testSetIncludeIgnoredItemsInTelemetry(): void
    {
        $telemeter = new Telemeter();
        self::assertFalse($telemeter->shouldIncludeIgnoredItemsInTelemetry());

        $telemeter->setIncludeIgnoredItemsInTelemetry(true);
        self::assertTrue($telemeter->shouldIncludeIgnoredItemsInTelemetry());
    }

    public function testSetFilterWithFilterableEvents(): void
    {
        $filter = new TestTelemetryFilter();
        $filter->includeFunction = Closure::fromCallable(function (TelemetryEvent $event, int $queueSize): bool {
            return $event->body->message !== 'foo';
        });
        $telemeter = new Telemeter();

        $event1 = new TelemetryEvent(EventType::Log, EventLevel::Info, ['message' => 'foo']);
        $event2 = new TelemetryEvent(EventType::Log, EventLevel::Info, new TelemetryBody('bar'));

        $telemeter->push($event1);
        $telemeter->push($event2);

        $telemeter->setFilter($filter);

        $events = $telemeter->copyEvents();
        self::assertSame(1, count($events));
        self::assertSame($event2, $events[0]);
    }
}
