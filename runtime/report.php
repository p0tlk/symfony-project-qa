<?php

declare(strict_types=1);

/** Escape untrusted project names, commands, reasons, and tool output for HTML. */
function qaReportEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Remove terminal formatting and OSC hyperlinks from displayed output only. */
function qaReportStripAnsi(string $value): string
{
    return preg_replace('~\x1B(?:\][^\x07\x1B]*(?:\x07|\x1B\\\\)|[PX^_].*?\x1B\\\\|\[[0-?]*[ -/]*[@-\x7e]|[ -/]*[0-\x7e])~s', '', $value) ?? $value;
}

/** @return array<array{name: string, path: string}> */
function qaReportArtifacts(string $reportDir, mixed $artifacts): array
{
    if (!is_array($artifacts)) {
        return [];
    }

    $valid = [];
    $directoryPrefix = rtrim(str_replace('\\', '/', $reportDir), '/') . '/';
    foreach ($artifacts as $artifact) {
        if (!is_array($artifact) || !is_string($artifact['name'] ?? null) || !is_string($artifact['path'] ?? null)) {
            continue;
        }
        $path = $artifact['path'];
        // The exporter uses the safe, underscore-prefixed filename _loader.neon.
        if (str_contains($path, '..') || preg_match('~^(?:[a-zA-Z0-9][a-zA-Z0-9._-]*|_loader\.neon)(?:/(?:[a-zA-Z0-9][a-zA-Z0-9._-]*|_loader\.neon))*$~D', $path) !== 1) {
            continue;
        }
        $resolved = realpath($reportDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path));
        if ($resolved === false || !is_file($resolved)) {
            continue;
        }
        $normalized = str_replace('\\', '/', $resolved);
        $inside = PHP_OS_FAMILY === 'Windows'
            ? strncasecmp($normalized, $directoryPrefix, strlen($directoryPrefix)) === 0
            : str_starts_with($normalized, $directoryPrefix);
        if ($inside) {
            $valid[] = ['name' => $artifact['name'], 'path' => $path];
        }
    }

    return $valid;
}

function qaReportStatus(mixed $value): string
{
    return in_array($value, ['passed', 'failed', 'skipped'], true) ? $value : 'failed';
}

/** Format argv for display only; this string is never executed. */
function qaReportCommand(array $command): string
{
    return implode(' ', array_map(static function (mixed $argument): string {
        $argument = is_scalar($argument) ? (string) $argument : '';
        if ($argument !== '' && preg_match('~^[a-zA-Z0-9_.,:\\\\/=@+%-]+$~D', $argument) === 1) {
            return $argument;
        }

        return '"' . str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\\"', '\\r', '\\n'], $argument) . '"';
    }, $command));
}

/**
 * Read only a direct log file in the report directory, never an arbitrary path.
 * Inline output is also accepted, for callers that have not persisted a log.
 *
 * @return array{filename: ?string, text: string}
 */
function qaReportLog(string $reportDir, array $check): array
{
    $log = is_string($check['log'] ?? null) ? $check['log'] : '';
    $output = is_string($check['output'] ?? null) ? $check['output'] : '';
    $isFilename = preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]*\.log$/D', $log) === 1;

    if ($isFilename) {
        $path = $reportDir . DIRECTORY_SEPARATOR . $log;
        $resolved = realpath($path);
        if ($resolved !== false && is_file($resolved)) {
            $parent = dirname($resolved);
            $inside = PHP_OS_FAMILY === 'Windows'
                ? strcasecmp($parent, $reportDir) === 0
                : $parent === $reportDir;
            if ($inside) {
                $contents = @file_get_contents($resolved);
                if ($contents !== false) {
                    return ['filename' => $log, 'text' => $contents];
                }
            }
        }

        return ['filename' => null, 'text' => $output !== '' ? $output : 'The log file is unavailable.'];
    }

    return ['filename' => null, 'text' => $output !== '' ? $output : $log];
}

