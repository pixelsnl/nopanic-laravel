<?php

namespace NoPanic\Laravel\Report;

use SplFileObject;
use Throwable;

class FrameCollector
{
    /**
     * @return array<int, array{file: string, line: int, class: ?string, method: ?string, application_frame: bool, code_snippet: array<int, string>}>
     */
    public function collect(Throwable $exception): array
    {
        $maxFrames = max((int) config('nopanic.max_frames', 50), 0);
        $snippetLines = max((int) config('nopanic.snippet_lines', 10), 1);

        $file = $exception->getFile();
        $line = $exception->getLine();
        $frames = [];

        foreach ($exception->getTrace() as $traceEntry) {
            $frames[] = $this->makeFrame($file, $line, $traceEntry['class'] ?? null, $traceEntry['function'] ?? null, $snippetLines);

            if (count($frames) >= $maxFrames) {
                return $frames;
            }

            $file = $traceEntry['file'] ?? 'unknown';
            $line = $traceEntry['line'] ?? 0;
        }

        $frames[] = $this->makeFrame($file, $line, null, null, $snippetLines);

        return array_slice($frames, 0, $maxFrames);
    }

    /**
     * @return array{file: string, line: int, class: ?string, method: ?string, application_frame: bool, code_snippet: array<int, string>}
     */
    protected function makeFrame(string $absolutePath, int $line, ?string $class, ?string $method, int $snippetLines): array
    {
        $isApplicationFrame = $this->isApplicationFrame($absolutePath);

        return [
            'file' => $this->relativePath($absolutePath),
            'line' => $line,
            'class' => $class,
            'method' => $method,
            'application_frame' => $isApplicationFrame,
            'code_snippet' => $isApplicationFrame ? $this->codeSnippet($absolutePath, $line, $snippetLines) : [],
        ];
    }

    protected function isApplicationFrame(string $absolutePath): bool
    {
        if (! $this->isInside($absolutePath, base_path())) {
            return false;
        }

        return ! $this->isInside($absolutePath, base_path('vendor'));
    }

    protected function isInside(string $absolutePath, string $directory): bool
    {
        return str_starts_with($absolutePath, rtrim($directory, '/\\').DIRECTORY_SEPARATOR);
    }

    protected function relativePath(string $absolutePath): string
    {
        if (! $this->isInside($absolutePath, base_path())) {
            return $absolutePath;
        }

        return '/'.ltrim(str_replace('\\', '/', substr($absolutePath, strlen(rtrim(base_path(), '/\\')))), '/');
    }

    /**
     * @return array<int, string>
     */
    protected function codeSnippet(string $absolutePath, int $line, int $snippetLines): array
    {
        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            return [];
        }

        try {
            $file = new SplFileObject($absolutePath);
            $file->seek(PHP_INT_MAX);
            $totalLines = $file->key() + 1;

            $startLine = max($line - (int) floor($snippetLines / 2), 1);
            $endLine = $startLine + $snippetLines - 1;

            if ($endLine > $totalLines) {
                $endLine = $totalLines;
                $startLine = max($endLine - $snippetLines + 1, 1);
            }

            $snippet = [];
            $file->seek($startLine - 1);

            for ($current = $startLine; $current <= $endLine; $current++) {
                $snippet[$current] = rtrim(substr((string) $file->current(), 0, 250));
                $file->next();
            }

            return $snippet;
        } catch (Throwable) {
            return [];
        }
    }
}
