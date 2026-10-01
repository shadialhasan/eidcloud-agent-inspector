<?php

declare(strict_types=1);

namespace EidCloud\AgentInspector\Packets;

/**
 * Encapsulates an entire agent trace / execution session comprising frames and session metadata.
 */
class Session
{
    /**
     * @param string $sessionId
     * @param array<string, mixed> $metadata
     * @param array<Frame> $frames
     */
    public function __construct(
        public readonly string $sessionId,
        public readonly array $metadata = [],
        private array $frames = []
    ) {}

    /**
     * @return array<Frame>
     */
    public function getFrames(): array
    {
        return $this->frames;
    }

    public function count(): int
    {
        return count($this->frames);
    }

    public function addFrame(Frame $frame): void
    {
        $this->frames[] = $frame;
    }

    public function totalLatencyMs(): float
    {
        $total = 0.0;
        foreach ($this->frames as $frame) {
            $total += $frame->latencyMs;
        }
        return $total;
    }

    public function totalTokens(): int
    {
        $tokens = 0;
        foreach ($this->frames as $frame) {
            $tokens += $frame->totalTokens();
        }
        return $tokens;
    }

    public function totalInputTokens(): int
    {
        $tokens = 0;
        foreach ($this->frames as $frame) {
            $tokens += $frame->inputTokens;
        }
        return $tokens;
    }

    public function totalOutputTokens(): int
    {
        $tokens = 0;
        foreach ($this->frames as $frame) {
            $tokens += $frame->outputTokens;
        }
        return $tokens;
    }

    /**
     * Returns an array of frames that took longer than threshold ms or exceeded token count.
     *
     * @param float $latencyThresholdMs
     * @param int $tokenThreshold
     * @return array<Frame>
     */
    public function findBottlenecks(float $latencyThresholdMs = 1500.0, int $tokenThreshold = 2000): array
    {
        $bottlenecks = [];
        foreach ($this->frames as $frame) {
            if ($frame->latencyMs >= $latencyThresholdMs || $frame->totalTokens() >= $tokenThreshold) {
                $bottlenecks[] = $frame;
            }
        }
        return $bottlenecks;
    }

    /**
     * Return frame counts categorized by frame type.
     *
     * @return array<string, int>
     */
    public function getStatistics(): array
    {
        $stats = [
            'total_frames' => count($this->frames),
            'total_latency_ms' => $this->totalLatencyMs(),
            'total_tokens' => $this->totalTokens(),
            'total_input_tokens' => $this->totalInputTokens(),
            'total_output_tokens' => $this->totalOutputTokens(),
            'by_type' => [],
            'tools_invoked' => [],
            'error_count' => 0,
        ];

        foreach ($this->frames as $frame) {
            $typeStr = $frame->type->value;
            $stats['by_type'][$typeStr] = ($stats['by_type'][$typeStr] ?? 0) + 1;

            if ($frame->toolName !== null) {
                $stats['tools_invoked'][$frame->toolName] = ($stats['tools_invoked'][$frame->toolName] ?? 0) + 1;
            }

            if ($frame->isError()) {
                $stats['error_count']++;
            }
        }

        return $stats;
    }

    public function toArray(): array
    {
        return [
            'session_id' => $this->sessionId,
            'metadata' => $this->metadata,
            'statistics' => $this->getStatistics(),
            'frames' => array_map(fn(Frame $f) => $f->toArray(), $this->frames),
        ];
    }
}
