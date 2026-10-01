<?php

declare(strict_types=1);

namespace EidCloud\AgentInspector\Tests;

require_once __DIR__ . '/../src/autoload.php';

use EidCloud\AgentInspector\Inspector;
use EidCloud\AgentInspector\Packets\Frame;
use EidCloud\AgentInspector\Packets\FrameType;
use EidCloud\AgentInspector\Packets\Session;
use EidCloud\AgentInspector\Filter\TraceFilter;
use EidCloud\AgentInspector\Replay\SessionReplayer;
use EidCloud\AgentInspector\Formatter\ConsoleFormatter;
use AssertionError;

class InspectorTest
{
    private Inspector $inspector;

    public function __construct()
    {
        $this->inspector = new Inspector(useColor: false);
    }

    public function testFrameCreationAndAttributes(): void
    {
        $frame = new Frame(
            frameId: 1,
            type: FrameType::TOOL_CALL,
            timestamp: '2026-10-01T12:00:00Z',
            latencyMs: 150.5,
            inputTokens: 100,
            outputTokens: 25,
            toolName: 'filesystem',
            payload: ['action' => 'read', 'path' => '/tmp/test.txt'],
            metadata: ['model' => 'gpt-4o']
        );

        $this->assertEquals(1, $frame->frameId);
        $this->assertEquals(FrameType::TOOL_CALL, $frame->type);
        $this->assertEquals('2026-10-01T12:00:00Z', $frame->timestamp);
        $this->assertEquals(150.5, $frame->latencyMs);
        $this->assertEquals(100, $frame->inputTokens);
        $this->assertEquals(25, $frame->outputTokens);
        $this->assertEquals(125, $frame->totalTokens());
        $this->assertEquals('filesystem', $frame->toolName);
        $this->assertTrue($frame->isTool());
        $this->assertFalse($frame->isError());

        $arr = $frame->toArray();
        $this->assertEquals('FRAME_TOOL_CALL', $arr['type']);
        $this->assertEquals(125, $arr['total_tokens']);
    }

    public function testFrameFromArrayNormalization(): void
    {
        $data = [
            'type' => 'THOUGHT',
            'duration_ms' => 450.0,
            'prompt_tokens' => 200,
            'completion_tokens' => 50,
            'content' => 'Planning next tool call...',
            'model' => 'claude-3-7-sonnet'
        ];

        $frame = Frame::fromArray($data, 42);
        $this->assertEquals(42, $frame->frameId);
        $this->assertEquals(FrameType::THINK, $frame->type);
        $this->assertEquals(450.0, $frame->latencyMs);
        $this->assertEquals(200, $frame->inputTokens);
        $this->assertEquals(50, $frame->outputTokens);
        $this->assertEquals(250, $frame->totalTokens());
        $this->assertEquals('Planning next tool call...', $frame->payload);
        $this->assertEquals('claude-3-7-sonnet', $frame->metadata['model']);
    }

    public function testFrameErrorDetection(): void
    {
        $errFrame = Frame::fromArray([
            'type' => 'ERROR',
            'tool' => 'bash',
            'payload' => 'Command failed with exit code 127',
            'metadata' => ['is_error' => true]
        ]);
        $this->assertTrue($errFrame->isError());
        $this->assertEquals(FrameType::ERROR, $errFrame->type);

        $metaErrFrame = Frame::fromArray([
            'type' => 'TOOL_RESULT',
            'tool' => 'api_request',
            'payload' => '500 Internal Server Error',
            'metadata' => ['is_error' => true]
        ]);
        $this->assertTrue($metaErrFrame->isError());
    }

