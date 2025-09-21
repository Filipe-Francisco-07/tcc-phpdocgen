#!/usr/bin/env php
<?php

require __DIR__ . '/../vendor/autoload.php';

use PhpParser\ParserFactory;
use PhpParser\Node;

$srcDir = __DIR__ . '/../src';
$testsDir = __DIR__ . '/../tests';

$parser = (new ParserFactory())->create(ParserFactory::PREFER_PHP7);
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir));
foreach ($rii as $file) {
    if (!$file->isFile() || pathinfo($file->getPathname(), PATHINFO_EXTENSION) !== 'php') {
        continue;
    }
    $code = file_get_contents($file->getPathname());
    try {
        $ast = $parser->parse($code);
    } catch (Throwable $e) {
        continue;
    }

    $classes = [];
    $it = new RecursiveIteratorIterator(new RecursiveArrayIterator($ast));
    // coleta classes e mÃƒÂ©todos pÃƒÂºblicos
    $nodeFinder = new PhpParser\NodeFinder();
    foreach ($nodeFinder->findInstanceOf($ast, Node\Stmt\Class_::class) as $cls) {
        $clsName = $cls->name?->toString() ?? 'Anonymous';
        $methods = [];
        foreach ($cls->getMethods() as $m) {
            if ($m->isPublic() && !$m->isAbstract()) {
                $methods[] = $m->name->toString();
            }
        }
        if ($methods) {
            $classes[] = [$clsName, $methods, $file->getPathname()];
        }
    }

    foreach ($classes as [$clsName, $methods, $path]) {
        $testPath = $testsDir . '/' . $clsName . 'Test.php';
        if (!file_exists($testPath)) {
            @mkdir($testsDir, 0777, true);
            $tpl = "<?php\nuse PHPUnit\\Framework\\TestCase;\nfinal class {$clsName}Test extends TestCase {\n";
            foreach ($methods as $m) {
                $tpl .= "  public function test_{$m}(): void {\n"
                . "    \$this->markTestIncomplete('TODO: implementar {$clsName}::{$m}');\n"
                . "  }\n";
            }
            $tpl .= "}\n";
            file_put_contents($testPath, $tpl);
        }
    }
}