/** Replace a report only after its complete replacement has been written. */
function qaReportAtomicWrite(string $reportDir, string $filename, string $contents): void
{
    $temporary = tempnam($reportDir, '.qa-report-');
    if ($temporary === false) {
        throw new RuntimeException('Cannot create a temporary report in ' . $reportDir);
    }

    $destination = $reportDir . DIRECTORY_SEPARATOR . $filename;
    try {
        $written = file_put_contents($temporary, $contents, LOCK_EX);
        if ($written !== strlen($contents)) {
            throw new RuntimeException('Cannot write complete report: ' . $destination);
        }

        if (@rename($temporary, $destination)) {
            return;
        }

        // Windows may require removing the previous single file before rename.
        if (PHP_OS_FAMILY === 'Windows' && (is_file($destination) || is_link($destination))) {
            if (!@unlink($destination)) {
                throw new RuntimeException('Cannot replace report: ' . $destination);
            }
            if (@rename($temporary, $destination)) {
                return;
            }
        }

        throw new RuntimeException('Cannot publish report: ' . $destination);
    } finally {
        if (is_file($temporary)) {
            @unlink($temporary);
        }
    }
}

/**
 * Write portable JSON, Markdown, and an offline HTML report for one QA run.
 *
 * @param array{project: string, mode: string, startedAt: string, finishedAt: string,
 *   status: string, counts?: array, checks: array<array<string, mixed>>,
 *   artifacts?: array<array{name: string, path: string}>} $report
 */
