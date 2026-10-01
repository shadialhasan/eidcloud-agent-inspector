<?php

declare(strict_types=1);

namespace EidCloud\AgentInspector\Formatter;

use EidCloud\AgentInspector\Packets\Frame;
use EidCloud\AgentInspector\Packets\FrameType;
use EidCloud\AgentInspector\Packets\Session;

/**
 * Renders rich ANSI colored terminal timeline and tables for frames and sessions.
 */
class ConsoleFormatter
{
    private const RESET = "\033[0m";
    private const BOLD = "\033[1m";
    private const DIM = "\033[2m";
    private const UNDERLINE = "\033[4m";

    // Colors
    private const RED = "\033[31m";
    private const GREEN = "\033[32m";
    private const YELLOW = "\033[33m";
    private const BLUE = "\033[34m";
    private const MAGENTA = "\033[35m";
    private const CYAN = "\033[36m";
    private const WHITE = "\033[37m";
    private const GRAY = "\033[90m";
    private const BRIGHT_RED = "\033[91m";
    private const BRIGHT_GREEN = "\033[92m";
    private const BRIGHT_YELLOW = "\033[93m";
    private const BRIGHT_BLUE = "\033[94m";
    private const BRIGHT_CYAN = "\033[96m";

    public function __construct(private readonly bool $useColor = true) {}

    private function color(string $code, string $text): string
    {
        return $this->useColor ? "{$code}{$text}" . self::RESET : $text;
    }

    public function renderHeader(string $title): string
    {
        $line = str_repeat('═', 78);
        $out = "\n" . $this->color(self::BRIGHT_CYAN . self::BOLD, "╔{$line}╗") . "\n";
        $padded = str_pad("  🔍 {$title}", 78);
        $out .= $this->color(self::BRIGHT_CYAN . self::BOLD, "║") . $this->color(self::BOLD . self::WHITE, $padded) . $this->color(self::BRIGHT_CYAN . self::BOLD, "║") . "\n";
        $out .= $this->color(self::BRIGHT_CYAN . self::BOLD, "╚{$line}╝") . "\n";
        return $out;
    }

    public function renderSessionSummary(Session $session): string
    {
        $stats = $session->getStatistics();
        $out = $this->renderHeader("SESSION OVERVIEW: {$session->sessionId}");

        $out .= $this->color(self::BOLD, " Metadata:\n");
        if (empty($session->metadata)) {
            $out .= "   (none)\n";
        } else {
            foreach ($session->metadata as $k => $v) {
                $valStr = is_scalar($v) ? (string)$v : json_encode($v);
                $out .= "   • " . $this->color(self::CYAN, $k) . ": {$valStr}\n";
            }
        }

        $out .= "\n" . $this->color(self::BOLD, " Metrics Summary:\n");
        $out .= "   • Total Frames   : " . $this->color(self::BRIGHT_GREEN . self::BOLD, (string)$stats['total_frames']) . "\n";
        $out .= "   • Total Latency  : " . $this->color(self::BRIGHT_YELLOW, sprintf('%.2f ms (%.2fs)', $stats['total_latency_ms'], $stats['total_latency_ms'] / 1000)) . "\n";
        $out .= "   • Total Tokens   : " . $this->color(self::BRIGHT_BLUE, number_format($stats['total_tokens'])) . " (In: {$stats['total_input_tokens']} / Out: {$stats['total_output_tokens']})\n";
        $out .= "   • Error Count    : " . ($stats['error_count'] > 0 ? $this->color(self::BRIGHT_RED . self::BOLD, (string)$stats['error_count']) : $this->color(self::GREEN, "0")) . "\n";

        if (!empty($stats['tools_invoked'])) {
            $out .= "\n" . $this->color(self::BOLD, " Tools Invoked:\n");
            foreach ($stats['tools_invoked'] as $tool => $cnt) {
                $out .= "   ⚙ " . $this->color(self::YELLOW, $tool) . ": {$cnt} call(s)\n";
            }
        }

        $out .= "\n";
        return $out;
    }

