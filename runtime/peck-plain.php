<?php

declare(strict_types=1);

/** Check identifiers without loading or executing a plain PHP application's files. */
function qaPlainSpelling(Peck\Config $config, Peck\Services\Spellcheckers\Aspell $aspell, string $root): array
{
    require_once __DIR__ . '/source-files.php';
    $parser = (new PhpParser\ParserFactory())->createForHostVersion();
    $finder = new PhpParser\NodeFinder();
    $issues = [];
    foreach (qaReadSourceManifest() as $file) {
        $relative = ltrim(substr(str_replace('\\', '/', $file), strlen(str_replace('\\', '/', $root))), '/');
        $ignored = false;
        foreach ($config->whitelistedPaths as $ignore) {
            $ignore = trim(str_replace('\\', '/', $ignore), '/');
            if ($relative === $ignore || str_starts_with($relative, $ignore . '/') || fnmatch($ignore, $relative)) {
                $ignored = true;
                break;
            }
        }
        if ($ignored) {
            continue;
        }
        $nodes = $parser->parse((string) file_get_contents($file));
        $names = [[pathinfo($file, PATHINFO_FILENAME), 0]];
        foreach ($finder->find($nodes ?? [], static fn(PhpParser\Node $node): bool => true) as $node) {
            if (($node instanceof PhpParser\Node\Stmt\ClassLike || $node instanceof PhpParser\Node\Stmt\ClassMethod || $node instanceof PhpParser\Node\Stmt\Function_ || $node instanceof PhpParser\Node\Stmt\EnumCase || $node instanceof PhpParser\Node\PropertyItem || $node instanceof PhpParser\Node\Const_) && $node->name !== null) {
                $names[] = [(string) $node->name, $node->getStartLine()];
            }
            if ($node instanceof PhpParser\Node\Param && $node->var instanceof PhpParser\Node\Expr\Variable && is_string($node->var->name)) {
                $names[] = [$node->var->name, $node->getStartLine()];
            }
            $comment = $node->getDocComment();
            if ($comment !== null) {
                foreach (explode("\n", $comment->getText()) as $offset => $line) {
                    if (!str_contains($line, '@')) {
                        $names[] = [$line, $comment->getStartLine() + $offset];
                    }
                }
            }
        }
        $seen = [];
        foreach ($names as [$name, $line]) {
            foreach ($aspell->check(Peck\Support\SpellcheckFormatter::format($name)) as $misspelling) {
                $key = $line . ':' . $misspelling->word;
                if (!isset($seen[$key])) {
                    $issues[] = new Peck\ValueObjects\Issue($misspelling, $file, $line);
                    $seen[$key] = true;
                }
            }
        }
    }
    return $issues;
}