    public function testSessionParsingAndAggregations(): void
    {
        $samplePath = __DIR__ . '/../examples/session_sample.json';
        $session = $this->inspector->loadFromFile($samplePath);

        $this->assertEquals('session_sample_autonomous_coder_9912', $session->sessionId);
        $this->assertEquals(9, $session->count());
        $this->assertGreaterThan(0, $session->totalTokens());
        $this->assertGreaterThan(0, $session->totalLatencyMs());

        $stats = $session->getStatistics();
        $this->assertEquals(9, $stats['total_frames']);
        $this->assertEquals(1, $stats['error_count']);
        $this->assertArrayHasKey('filesystem', $stats['tools_invoked']);
        $this->assertArrayHasKey('postgres_migrator', $stats['tools_invoked']);
        $this->assertArrayHasKey('health_check', $stats['tools_invoked']);
        $this->assertEquals(2, $stats['tools_invoked']['filesystem']);
    }

    public function testJsonLinesParsing(): void
    {
        $jsonl = implode("\n", [
            json_encode(['type' => 'METADATA', 'agent' => 'jsonl-agent']),
            json_encode(['id' => 1, 'type' => 'PROMPT', 'content' => 'Hello', 'latency_ms' => 10]),
            json_encode(['id' => 2, 'type' => 'ANSWER', 'content' => 'World', 'latency_ms' => 25]),
        ]);

        $session = $this->inspector->loadFromString($jsonl, 'test_jsonl_session');
        $this->assertEquals(2, $session->count());
        $this->assertEquals('jsonl-agent', $session->metadata['agent']);
        $frames = $session->getFrames();
        $this->assertEquals(FrameType::PROMPT, $frames[0]->type);
        $this->assertEquals(FrameType::ANSWER, $frames[1]->type);
    }

    public function testBottlenecksDetection(): void
    {
        $samplePath = __DIR__ . '/../examples/session_sample.json';
        $session = $this->inspector->loadFromFile($samplePath);

        // Frame 4 (1850ms), Frame 6 (3200ms, 2400tok)
        $bottlenecks = $session->findBottlenecks(latencyThresholdMs: 1500.0, tokenThreshold: 2000);
        $this->assertGreaterThanOrEqual(2, count($bottlenecks));

        $ids = array_map(fn(Frame $f) => $f->frameId, $bottlenecks);
        $this->assertContains(4, $ids);
        $this->assertContains(6, $ids);
    }

    public function testTraceFiltering(): void
    {
        $samplePath = __DIR__ . '/../examples/session_sample.json';
        $session = $this->inspector->loadFromFile($samplePath);

        // Filter by tool
        $filterTool = new TraceFilter();
        $filtered = $filterTool->byTool('filesystem')->filterSession($session);
        $this->assertEquals(2, $filtered->count());
        foreach ($filtered->getFrames() as $f) {
            $this->assertEquals('filesystem', $f->toolName);
        }

        // Filter by errors only
        $filterErrors = (new TraceFilter())->errorsOnly(true);
        $errSession = $filterErrors->filterSession($session);
        $this->assertEquals(1, $errSession->count());
        $this->assertTrue($errSession->getFrames()[0]->isError());

        // Filter by minimum latency
        $filterLat = (new TraceFilter())->minLatency(2000.0);
        $latSession = $filterLat->filterSession($session);
        $this->assertEquals(1, $latSession->count()); // Frame 6 is 3200ms
        $this->assertEquals(6, $latSession->getFrames()[0]->frameId);

        // Filter by search query
        $filterSearch = (new TraceFilter())->search('schema');
        $searchSession = $filterSearch->filterSession($session);
        $this->assertGreaterThanOrEqual(2, $searchSession->count());
    }

    public function testSessionReplayer(): void
    {
        $samplePath = __DIR__ . '/../examples/session_sample.json';
        $session = $this->inspector->loadFromFile($samplePath);

        $replayer = new SessionReplayer($session);
        $this->assertEquals(0, $replayer->getCurrentIndex());
        $this->assertTrue($replayer->hasNext());

        $first = $replayer->next();
        $this->assertNotNull($first);
        $this->assertEquals(1, $first->frameId);
        $this->assertEquals(1, $replayer->getCurrentIndex());

        $replayedCount = 0;
        $replayer->reset();
        $replayer->replay(speedMultiplier: 0.0, onFrame: function (Frame $f, int $idx, int $total) use (&$replayedCount) {
            $replayedCount++;
        });

        $this->assertEquals($session->count(), $replayedCount);
    }

