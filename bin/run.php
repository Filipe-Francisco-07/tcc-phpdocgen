<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Parser\Normalizador;
use App\Parser\ServicoAst;
use PhpParser\NodeTraverser;
use Analyser\MarcadorDocumentacao;
use Util\InjetorPlaceholder;
use Util\RelatorErros;
use Generator\ConstrutorPrompt;
use Generator\ClienteGPT;
use Generator\AplicadorDocumentacao;

/**
 * run.php
 * Pipeline: lÃª input â†’ normaliza â†’ AST â†’ coleta itens â†’ (placeholders) â†’ prompts â†’ (LLM ou FAKE) â†’ aplica â†’ output
 * CompatÃ­vel com a extensÃ£o (arquivos esperados em output/).
 */

// -------------------------------
// Paths bÃ¡sicos
// -------------------------------
$inputPath = realpath(__DIR__ . '/../input/entrada.php') ?: (__DIR__ . '/../input/entrada.php');
$base      = pathinfo($inputPath, PATHINFO_FILENAME);
$outDir    = __DIR__ . '/../output';
@mkdir($outDir, 0777, true);

// Limpa outputs anteriores (apenas deste base)
foreach (glob($outDir . '/*_' . $base . '.*') as $f) {
    @unlink($f);
}
@unlink($outDir . '/errors.json'); // arquivo de erros tem nome fixo

echo "=> Input:  {$inputPath}\n";
echo "=> Output: " . realpath($outDir) . "\n";
echo "=> Base:   {$base}\n";

// -------------------------------
// Leitura do input
// -------------------------------
$raw = @file_get_contents($inputPath);
if ($raw === false) {
    (new RelatorErros())->escrever($outDir, [[
        'mensagem'     => "Arquivo nÃ£o encontrado: {$inputPath}",
        'linha_inicio' => 0,
        'linha_fim'    => 0
    ]]);
    fwrite(STDERR, "Erro: arquivo de entrada ausente: {$inputPath}\n");
    exit(1);
}

// -------------------------------
// NormalizaÃ§Ã£o (detectar fragmento e contabilizar linhas adicionadas)
// -------------------------------
[$normalized, $isFragment, $addedLines] = (new Normalizador())->normalizar($raw);

