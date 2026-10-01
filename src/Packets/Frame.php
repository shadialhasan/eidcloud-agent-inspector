<?php

declare(strict_types=1);

namespace EidCloud\AgentInspector\Packets;

/**
 * Represents a single network-like packet frame in an AI Agent execution session.
 */
class Frame
{
    public function __construct(
        public readonly int $frameId,
        public readonly FrameType $type,
        public readonly string $timestamp,
        public readonly float $latencyMs,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly ?string $toolName = null,
        public readonly mixed $payload = null,
        public readonly array $metadata = []
    ) {}

    public function totalTokens(): int
    {
        return $this->inputTokens + $this->outputTokens;
    }

    public function isTool(): bool
    {
        return $this->type === FrameType::TOOL_CALL || $this->type === FrameType::TOOL_RESULT;
    }

    public function isError(): bool
    {
        return $this->type === FrameType::ERROR || ($this->metadata['is_error'] ?? false) === true;
    }

    public function toArray(): array
    {
        return [
            'frame_id' => $this->frameId,
            'type' => $this->type->value,
            'timestamp' => $this->timestamp,
            'latency_ms' => $this->latencyMs,
            'input_tokens' => $this->inputTokens,
            'output_tokens' => $this->outputTokens,
            'total_tokens' => $this->totalTokens(),
            'tool_name' => $this->toolName,
            'payload' => $this->payload,
            'metadata' => $this->metadata,
        ];
    }

    public static function fromArray(array $data, int $defaultIndex = 1): self
    {
        $rawType = $data['type'] ?? $data['frame_type'] ?? 'FRAME_PROMPT';
        $type = match (strtoupper((string)$rawType)) {
            'FRAME_PROMPT', 'PROMPT', 'USER' => FrameType::PROMPT,
            'FRAME_THINK', 'THINK', 'THOUGHT', 'REASONING' => FrameType::THINK,
            'FRAME_TOOL_CALL', 'TOOL_CALL', 'TOOL_INVOCATION', 'ACTION' => FrameType::TOOL_CALL,
            'FRAME_TOOL_RESULT', 'TOOL_RESULT', 'OBSERVATION', 'TOOL_OUTPUT' => FrameType::TOOL_RESULT,
            'FRAME_ANSWER', 'ANSWER', 'ASSISTANT', 'RESPONSE', 'FINAL_ANSWER' => FrameType::ANSWER,
            'FRAME_ERROR', 'ERROR', 'EXCEPTION' => FrameType::ERROR,
            'FRAME_CONTEXT', 'CONTEXT', 'SYSTEM' => FrameType::CONTEXT,
            default => FrameType::PROMPT,
        };

        $frameId = (int)($data['frame_id'] ?? $data['id'] ?? $data['step'] ?? $defaultIndex);
        $timestamp = (string)($data['timestamp'] ?? $data['time'] ?? date('c'));
        $latencyMs = (float)($data['latency_ms'] ?? $data['latency'] ?? $data['duration_ms'] ?? 0.0);
        $inputTokens = (int)($data['input_tokens'] ?? $data['prompt_tokens'] ?? 0);
        $outputTokens = (int)($data['output_tokens'] ?? $data['completion_tokens'] ?? 0);
        $toolName = isset($data['tool_name']) ? (string)$data['tool_name'] : ($data['tool'] ?? null);
        $payload = $data['payload'] ?? $data['content'] ?? $data['data'] ?? null;
        $metadata = (array)($data['metadata'] ?? []);

        if (isset($data['model']) && !isset($metadata['model'])) {
            $metadata['model'] = $data['model'];
        }
        if (isset($data['status']) && !isset($metadata['status'])) {
            $metadata['status'] = $data['status'];
        }

        return new self(
            frameId: $frameId,
            type: $type,
            timestamp: $timestamp,
            latencyMs: $latencyMs,
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            toolName: $toolName,
            payload: $payload,
            metadata: $metadata
        );
    }
}