    public function testConsoleFormatter(): void
    {
        $formatter = new ConsoleFormatter(useColor: false);
        $samplePath = __DIR__ . '/../examples/session_sample.json';
        $session = $this->inspector->loadFromFile($samplePath);

        $summary = $formatter->renderSessionSummary($session);
        $this->assertStringContainsString('SESSION OVERVIEW', $summary);
        $this->assertStringContainsString('Total Frames', $summary);

        $timeline = $formatter->renderTimelineTable($session->getFrames());
        $this->assertStringContainsString('FRAME TYPE', $timeline);
        $this->assertStringContainsString('PROMPT', $timeline);
        $this->assertStringContainsString('TOOL_CALL', $timeline);

        $detail = $formatter->renderFrameDetail($session->getFrames()[0]);
        $this->assertStringContainsString('FRAME #1', $detail);
        $this->assertStringContainsString('Payload', $detail);
    }

    public function testJsonExport(): void
    {
        $samplePath = __DIR__ . '/../examples/session_sample.json';
        $session = $this->inspector->loadFromFile($samplePath);

        $json = $this->inspector->exportJson($session);
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertEquals($session->sessionId, $decoded['session_id']);
        $this->assertCount(9, $decoded['frames']);
        $this->assertArrayHasKey('statistics', $decoded);
    }

    // Helper assertions
    private function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            throw new AssertionError($message ?: "Expected " . var_export($expected, true) . ", got " . var_export($actual, true));
        }
    }

    private function assertTrue(bool $condition, string $message = 'Expected true'): void
    {
        if (!$condition) {
            throw new AssertionError($message);
        }
    }

    private function assertFalse(bool $condition, string $message = 'Expected false'): void
    {
        if ($condition) {
            throw new AssertionError($message);
        }
    }

    private function assertNotNull(mixed $val, string $message = 'Expected not null'): void
    {
        if ($val === null) {
            throw new AssertionError($message);
        }
    }

    private function assertGreaterThan(float|int $threshold, float|int $actual, string $message = ''): void
    {
        if ($actual <= $threshold) {
            throw new AssertionError($message ?: "Expected {$actual} to be greater than {$threshold}");
        }
    }

    private function assertGreaterThanOrEqual(float|int $threshold, float|int $actual, string $message = ''): void
    {
        if ($actual < $threshold) {
            throw new AssertionError($message ?: "Expected {$actual} >= {$threshold}");
        }
    }

    private function assertArrayHasKey(string|int $key, array $array, string $message = ''): void
    {
        if (!array_key_exists($key, $array)) {
            throw new AssertionError($message ?: "Array does not contain key '{$key}'");
        }
    }

    private function assertContains(mixed $needle, array $haystack, string $message = ''): void
    {
        if (!in_array($needle, $haystack, true)) {
            throw new AssertionError($message ?: "Array does not contain value " . var_export($needle, true));
        }
    }

    private function assertStringContainsString(string $needle, string $haystack, string $message = ''): void
    {
        if (!str_contains($haystack, $needle)) {
            throw new AssertionError($message ?: "String '{$haystack}' does not contain '{$needle}'");
        }
    }

    private function assertIsArray(mixed $val, string $message = 'Expected array'): void
    {
        if (!is_array($val)) {
            throw new AssertionError($message);
        }
    }

    private function assertCount(int $expectedCount, array $array, string $message = ''): void
    {
        if (count($array) !== $expectedCount) {
            throw new AssertionError($message ?: "Expected count {$expectedCount}, got " . count($array));
        }
    }
}