    public function renderTimelineTable(array $frames): string
    {
        if (empty($frames)) {
            return $this->color(self::GRAY, "  (No packet frames found)\n");
        }

        $line = str_repeat('─', 84);
        $out = $this->color(self::GRAY, "┌────┬──────────────────┬─────────────┬───────────┬─────────────────────────────────┐\n");
        $out .= sprintf(
            "│ %-2s │ %-16s │ %-11s │ %-9s │ %-31s │\n",
            $this->color(self::BOLD, "ID"),
            $this->color(self::BOLD, "FRAME TYPE"),
            $this->color(self::BOLD, "LATENCY"),
            $this->color(self::BOLD, "TOKENS"),
            $this->color(self::BOLD, "PAYLOAD PREVIEW / TOOL")
        );
        $out .= $this->color(self::GRAY, "├────┼──────────────────┼─────────────┼───────────┼─────────────────────────────────┤\n");

        foreach ($frames as $frame) {
            $typeColor = $frame->type->ansiColor();
            $typeStr = $this->color($typeColor . self::BOLD, sprintf('%-16s', $frame->type->label()));

            $latStr = sprintf('%7.1f ms', $frame->latencyMs);
            if ($frame->latencyMs > 2000) {
                $latStr = $this->color(self::BRIGHT_RED, $latStr);
            } elseif ($frame->latencyMs > 800) {
                $latStr = $this->color(self::YELLOW, $latStr);
            } else {
                $latStr = $this->color(self::GRAY, $latStr);
            }

            $tokStr = sprintf('%5d tok', $frame->totalTokens());
            $tokStr = $this->color(self::CYAN, $tokStr);

            $detail = '';
            if ($frame->toolName) {
                $detail .= '[' . $this->color(self::YELLOW, $frame->toolName) . '] ';
            }
            $preview = $this->snippet($frame->payload);
            $detail .= $preview;

            // Plain text length calculation for table formatting
            $cleanPreview = ($frame->toolName ? "[{$frame->toolName}] " : "") . $this->snippet($frame->payload);
            $truncatedPreview = mb_strimwidth($cleanPreview, 0, 31, '…');
            $detailFormatted = sprintf('%-31s', $truncatedPreview);

            $out .= sprintf(
                "│ %02d │ %s │ %s │ %s │ %s │\n",
                $frame->frameId,
                $typeStr,
                $latStr,
                $tokStr,
                $detailFormatted
            );
        }

        $out .= $this->color(self::GRAY, "└────┴──────────────────┴─────────────┴───────────┴─────────────────────────────────┘\n");
        return $out;
    }

    public function renderFrameDetail(Frame $frame): string
    {
        $typeColor = $frame->type->ansiColor();
        $title = "FRAME #" . $frame->frameId . " [" . $frame->type->label() . "]";
        $out = $this->color($typeColor . self::BOLD, "\n┌─── {$title} " . str_repeat('─', max(0, 60 - strlen($title)))) . "\n";
        $out .= "│ " . $this->color(self::BOLD, "Timestamp:") . " {$frame->timestamp}\n";
        $out .= "│ " . $this->color(self::BOLD, "Latency  :") . " {$frame->latencyMs} ms\n";
        $out .= "│ " . $this->color(self::BOLD, "Tokens   :") . " In: {$frame->inputTokens}, Out: {$frame->outputTokens} (Total: {$frame->totalTokens()})\n";

        if ($frame->toolName !== null) {
            $out .= "│ " . $this->color(self::BOLD, "Tool Name:") . " " . $this->color(self::YELLOW . self::BOLD, $frame->toolName) . "\n";
        }

        if (!empty($frame->metadata)) {
            $out .= "│ " . $this->color(self::BOLD, "Metadata :") . " " . json_encode($frame->metadata, JSON_UNESCAPED_SLASHES) . "\n";
        }

        $out .= "│ " . $this->color(self::BOLD, "Payload  :") . "\n";

        $payloadText = is_string($frame->payload)
            ? $frame->payload
            : json_encode($frame->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        foreach (explode("\n", (string)$payloadText) as $line) {
            $out .= "│   " . $line . "\n";
        }

        $out .= $this->color($typeColor, "└───" . str_repeat('─', 65)) . "\n";
        return $out;
    }

    private function snippet(mixed $payload): string
    {
        if ($payload === null) {
            return '(empty)';
        }
        if (is_array($payload)) {
            $payload = json_encode($payload, JSON_UNESCAPED_SLASHES);
        }
        $str = preg_replace('/\s+/', ' ', (string)$payload);
        return trim((string)$str);
    }
}