function qaWriteReports(string $reportDir, array $report): void
{
    if (!is_dir($reportDir) && !@mkdir($reportDir, 0777, true) && !is_dir($reportDir)) {
        throw new RuntimeException('Cannot create report directory: ' . $reportDir);
    }
    $resolvedDirectory = realpath($reportDir);
    if ($resolvedDirectory === false) {
        throw new RuntimeException('Cannot resolve report directory: ' . $reportDir);
    }
    $reportDir = $resolvedDirectory;

    $checks = is_array($report['checks'] ?? null) ? $report['checks'] : [];
    $counts = ['passed' => 0, 'failed' => 0, 'skipped' => 0, 'total' => count($checks)];
    $duration = 0.0;
    foreach ($checks as $check) {
        ++$counts[qaReportStatus($check['status'] ?? null)];
        $duration += max(0.0, (float) ($check['duration'] ?? 0));
    }
    $report['counts'] = array_replace(is_array($report['counts'] ?? null) ? $report['counts'] : [], $counts);

    $project = (string) ($report['project'] ?? 'Project');
    $mode = (string) ($report['mode'] ?? 'qa');
    $status = qaReportStatus($report['status'] ?? null);
    $startedAt = (string) ($report['startedAt'] ?? '');
    $finishedAt = (string) ($report['finishedAt'] ?? '');
    $e = static fn (string $value): string => qaReportEscape($value);
    $seconds = static fn (float $value): string => number_format($value, 2, '.', '') . ' s';

    $artifacts = qaReportArtifacts($reportDir, $report['artifacts'] ?? null);
    $artifactHtml = '';
    $artifactMarkdown = '';
    $baselineExport = false;
    foreach ($artifacts as $artifact) {
        $url = implode('/', array_map('rawurlencode', explode('/', $artifact['path'])));
        $artifactHtml .= '<li><a href="' . $e($url) . '" download>' . $e($artifact['name'])
            . '</a> <code>' . $e($artifact['path']) . '</code></li>';
        $markdownName = str_replace(
            ['\\', '[', ']', '`', '*', '_'],
            ['\\\\', '\\[', '\\]', '\\`', '\\*', '\\_'],
            $e(str_replace(["\r", "\n"], ' ', $artifact['name'])),
        );
        $artifactMarkdown .= '- [' . $markdownName . '](' . $url . ')' . "\n";
        $baselineExport = $baselineExport || str_starts_with($artifact['path'], 'baselines/');
    }
    if ($artifacts !== []) {
        $baselineNote = 'Baseline export groups current findings by identifier; it is not applied to project analysis.';
        $artifactHtml = '<section class="panel" aria-labelledby="artifacts"><h2 id="artifacts">Generated artifacts</h2>'
            . ($baselineExport ? '<p class="muted">' . $baselineNote . '</p>' : '')
            . '<ul class="artifacts">' . $artifactHtml . '</ul></section>';
        $artifactMarkdown = '## Generated artifacts' . "\n\n"
            . ($baselineExport ? $baselineNote . "\n\n" : '') . $artifactMarkdown . "\n";
    }

    $tableRows = '';
    $details = '';
    $markdownRows = [];
    foreach ($checks as $index => $check) {
        $name = (string) ($check['name'] ?? 'Check');
        $checkStatus = qaReportStatus($check['status'] ?? null);
        $exitCode = isset($check['exitCode']) ? (string) $check['exitCode'] : '—';
        $checkDuration = $seconds(max(0.0, (float) ($check['duration'] ?? 0)));
        $command = qaReportCommand(is_array($check['command'] ?? null) ? $check['command'] : []);
        $reason = is_string($check['reason'] ?? null) ? $check['reason'] : '';
        $log = qaReportLog($reportDir, $check);
        $anchor = 'check-' . ((int) $index + 1);
        $badge = '<span class="badge ' . $checkStatus . '">' . ucfirst($checkStatus) . '</span>';

        $tableRows .= '<tr><th scope="row"><a href="#' . $anchor . '">' . $e($name) . '</a></th>'
            . '<td>' . $badge . '</td><td class="number">' . $e($exitCode) . '</td>'
            . '<td class="number">' . $e($checkDuration) . '</td></tr>' . "\n";

        $details .= '<article class="check" id="' . $anchor . '"><div class="check-heading"><h3>'
            . $e($name) . '</h3>' . $badge . '</div><p class="check-meta">Exit code <strong>'
            . $e($exitCode) . '</strong><span aria-hidden="true"> · </span>Duration <strong>'
            . $e($checkDuration) . '</strong></p>';
        if ($reason !== '') {
            $details .= '<p class="reason">' . $e($reason) . '</p>';
        }
        if ($command !== '') {
            $details .= '<div class="command"><span>Command</span><code>' . $e($command) . '</code></div>';
        }
        if ($log['text'] !== '' || $log['filename'] !== null) {
            $details .= '<details><summary>Tool output</summary>';
            if ($log['filename'] !== null) {
                $details .= '<p class="log-link"><a href="' . $e(rawurlencode($log['filename']))
                    . '" download>Download ' . $e($log['filename']) . '</a></p>';
            }
            $details .= '<pre><code>' . $e(qaReportStripAnsi($log['text'] !== '' ? $log['text'] : '(No output.)'))
                . '</code></pre></details>';
        } else {
            $details .= '<p class="muted">No tool output.</p>';
        }
        $details .= '</article>' . "\n";

        $markdownName = str_replace('|', '&#124;', $e(str_replace(["\r", "\n"], ' ', $name)));
        $markdownReason = str_replace('|', '&#124;', $e(str_replace(["\r", "\n"], ' ', $reason)));
        $markdownLog = $log['filename'] !== null
            ? '[log](' . rawurlencode($log['filename']) . ')'
            : '—';
        $markdownRows[] = '| ' . $markdownName . ' | ' . $checkStatus . ' | ' . $exitCode . ' | '
            . $checkDuration . ' | ' . $markdownLog . ' | ' . $markdownReason . ' |';
    }

    if ($checks === []) {
        $tableRows = '<tr><td colspan="4" class="empty">No checks were recorded.</td></tr>';
        $details = '<p class="empty">No tool output is available.</p>';
    }

    $style = <<<'CSS'
:root{color-scheme:light dark;--bg:#f3f5f9;--surface:#fff;--text:#172337;--muted:#5c687b;--line:#dce2eb;--accent:#3458a2;--pass:#11613a;--pass-bg:#e6f5ed;--fail:#a62532;--fail-bg:#fdecef;--skip:#6b560e;--skip-bg:#fff6dc;--code:#101b2b;--code-text:#e1e9f6}
*{box-sizing:border-box}html{scroll-behavior:smooth}body{margin:0;background:var(--bg);color:var(--text);font:15px/1.6 system-ui,-apple-system,"Segoe UI",sans-serif}a{color:var(--accent);text-underline-offset:3px}a:hover{text-decoration-thickness:2px}a:focus-visible,summary:focus-visible{outline:3px solid var(--accent);outline-offset:4px}.page{max-width:1120px;margin:0 auto;padding:44px 28px 36px}.eyebrow{text-transform:uppercase;letter-spacing:.15em;color:var(--muted);font-size:12px;font-weight:700;margin:0 0 8px}.hero{display:flex;justify-content:space-between;align-items:flex-start;gap:24px}h1{font-size:36px;line-height:1.2;margin:0 0 12px;letter-spacing:-.025em}h2{font-size:20px;margin:0 0 18px}h3{font-size:17px;margin:0}.project{margin:0;overflow-wrap:anywhere;font-family:ui-monospace,Consolas,monospace;color:var(--muted)}.run-status{font-size:16px;padding:8px 16px;flex-shrink:0}.badge{display:inline-block;line-height:1.4;border-radius:6px;padding:4px 9px;font-size:12px;font-weight:700}.passed{color:var(--pass);background:var(--pass-bg)}.failed{color:var(--fail);background:var(--fail-bg)}.skipped{color:var(--skip);background:var(--skip-bg)}.metadata{display:flex;gap:12px 28px;flex-wrap:wrap;margin:24px 0 30px;color:var(--muted);font-size:13px}.metadata div{display:flex;gap:8px}.metadata dt{font-weight:600}.metadata dd{margin:0}.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin:0 0 30px}.stat{border:1px solid var(--line);background:var(--surface);padding:18px 22px;border-radius:10px}.stat strong{display:block;font-size:30px;line-height:1.2}.stat span{display:block;color:var(--muted);font-size:13px;margin-top:6px}.stat.passed-count strong{color:var(--pass)}.stat.failed-count strong{color:var(--fail)}.stat.skipped-count strong{color:var(--skip)}.panel{border:1px solid var(--line);background:var(--surface);padding:24px;border-radius:12px;margin-bottom:28px}.table-scroll{overflow-x:auto}table{width:100%;border-collapse:collapse;font-size:14px}th,td{padding:12px 10px;border-bottom:1px solid var(--line);text-align:left}thead th{color:var(--muted);font-size:11px;font-weight:700;letter-spacing:.07em;text-transform:uppercase}tbody th{font-weight:600}tbody tr:last-child th,tbody tr:last-child td{border-bottom:0}.number{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}.check{scroll-margin-top:18px;border:1px solid var(--line);background:var(--surface);border-radius:12px;padding:22px 24px;margin-bottom:16px}.check-heading{display:flex;align-items:center;justify-content:space-between;gap:16px}.check-meta{font-size:12px;color:var(--muted);margin:8px 0 16px}.check-meta strong{font-weight:500}.reason{border-left:3px solid var(--line);padding-left:12px;overflow-wrap:anywhere}.command{margin:16px 0;display:flex;flex-direction:column;gap:6px}.command span{font-size:11px;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;font-weight:700}.command code{background:var(--bg);border:1px solid var(--line);border-radius:6px;padding:10px 12px;white-space:pre-wrap;overflow-wrap:anywhere;font-size:12px}summary{cursor:pointer;color:var(--accent);font-size:13px;font-weight:600;padding:4px 0}pre{margin:10px 0 0;max-height:560px;overflow:auto;background:var(--code);color:var(--code-text);padding:18px;border-radius:7px;font-size:12px;line-height:1.6;tab-size:4;white-space:pre-wrap;overflow-wrap:anywhere}code{font-family:ui-monospace,"Cascadia Code",Consolas,monospace}.log-link{margin:10px 0;font-size:12px}.muted,.empty{color:var(--muted);font-size:13px}.empty{padding:16px 0}.downloads{display:flex;gap:22px;flex-wrap:wrap;font-size:13px}footer{margin-top:28px;padding-top:20px;border-top:1px solid var(--line);color:var(--muted);font-size:12px}
.artifacts{margin:0;padding-left:20px}.artifacts li+li{margin-top:8px}.artifacts code{display:inline-block;font-size:12px;color:var(--muted);margin-left:8px;overflow-wrap:anywhere}
@media(prefers-color-scheme:dark){:root{--bg:#101721;--surface:#172130;--text:#e5ebf5;--muted:#a3afc1;--line:#334155;--accent:#9ebeff;--pass:#82dfad;--pass-bg:#173b2d;--fail:#ffadb7;--fail-bg:#482632;--skip:#ead18a;--skip-bg:#40391f;--code:#0a111c;--code-text:#e1e9f6}}
@media(max-width:650px){.page{padding:26px 16px}.hero{flex-direction:column;gap:16px}h1{font-size:30px}.stats{grid-template-columns:repeat(2,1fr);gap:10px}.stat{padding:16px}.panel,.check{padding:18px 16px}.metadata{flex-direction:column;gap:8px}.metadata div{flex-wrap:wrap}.check-heading{align-items:flex-start}.check-heading h3{overflow-wrap:anywhere}.check-heading .badge{flex-shrink:0}th,td{padding:10px 6px}.downloads{gap:12px}}
@media print{:root{color-scheme:light;--bg:#fff;--surface:#fff;--text:#111;--muted:#555;--line:#ccc;--accent:#111}.page{padding:0}.panel,.check{break-inside:avoid}footer,.downloads{display:none}pre{max-height:none;background:#f3f3f3;color:#111}a{text-decoration:none}}
CSS;

    $html = '<!doctype html>' . "\n" . '<html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="color-scheme" content="light dark"><title>QA report — ' . $e($project) . '</title>'
        . '<style>' . $style . '</style></head><body><main class="page">'
        . '<header><div class="hero"><div><p class="eyebrow">Project quality assurance</p>'
        . '<h1>QA report</h1><p class="project">' . $e($project) . '</p></div>'
        . '<span class="badge run-status ' . $status . '">Run ' . $status . '</span></div>'
        . '<dl class="metadata"><div><dt>Mode</dt><dd>' . $e($mode) . '</dd></div>'
        . '<div><dt>Started</dt><dd>' . $e($startedAt) . '</dd></div>'
        . '<div><dt>Finished</dt><dd>' . $e($finishedAt) . '</dd></div>'
        . '<div><dt>Tool time</dt><dd>' . $e($seconds($duration)) . '</dd></div></dl></header>'
        . '<section class="stats" aria-label="Check totals"><div class="stat"><strong>' . $counts['total']
        . '</strong><span>Total checks</span></div><div class="stat passed-count"><strong>' . $counts['passed']
        . '</strong><span>Passed</span></div><div class="stat failed-count"><strong>' . $counts['failed']
        . '</strong><span>Failed</span></div><div class="stat skipped-count"><strong>' . $counts['skipped']
        . '</strong><span>Skipped</span></div></section>'
        . '<section class="panel" aria-labelledby="overview"><h2 id="overview">Checks at a glance</h2>'
        . '<div class="table-scroll"><table><thead><tr><th scope="col">Tool</th><th scope="col">Result</th>'
        . '<th scope="col" class="number">Exit code</th><th scope="col" class="number">Duration</th>'
        . '</tr></thead><tbody>' . $tableRows . '</tbody></table></div></section>'
        . $artifactHtml . '<section aria-labelledby="output"><h2 id="output">Tool details</h2>' . $details . '</section>'
        . '<footer><nav class="downloads" aria-label="Other report formats"><a href="report.json" download>JSON report</a>'
        . '<a href="summary.md" download>Markdown summary</a></nav>'
        . '<p>Generated by qa. This report works offline; all tool logs remain beside it.</p></footer>'
        . '</main></body></html>' . "\n";

    $markdown = '# QA report' . "\n\n"
        . '**Project:** <code>' . $e(str_replace(["\r", "\n"], ' ', $project)) . '</code>' . "\n\n"
        . '**Status:** ' . $status . ' · **Mode:** <code>' . $e($mode) . '</code>' . "\n\n"
        . '**Started:** ' . $e($startedAt) . '  ' . "\n"
        . '**Finished:** ' . $e($finishedAt) . '  ' . "\n"
        . '**Tool time:** ' . $seconds($duration) . "\n\n"
        . $counts['passed'] . ' passed · ' . $counts['failed'] . ' failed · ' . $counts['skipped']
        . ' skipped · ' . $counts['total'] . ' total' . "\n\n" . $artifactMarkdown
        . '| Tool | Status | Exit code | Duration | Output | Reason |' . "\n"
        . '| --- | --- | ---: | ---: | --- | --- |' . "\n"
        . implode("\n", $markdownRows) . "\n\n"
        . '[Open the HTML report](report.html) for commands and full tool output. '
        . '[JSON report](report.json)' . "\n";

    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    qaReportAtomicWrite($reportDir, 'report.json', $json . "\n");
    qaReportAtomicWrite($reportDir, 'summary.md', $markdown);
    qaReportAtomicWrite($reportDir, 'report.html', $html);
}
