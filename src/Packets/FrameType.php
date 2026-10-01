<?php

declare(strict_types=1);

namespace EidCloud\AgentInspector\Packets;

/**
 * Enumeration of standard packet frame types in AI Agent communication.
 */
enum FrameType: string
{
    case PROMPT = 'FRAME_PROMPT';
    case THINK = 'FRAME_THINK';
    case TOOL_CALL = 'FRAME_TOOL_CALL';
    case TOOL_RESULT = 'FRAME_TOOL_RESULT';
    case ANSWER = 'FRAME_ANSWER';
    case ERROR = 'FRAME_ERROR';
    case CONTEXT = 'FRAME_CONTEXT';

    public function label(): string
    {
        return match ($this) {
            self::PROMPT => 'PROMPT',
            self::THINK => 'THINK',
            self::TOOL_CALL => 'TOOL_CALL',
            self::TOOL_RESULT => 'TOOL_RESULT',
            self::ANSWER => 'ANSWER',
            self::ERROR => 'ERROR',
            self::CONTEXT => 'CONTEXT',
        };
    }

    public function ansiColor(): string
    {
        return match ($this) {
            self::PROMPT => "\033[36m",      // Cyan
            self::THINK => "\033[35m",       // Magenta
            self::TOOL_CALL => "\033[33m",   // Yellow
            self::TOOL_RESULT => "\033[32m", // Green
            self::ANSWER => "\033[1;34m",    // Bright Blue
            self::ERROR => "\033[1;31m",     // Bright Red
            self::CONTEXT => "\033[90m",     // Dark Gray
        };
    }
}
