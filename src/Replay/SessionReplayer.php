<?php

declare(strict_types=1);

namespace EidCloud\AgentInspector\Replay;

use EidCloud\AgentInspector\Formatter\ConsoleFormatter;
use EidCloud\AgentInspector\Packets\Frame;
use EidCloud\AgentInspector\Packets\Session;

/**
 * Interactive or automated session replayer that steps through execution turn-by-turn.
 */
class SessionReplayer
{
    private int $currentIndex = 0;
    private ConsoleFormatter $formatter;

    public function __construct(
        public readonly Session $session,
        ?ConsoleFormatter $formatter = null
    ) {
        $this->formatter = $formatter ?? new ConsoleFormatter();
    }

    public function reset(): void
    {
        $this->currentIndex = 0;
    }

    public function getCurrentIndex(): int
    {
        return $this->currentIndex;
    }

    public function hasNext(): bool
    {
        return $this->currentIndex < count($this->session->getFrames());
    }

    public function next(): ?Frame
    {
        $frames = $this->session->getFrames();
        if ($this->currentIndex >= count($frames)) {
            return null;
        }

        $frame = $frames[$this->currentIndex];
        $this->currentIndex++;
        return $frame;
    }

    public function current(): ?Frame
    {
        $frames = $this->session->getFrames();
        return $frames[$this->currentIndex] ?? null;
    }

    /**
     * Replay session automatically with a simulated speed multiplier (e.g. 1x, 2x, 5x, 0 for instant).
     *
     * @param float $speedMultiplier
     * @param callable|null $onFrame Callback invoked on each frame: fn(Frame $frame, int $index, int $total)
     */
    public function replay(float $speedMultiplier = 1.0, ?callable $onFrame = null): void
    {
        $this->reset();
        $frames = $this->session->getFrames();
        $total = count($frames);

        while ($this->hasNext()) {
            $index = $this->currentIndex + 1;
            $frame = $this->next();
            if ($frame === null) {
                break;
            }

            if ($onFrame !== null) {
                $onFrame($frame, $index, $total);
            } else {
                echo $this->formatter->renderFrameDetail($frame);
            }

            if ($speedMultiplier > 0 && $this->hasNext()) {
                // Sleep proportional to frame latency (clamped between 50ms and 1500ms scaled)
                $sleepMs = ($frame->latencyMs / max($speedMultiplier, 0.1));
                $sleepMs = min(max($sleepMs, 50), 1000);
                usleep((int)($sleepMs * 1000));
            }
        }
    }
}
