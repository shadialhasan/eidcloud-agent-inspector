<?php

declare(strict_types=1);

namespace EidCloud\AgentInspector\Filter;

use EidCloud\AgentInspector\Packets\Frame;
use EidCloud\AgentInspector\Packets\FrameType;
use EidCloud\AgentInspector\Packets\Session;

/**
 * Filter and query agent execution frames by criteria such as tool name, latency, tokens, error state, and text search.
 */
class TraceFilter
{
    private ?string $toolName = null;
    private ?FrameType $type = null;
    private ?float $minLatencyMs = null;
    private ?int $minTokens = null;
    private ?bool $onlyErrors = null;
    private ?string $searchQuery = null;

    public function byTool(?string $toolName): self
    {
        $this->toolName = $toolName;
        return $this;
    }

    public function byType(?FrameType $type): self
    {
        $this->type = $type;
        return $this;
    }

    public function minLatency(float $ms): self
    {
        $this->minLatencyMs = $ms;
        return $this;
    }

    public function minTokens(int $tokens): self
    {
        $this->minTokens = $tokens;
        return $this;
    }

    public function errorsOnly(bool $only = true): self
    {
        $this->onlyErrors = $only;
        return $this;
    }

    public function search(?string $query): self
    {
        $this->searchQuery = $query;
        return $this;
    }

    /**
     * @param array<Frame> $frames
     * @return array<Frame>
     */
    public function filter(array $frames): array
    {
        return array_values(array_filter($frames, fn(Frame $frame) => $this->matches($frame)));
    }

    public function filterSession(Session $session): Session
    {
        $filtered = $this->filter($session->getFrames());
        return new Session(
            sessionId: $session->sessionId . ' (filtered)',
            metadata: array_merge($session->metadata, ['filtered' => true]),
            frames: $filtered
        );
    }

    public function matches(Frame $frame): bool
    {
        if ($this->toolName !== null) {
            if ($frame->toolName === null || stripos($frame->toolName, $this->toolName) === false) {
                return false;
            }
        }

        if ($this->type !== null && $frame->type !== $this->type) {
            return false;
        }

        if ($this->minLatencyMs !== null && $frame->latencyMs < $this->minLatencyMs) {
            return false;
        }

        if ($this->minTokens !== null && $frame->totalTokens() < $this->minTokens) {
            return false;
        }

        if ($this->onlyErrors === true && !$frame->isError()) {
            return false;
        }

        if ($this->searchQuery !== null && trim($this->searchQuery) !== '') {
            $payloadStr = is_string($frame->payload)
                ? $frame->payload
                : json_encode($frame->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $haystack = ($frame->toolName ?? '') . ' ' . $payloadStr . ' ' . json_encode($frame->metadata);
            if (stripos($haystack, $this->searchQuery) === false) {
                return false;
            }
        }

        return true;
    }
}