// -------------------------------
// AST + erros de sintaxe
// -------------------------------
[$ast, $parseErrors] = (new ServicoAst())->analisarCodigo($normalized);
if (!empty($parseErrors)) {
    (new RelatorErros())->escrever($outDir, $parseErrors);
    file_put_contents("{$outDir}/doc_map_{$base}.json", json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo "Erros -> {$outDir}/errors.json\n";
    exit(1);
}

// -------------------------------
// Mapeamento de itens documentÃ¡veis
// -------------------------------
$marker = new MarcadorDocumentacao();
$tr     = new NodeTraverser();
$tr->addVisitor($marker);

try {
    $tr->traverse($ast);
} catch (Throwable $e) {
    (new RelatorErros())->escrever($outDir, [[
        'mensagem'     => 'Falha no traverse: ' . $e->getMessage(),
        'linha_inicio' => 0,
        'linha_fim'    => 0
    ]]);
    echo "Erros -> {$outDir}/errors.json\n";
    exit(1);
}

// Ajusta linhas considerando as linhas extras da normalizaÃ§Ã£o
$items = array_map(function (array $it) use ($addedLines): array {
    $adj = static function ($v) use ($addedLines) {
        return is_int($v) ? max(1, $v - $addedLines) : $v;
    };
    $it['line']      = $adj($it['line']      ?? 1);
    $it['endLine']   = $adj($it['endLine']   ?? null);
    $it['doc_start'] = $adj($it['doc_start'] ?? null);
    $it['doc_end']   = $adj($it['doc_end']   ?? null);
    return $it;
}, $marker->aItens ?? []);

// Em fragmento, ignore class/interface/trait/enum
if ($isFragment) {
    $items = array_values(array_filter($items, static function ($it) {
        return in_array($it['type'] ?? '', ['function', 'method', 'property', 'constant'], true);
    }));
}

file_put_contents("{$outDir}/doc_map_{$base}.json", json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "Mapping -> {$outDir}/doc_map_{$base}.json\n";
if ($isFragment) {
    echo "Selecao/fragmento detectado.\n";
}

// -------------------------------
// Placeholders (apenas arquivo inteiro)
// -------------------------------
if (!$isFragment) {
    $map = array_map(static function ($it) {
        return [
            'id'        => $it['id'],
            'line'      => max(2, (int)($it['line'] ?? 1)), // evita linha 1
            'doc_start' => $it['doc_start'] ?? null,
            'doc_end'   => $it['doc_end'] ?? null,
        ];
    }, $items);

    // InjetorPlaceholder espera CONTEÃšDO (mais seguro)
    $srcWithPH = (new Util\InjetorPlaceholder())->injetar($inputPath, $map);
    file_put_contents("{$outDir}/placeholder_{$base}.php", $srcWithPH);
    echo "Placeholders -> {$outDir}/placeholder_{$base}.php\n";
}

// -------------------------------
// Prompts por item
// -------------------------------
$constr   = new ConstrutorPrompt();
$prompts  = [];
foreach ($items as $it) {
    $prompts[$it['id']] = $constr->construir($it, $raw);
}
file_put_contents("{$outDir}/prompts_{$base}.json", json_encode($prompts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

// -------------------------------
// GeraÃ§Ã£o de DocBlocks (LLM real ou modo FAKE)
// -------------------------------
$apiKey = getenv('OPENAI_API_KEY') ?: '';
$model  = getenv('OPENAI_MODEL') ?: 'gpt-4o-mini';
$baseUrl = rtrim(getenv('OPENAI_BASE') ?: 'https://api.openai.com/v1', '/');
$fake   = (getenv('DOCGEN_FAKE') ?: '') === '1';

$docs = [];

if ($fake) {
    // Modo FAKE para smoke local e CI offline
    foreach ($items as $it) {
        $params = $it['params'] ?? [];
        $ret    = $it['return'] ?? ($it['retorno'] ?? 'mixed');
        $lines  = [];
        $lines[] = '/**';
        $lines[] = ' * DocumentaÃ§Ã£o gerada (FAKE).';
        foreach ($params as $p) {
            $t = $p['type'] ?? 'mixed';
            $n = $p['name'] ?? '$param';
            $lines[] = " * @param {$t} {$n}";
        }
        if ($ret) {
            $lines[] = " * @return {$ret}";
        }
        $lines[] = ' */';
        $docs[$it['id']] = implode("\n", $lines);
    }
    file_put_contents("{$outDir}/generated_docs_{$base}.json", json_encode($docs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo "Gerados (FAKE) -> {$outDir}/generated_docs_{$base}.json\n";
} elseif ($apiKey !== '') {
    $cli = new ClienteGPT();
    foreach ($items as $it) {
        $doc = $cli->gerar($baseUrl, $apiKey, $model, $prompts[$it['id']]);
        if ($doc) {
            $docs[$it['id']] = $doc;
        }
    }
    $idsMapa   = array_column($items, 'id');
    $idsSemDoc = array_values(array_diff($idsMapa, array_keys($docs)));
    if ($idsSemDoc) {
        file_put_contents("{$outDir}/missing_docs_{$base}.log", implode("\n", $idsSemDoc));
    }
    file_put_contents("{$outDir}/generated_docs_{$base}.json", json_encode($docs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo "Gerados (API) -> {$outDir}/generated_docs_{$base}.json\n";
} else {
    echo "Sem OPENAI_API_KEY. Pulei geraÃ§Ã£o.\n";
}

// -------------------------------
// AplicaÃ§Ã£o (arquivo inteiro) ou Preview (seleÃ§Ã£o)
// -------------------------------
if (!$isFragment && file_exists("{$outDir}/placeholder_{$base}.php")) {
    $srcPH = file_get_contents("{$outDir}/placeholder_{$base}.php");

    // preserva primeira linha se for <?php
    $lines = explode("\n", str_replace("\r\n", "\n", $srcPH));
    $head  = $lines[0] ?? '';

    $final = (new AplicadorDocumentacao())->aplicar($srcPH, $docs);

    if ($head !== '' && str_starts_with($head, '<?php')) {
        $fLines     = explode("\n", str_replace("\r\n", "\n", $final));
        $fLines[0]  = $head;
        $final      = implode("\n", $fLines);
    }

    file_put_contents("{$outDir}/documentado_{$base}.php", $final);
    echo "Documentado -> {$outDir}/documentado_{$base}.php\n";
} else {
    if (!empty($docs)) {
        // Preview em seleÃ§Ã£o: concatena blocos para inserÃ§Ã£o pela extensÃ£o
        $preview = implode("\n\n", array_values($docs));
        file_put_contents("{$outDir}/preview_patch_{$base}.txt", $preview);
        echo "Preview -> {$outDir}/preview_patch_{$base}.txt\n";
    }
}
