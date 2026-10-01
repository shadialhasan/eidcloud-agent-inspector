<?php

declare(strict_types=1);

namespace EidCloud\AgentInspector;

use EidCloud\AgentInspector\Filter\TraceFilter;
use EidCloud\AgentInspector\Formatter\ConsoleFormatter;
use EidCloud\AgentInspector\Packets\Frame;
use EidCloud\AgentInspector\Packets\Session;
use EidCloud\AgentInspector\Replay\SessionReplayer;
use RuntimeException;

/**
 * Main Inspector interface for parsing, inspecting, filtering, and replaying agent execution traces.
 */
class Inspector
{
    private ConsoleFormatter $formatter;

    public function __construct(bool $useColor = true)
    {
        $this->formatter = new ConsoleFormatter($useColor);
    }

    /**
     * Parse an execution trace from a JSON string or file.
     *
     * Supports formats:
     * - Direct JSON object with "frames" or "steps" array
     * - JSON array of frame objects
     * - JSONL format (one JSON object per line)
     */
    public function loadFromFile(string $filePath): Session
    {
        if (!file_exists($filePath)) {
            throw new RuntimeException("Trace file not found: {$filePath}");
        }

        $content = (string)file_get_contents($filePath);
        return $this->loadFromString($content, basename($filePath));
    }

    /**
     * Parse trace content string.
     */
    public function loadFromString(string $content, string $fallbackSessionId = 'session_unnamed'): Session
    {
        $trimmed = trim($content);
        if ($trimmed === '') {
            throw new RuntimeException("Trace content is empty");
        }

        // Try standard JSON first
        $decoded = json_decode($trimmed, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $this->parseJsonArrayOrObject($decoded, $fallbackSessionId);
        }

        // Try JSON Lines (JSONL)
        return $this->parseJsonLines($trimmed, $fallbackSessionId);
    }

    private function parseJsonArrayOrObject(array $data, string $fallbackSessionId): Session
    {
        // Case 1: Root object with "session_id", "frames" or "steps" or "trajectory"
        if (isset($data['frames']) || isset($data['steps']) || isset($data['trajectory'])) {
            $sessionId = (string)($data['session_id'] ?? $data['id'] ?? $fallbackSessionId);
            $rawFrames = (array)($data['frames'] ?? $data['steps'] ?? $data['trajectory'] ?? []);
            $metadata = (array)($data['metadata'] ?? []);

            // Copy root extra keys to metadata if relevant
            foreach (['agent_name', 'model', 'created_at', 'status', 'version'] as $key) {
                if (isset($data[$key]) && !isset($metadata[$key])) {
                    $metadata[$key] = $data[$key];
                }
            }

            $frames = [];
            $idx = 1;
            foreach ($rawFrames as $f) {
                if (is_array($f)) {
                    $frames[] = Frame::fromArray($f, $idx++);
                }
            }

            return new Session($sessionId, $metadata, $frames);
        }

        // Case 2: Array of frames
        if (array_is_list($data)) {
            $frames = [];
            $idx = 1;
            foreach ($data as $item) {
                if (is_array($item)) {
                    $frames[] = Frame::fromArray($item, $idx++);
                }
            }
            return new Session($fallbackSessionId, [], $frames);
        }

        // Single object frame or single turn
        return new Session($fallbackSessionId, [], [Frame::fromArray($data, 1)]);
    }

    private function parseJsonLines(string $jsonlContent, string $fallbackSessionId): Session
    {
        $lines = explode("\n", $jsonlContent);
        $frames = [];
        $metadata = [];
        $idx = 1;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $data = json_decode($line, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
                continue;
            }

            // Check if this line is header metadata
            if (isset($data['type']) && strtoupper((string)$data['type']) === 'METADATA') {
                $metadata = array_merge($metadata, $data);
                continue;
            }

            $frames[] = Frame::fromArray($data, $idx++);
        }

        return new Session($fallbackSessionId, $metadata, $frames);
    }

    public function filter(Session $session): TraceFilter
    {
        return new TraceFilter();
    }

    public function createReplayer(Session $session): SessionReplayer
    {
        return new SessionReplayer($session, $this->formatter);
    }

    public function getFormatter(): ConsoleFormatter
    {
        return $this->formatter;
    }

    public function exportJson(Session $session, bool $pretty = true): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if ($pretty) {
            $flags |= JSON_PRETTY_PRINT;
        }
        return (string)json_encode($session->toArray(), $flags);
    }
}
