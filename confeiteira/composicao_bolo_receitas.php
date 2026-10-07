<?php

require_once "../config/auth.php";
require_once "../config/banco.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*
|--------------------------------------------------------------------------
| AUTENTICAÇÃO
|--------------------------------------------------------------------------
*/

exigirConfeiteira();

$usuario_id = (int) $_SESSION['usuario_id'];

/*
|--------------------------------------------------------------------------
| TOKEN CSRF
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION['csrf_token'];

/*
|--------------------------------------------------------------------------
| BUSCAR CONFEITEIRA
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        id,
        nome_confeitaria
    FROM confeiteiras
    WHERE usuario_id = ?
    LIMIT 1
");

$stmt->execute([
    $usuario_id
]);

$confeiteira = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$confeiteira) {
    die("Confeiteira não encontrada.");
}

$confeiteira_id = (int) $confeiteira['id'];

/*
|--------------------------------------------------------------------------
| VALIDAR BOLO
|--------------------------------------------------------------------------
*/

$bolo_id = isset($_GET['id'])
    ? (int) $_GET['id']
    : 0;

if ($bolo_id <= 0) {
    die("Bolo inválido.");
}

/*
|--------------------------------------------------------------------------
| BUSCAR BOLO
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT *
    FROM bolos
    WHERE id = ?
      AND confeiteira_id = ?
    LIMIT 1
");

$stmt->execute([
    $bolo_id,
    $confeiteira_id
]);

$bolo = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$bolo) {
    die("Bolo não encontrado.");
}

$erro = "";
$sucesso = "";

/*
|--------------------------------------------------------------------------
| FUNÇÕES DE UNIDADE
|--------------------------------------------------------------------------
*/

/**
 * Normaliza nomes de unidades.
 */
function normalizarUnidade($unidade)
{
    $unidade = trim((string) $unidade);

    if ($unidade === '') {
        return '';
    }

    $unidade = mb_strtolower($unidade, 'UTF-8');

    $mapa = [
        'quilo'       => 'kg',
        'quilos'      => 'kg',
        'quilograma'  => 'kg',
        'quilogramas' => 'kg',
        'kg'          => 'kg',

        'grama'       => 'g',
        'gramas'      => 'g',
        'g'           => 'g',

        'miligrama'   => 'mg',
        'miligramas'  => 'mg',
        'mg'          => 'mg',

        'litro'       => 'l',
        'litros'      => 'l',
        'l'           => 'l',

        'mililitro'   => 'ml',
        'mililitros'  => 'ml',
        'ml'          => 'ml',

        'un'          => 'un',
        'und'         => 'un',
        'unid'        => 'un',
        'unidade'     => 'un',
        'unidades'    => 'un'
    ];

    return $mapa[$unidade] ?? $unidade;
}

/**
 * Retorna a categoria da unidade.
 */
function categoriaUnidade($unidade)
{
    $unidade = normalizarUnidade($unidade);

    if (in_array($unidade, ['mg', 'g', 'kg'], true)) {
        return 'massa';
    }

    if (in_array($unidade, ['ml', 'l'], true)) {
        return 'volume';
    }

    if ($unidade === 'un') {
        return 'quantidade';
    }

    return null;
}

/**
 * Retorna o fator para a unidade-base da categoria.
 */
function fatorUnidade($unidade)
{
    $unidade = normalizarUnidade($unidade);

    switch ($unidade) {

        case 'mg':
            return 0.001;

        case 'g':
            return 1;

        case 'kg':
            return 1000;

        case 'ml':
            return 1;

        case 'l':
            return 1000;

        case 'un':
            return 1;

        default:
            return null;
    }
}

/**
 * Verifica se duas unidades são compatíveis.
 */
function unidadesCompativeis($origem, $destino)
{
    $categoriaOrigem = categoriaUnidade($origem);
    $categoriaDestino = categoriaUnidade($destino);

    if ($categoriaOrigem === null || $categoriaDestino === null) {
        return false;
    }

    return $categoriaOrigem === $categoriaDestino;
}

/**
 * Converte quantidade entre unidades compatíveis.
 */
function converterQuantidade(
    $quantidade,
    $unidadeOrigem,
    $unidadeDestino
) {
    $quantidade = (float) $quantidade;

    $origem = normalizarUnidade($unidadeOrigem);
    $destino = normalizarUnidade($unidadeDestino);

    if ($origem === '' || $destino === '') {
        return null;
    }

    if (!unidadesCompativeis($origem, $destino)) {
        return null;
    }

    $fatorOrigem = fatorUnidade($origem);
    $fatorDestino = fatorUnidade($destino);

    if ($fatorOrigem === null || $fatorDestino === null) {
        return null;
    }

    $quantidadeBase = $quantidade * $fatorOrigem;

    return $quantidadeBase / $fatorDestino;
}

/**
 * Formata unidade para exibição.
 */
function exibirUnidade($unidade)
{
    $normalizada = normalizarUnidade($unidade);

    switch ($normalizada) {

        case 'kg':
            return 'kg';

        case 'g':
            return 'g';

        case 'mg':
            return 'mg';

        case 'l':
            return 'L';

        case 'ml':
            return 'ml';

        case 'un':
            return 'un';

        default:
            return $unidade;
    }
}

/*
|--------------------------------------------------------------------------
| PROCESSAR POST
|--------------------------------------------------------------------------
|
| Aqui ficam:
|
| 1. Adicionar receita
| 2. Remover receita
| 3. Subir receita
| 4. Descer receita
|
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /*
    |--------------------------------------------------------------------------
    | VALIDAR CSRF
    |--------------------------------------------------------------------------
    */

    $token_recebido = $_POST['csrf_token'] ?? '';

    if (
        !hash_equals(
            $_SESSION['csrf_token'],
            $token_recebido
        )
    ) {

        $erro = "Sessão de segurança inválida. Atualize a página e tente novamente.";

    } else {

        $acao = $_POST['acao'] ?? '';

        /*
        |--------------------------------------------------------------------------
        | ADICIONAR RECEITA
        |--------------------------------------------------------------------------
        */

        if ($acao === 'adicionar_receita') {

            $receita_id = (int) ($_POST['receita_id'] ?? 0);

            $quantidade = str_replace(
                ',',
                '.',
                $_POST['quantidade'] ?? ''
            );

            $unidade_utilizacao = trim(
                $_POST['unidade_utilizacao'] ?? ''
            );

            if (
                $receita_id <= 0 ||
                !is_numeric($quantidade) ||
                (float) $quantidade <= 0
            ) {

                $erro = "Informe uma receita e uma quantidade válida.";

            } else {

                $quantidade = (float) $quantidade;

                /*
                |--------------------------------------------------------------------------
                | BUSCAR RECEITA
                |--------------------------------------------------------------------------
                |
                | A receita precisa pertencer à confeiteira logada.
                |
                */

                $stmt = $pdo->prepare("
                    SELECT
                        id,
                        nome,
                        tipo,
                        rendimento,
                        unidade_rendimento
                    FROM receitas
                    WHERE id = ?
                      AND confeiteira_id = ?
                      AND ativo = 1
                    LIMIT 1
                ");

                $stmt->execute([
                    $receita_id,
                    $confeiteira_id
                ]);

                $receita = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$receita) {

                    $erro = "Receita inválida.";

                } else {

                    $rendimento =
                        (float) $receita['rendimento'];

                    $unidade_rendimento =
                        trim(
                            $receita['unidade_rendimento'] ?? ''
                        );

                    /*
                    |--------------------------------------------------------------------------
                    | UNIDADE DE UTILIZAÇÃO
                    |--------------------------------------------------------------------------
                    */

                    if ($unidade_utilizacao === '') {
                        $unidade_utilizacao =
                            $unidade_rendimento;
                    }

                    $quantidade_convertida =
                        $quantidade;

                    /*
                    |--------------------------------------------------------------------------
                    | VALIDAR RENDIMENTO
                    |--------------------------------------------------------------------------
                    */

                    if ($rendimento > 0) {

                        if ($unidade_rendimento === '') {

                            $erro =
                                "A receita possui rendimento, mas não possui unidade de rendimento.";

                        } elseif ($unidade_utilizacao === '') {

                            $erro =
                                "Informe a unidade utilizada.";

                        } elseif (
                            !unidadesCompativeis(
                                $unidade_utilizacao,
                                $unidade_rendimento
                            )
                        ) {

                            $erro =
                                "A unidade " .
                                exibirUnidade(
                                    $unidade_utilizacao
                                ) .
                                " não é compatível com " .
                                exibirUnidade(
                                    $unidade_rendimento
                                ) .
                                ".";

                        } else {

                            $quantidade_convertida =
                                converterQuantidade(
                                    $quantidade,
                                    $unidade_utilizacao,
                                    $unidade_rendimento
                                );

                            if (
                                $quantidade_convertida === null
                            ) {

                                $erro =
                                    "Não foi possível converter as unidades.";

                            } elseif (
                                $quantidade_convertida >
                                $rendimento + 0.0000001
                            ) {

                                $erro =
                                    "A quantidade utilizada corresponde a " .
                                    number_format(
                                        $quantidade_convertida,
                                        3,
                                        ',',
                                        '.'
                                    ) .
                                    " " .
                                    exibirUnidade(
                                        $unidade_rendimento
                                    ) .
                                    ", mas o rendimento máximo da receita é " .
                                    number_format(
                                        $rendimento,
                                        3,
                                        ',',
                                        '.'
                                    ) .
                                    " " .
                                    exibirUnidade(
                                        $unidade_rendimento
                                    ) .
                                    ".";
                            }
                        }
                    }

                    /*
                    |--------------------------------------------------------------------------
                    | VERIFICAR SE RECEITA JÁ EXISTE NO BOLO
                    |--------------------------------------------------------------------------
                    */

                    if ($erro === '') {

                        $stmt = $pdo->prepare("
                            SELECT
                                br.id,
                                br.quantidade,
                                br.unidade_utilizacao
                            FROM bolo_receitas br

                            INNER JOIN bolos b
                                ON b.id = br.bolo_id

                            INNER JOIN receitas r
                                ON r.id = br.receita_id

                            WHERE br.bolo_id = ?
                              AND br.receita_id = ?
                              AND b.confeiteira_id = ?
                              AND r.confeiteira_id = ?
                            LIMIT 1
                        ");

                        $stmt->execute([
                            $bolo_id,
                            $receita_id,
                            $confeiteira_id,
                            $confeiteira_id
                        ]);

                        $existente =
                            $stmt->fetch(PDO::FETCH_ASSOC);

                        /*
                        |--------------------------------------------------------------------------
                        | RECEITA JÁ EXISTENTE
                        |--------------------------------------------------------------------------
                        */

                        if ($existente) {

                            $unidadeExistente =
                                trim(
                                    $existente['unidade_utilizacao'] ?? ''
                                );

                            if ($unidadeExistente === '') {
                                $unidadeExistente =
                                    $unidade_rendimento;
                            }

                            /*
                            |--------------------------------------------------------------------------
                            | VERIFICAR COMPATIBILIDADE
                            |--------------------------------------------------------------------------
                            */

                            if (
                                $rendimento > 0 &&
                                !unidadesCompativeis(
                                    $unidadeExistente,
                                    $unidade_utilizacao
                                )
                            ) {

                                $unidadeExistente =
                                    $unidade_rendimento;
                            }

                            $quantidadeExistente =
                                (float) $existente['quantidade'];

                            /*
                            |--------------------------------------------------------------------------
                            | CONVERTER QUANTIDADE EXISTENTE
                            |--------------------------------------------------------------------------
                            */

                            if (
                                $rendimento > 0 &&
                                $unidadeExistente !== ''
                            ) {

                                $quantidadeExistenteConvertida =
                                    converterQuantidade(
                                        $quantidadeExistente,
                                        $existente['unidade_utilizacao'],
                                        $unidadeExistente
                                    );

                                if (
                                    $quantidadeExistenteConvertida === null
                                ) {

                                    $quantidadeExistenteConvertida =
                                        converterQuantidade(
                                            $quantidadeExistente,
                                            $unidade_rendimento,
                                            $unidadeExistente
                                        );
                                }

                            } else {

                                $quantidadeExistenteConvertida =
                                    $quantidadeExistente;
                            }

                            if (
                                $quantidadeExistenteConvertida === null
                            ) {

                                $erro =
                                    "Não foi possível interpretar a quantidade já cadastrada.";

                            }

                            /*
                            |--------------------------------------------------------------------------
                            | CONVERTER NOVA QUANTIDADE
                            |--------------------------------------------------------------------------
                            */

                            if ($erro === '') {

                                if (
                                    $rendimento > 0 &&
                                    $unidadeExistente !== ''
                                ) {

                                    $novaQuantidadeConvertida =
                                        converterQuantidade(
                                            $quantidade,
                                            $unidade_utilizacao,
                                            $unidadeExistente
                                        );

                                    if (
                                        $novaQuantidadeConvertida === null
                                    ) {

                                        $erro =
                                            "Não foi possível somar as quantidades porque as unidades são incompatíveis.";
                                    }

                                } else {

                                    $novaQuantidadeConvertida =
                                        $quantidade;
                                }
                            }

                            /*
                            |--------------------------------------------------------------------------
                            | SOMAR
                            |--------------------------------------------------------------------------
                            */

                            if ($erro === '') {

                                $nova_quantidade =
                                    $quantidadeExistenteConvertida +
                                    $novaQuantidadeConvertida;

                                /*
                                |--------------------------------------------------------------------------
                                | VALIDAR LIMITE TOTAL
                                |--------------------------------------------------------------------------
                                */

                                if ($rendimento > 0) {

                                    $totalNaUnidadeRendimento =
                                        converterQuantidade(
                                            $nova_quantidade,
                                            $unidadeExistente,
                                            $unidade_rendimento
                                        );

                                    if (
                                        $totalNaUnidadeRendimento === null
                                    ) {

                                        $erro =
                                            "Não foi possível validar a quantidade total da receita.";

                                    } elseif (
                                        $totalNaUnidadeRendimento >
                                        $rendimento + 0.0000001
                                    ) {

                                        $erro =
                                            "Esta receita já possui " .
                                            number_format(
                                                $quantidadeExistenteConvertida,
                                                3,
                                                ',',
                                                '.'
                                            ) .
                                            " " .
                                            exibirUnidade(
                                                $unidadeExistente
                                            ) .
                                            " adicionados. A quantidade total não pode ultrapassar " .
                                            number_format(
                                                $rendimento,
                                                3,
                                                ',',
                                                '.'
                                            ) .
                                            " " .
                                            exibirUnidade(
                                                $unidade_rendimento
                                            ) .
                                            ".";
                                    }
                                }

                                /*
                                |--------------------------------------------------------------------------
                                | ATUALIZAR
                                |--------------------------------------------------------------------------
                                */

                                if ($erro === '') {

                                    $stmt = $pdo->prepare("
                                        UPDATE bolo_receitas br

                                        INNER JOIN bolos b
                                            ON b.id = br.bolo_id

                                        INNER JOIN receitas r
                                            ON r.id = br.receita_id

                                        SET
                                            br.quantidade = ?,
                                            br.unidade_utilizacao = ?

                                        WHERE br.id = ?
                                          AND br.bolo_id = ?
                                          AND b.confeiteira_id = ?
                                          AND r.confeiteira_id = ?
                                    ");

                                    $stmt->execute([
                                        $nova_quantidade,
                                        $unidadeExistente,
                                        $existente['id'],
                                        $bolo_id,
                                        $confeiteira_id,
                                        $confeiteira_id
                                    ]);

                                    header(
                                        "Location: composicao_bolo_receitas.php?id=" .
                                        $bolo_id
                                    );

                                    exit;
                                }
                            }

                        } else {

                            /*
                            |--------------------------------------------------------------------------
                            | BUSCAR PRÓXIMA ORDEM
                            |--------------------------------------------------------------------------
                            */

                            $stmt = $pdo->prepare("
                                SELECT
                                    COALESCE(MAX(ordem), -1) + 1
                                FROM bolo_receitas br

                                INNER JOIN bolos b
                                    ON b.id = br.bolo_id

                                WHERE br.bolo_id = ?
                                  AND b.confeiteira_id = ?
                            ");

                            $stmt->execute([
                                $bolo_id,
                                $confeiteira_id
                            ]);

                            $ordem =
                                (int) $stmt->fetchColumn();

                            /*
                            |--------------------------------------------------------------------------
                            | INSERIR
                            |--------------------------------------------------------------------------
                            */

                            $stmt = $pdo->prepare("
                                INSERT INTO bolo_receitas
                                (
                                    bolo_id,
                                    receita_id,
                                    quantidade,
                                    unidade_utilizacao,
                                    ordem
                                )
                                SELECT
                                    ?,
                                    r.id,
                                    ?,
                                    ?,
                                    ?
                                FROM receitas r
                                INNER JOIN bolos b
                                    ON b.id = ?
                                WHERE r.id = ?
                                  AND r.confeiteira_id = ?
                                  AND b.confeiteira_id = ?
                            ");

                            $stmt->execute([
                                $bolo_id,
                                $quantidade,
                                $unidade_utilizacao,
                                $ordem,
                                $bolo_id,
                                $receita_id,
                                $confeiteira_id,
                                $confeiteira_id
                            ]);

                            if ($stmt->rowCount() > 0) {

                                header(
                                    "Location: composicao_bolo_receitas.php?id=" .
                                    $bolo_id
                                );

                                exit;

                            } else {

                                $erro =
                                    "Não foi possível adicionar a receita ao bolo.";
                            }
                        }
                    }
                }
            }
        }

        /*
        |--------------------------------------------------------------------------
        | REMOVER RECEITA
        |--------------------------------------------------------------------------
        */

        elseif ($acao === 'remover_receita') {

            $bolo_receita_id =
                (int) ($_POST['bolo_receita_id'] ?? 0);

            if ($bolo_receita_id <= 0) {

                $erro =
                    "Receita inválida.";

            } else {

                $stmt = $pdo->prepare("
                    DELETE br
                    FROM bolo_receitas br

                    INNER JOIN bolos b
                        ON b.id = br.bolo_id

                    INNER JOIN receitas r
                        ON r.id = br.receita_id

                    WHERE br.id = ?
                      AND br.bolo_id = ?
                      AND b.confeiteira_id = ?
                      AND r.confeiteira_id = ?
                ");

                $stmt->execute([
                    $bolo_receita_id,
                    $bolo_id,
                    $confeiteira_id,
                    $confeiteira_id
                ]);

                header(
                    "Location: composicao_bolo_receitas.php?id=" .
                    $bolo_id
                );

                exit;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | SUBIR RECEITA
        |--------------------------------------------------------------------------
        */

        elseif ($acao === 'subir_receita') {

            $id_atual =
                (int) ($_POST['bolo_receita_id'] ?? 0);

            if ($id_atual > 0) {

                $stmt = $pdo->prepare("
                    SELECT
                        br.id,
                        br.ordem,
                        r.tipo

                    FROM bolo_receitas br

                    INNER JOIN receitas r
                        ON r.id = br.receita_id

                    INNER JOIN bolos b
                        ON b.id = br.bolo_id

                    WHERE br.id = ?
                      AND br.bolo_id = ?
                      AND b.confeiteira_id = ?
                      AND r.confeiteira_id = ?

                    LIMIT 1
                ");

                $stmt->execute([
                    $id_atual,
                    $bolo_id,
                    $confeiteira_id,
                    $confeiteira_id
                ]);

                $atual =
                    $stmt->fetch(PDO::FETCH_ASSOC);

                if ($atual) {

                    $stmt = $pdo->prepare("
                        SELECT
                            br.id,
                            br.ordem

                        FROM bolo_receitas br

                        INNER JOIN receitas r
                            ON r.id = br.receita_id

                        INNER JOIN bolos b
                            ON b.id = br.bolo_id

                        WHERE br.bolo_id = ?
                          AND r.tipo = ?
                          AND br.ordem < ?
                          AND b.confeiteira_id = ?
                          AND r.confeiteira_id = ?

                        ORDER BY br.ordem DESC

                        LIMIT 1
                    ");

                    $stmt->execute([
                        $bolo_id,
                        $atual['tipo'],
                        $atual['ordem'],
                        $confeiteira_id,
                        $confeiteira_id
                    ]);

                    $anterior =
                        $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($anterior) {

                        $pdo->beginTransaction();

                        try {

                            $stmt = $pdo->prepare("
                                UPDATE bolo_receitas br

                                INNER JOIN bolos b
                                    ON b.id = br.bolo_id

                                SET br.ordem = ?

                                WHERE br.id = ?
                                  AND br.bolo_id = ?
                                  AND b.confeiteira_id = ?
                            ");

                            $stmt->execute([
                                $anterior['ordem'],
                                $atual['id'],
                                $bolo_id,
                                $confeiteira_id
                            ]);

                            $stmt->execute([
                                $atual['ordem'],
                                $anterior['id'],
                                $bolo_id,
                                $confeiteira_id
                            ]);

                            $pdo->commit();

                        } catch (Exception $e) {

                            if ($pdo->inTransaction()) {
                                $pdo->rollBack();
                            }

                            $erro =
                                "Não foi possível alterar a ordem.";
                        }
                    }
                }
            }

            if ($erro === '') {

                header(
                    "Location: composicao_bolo_receitas.php?id=" .
                    $bolo_id
                );

                exit;
            }
        }

        /*
        |--------------------------------------------------------------------------
        | DESCER RECEITA
        |--------------------------------------------------------------------------
        */

        elseif ($acao === 'descer_receita') {

            $id_atual =
                (int) ($_POST['bolo_receita_id'] ?? 0);

            if ($id_atual > 0) {

                $stmt = $pdo->prepare("
                    SELECT
                        br.id,
                        br.ordem,
                        r.tipo

                    FROM bolo_receitas br

                    INNER JOIN receitas r
                        ON r.id = br.receita_id

                    INNER JOIN bolos b
                        ON b.id = br.bolo_id

                    WHERE br.id = ?
                      AND br.bolo_id = ?
                      AND b.confeiteira_id = ?
                      AND r.confeiteira_id = ?

                    LIMIT 1
                ");

                $stmt->execute([
                    $id_atual,
                    $bolo_id,
                    $confeiteira_id,
                    $confeiteira_id
                ]);

                $atual =
                    $stmt->fetch(PDO::FETCH_ASSOC);

                if ($atual) {

                    $stmt = $pdo->prepare("
                        SELECT
                            br.id,
                            br.ordem

                        FROM bolo_receitas br

                        INNER JOIN receitas r
                            ON r.id = br.receita_id

                        INNER JOIN bolos b
                            ON b.id = br.bolo_id

                        WHERE br.bolo_id = ?
                          AND r.tipo = ?
                          AND br.ordem > ?
                          AND b.confeiteira_id = ?
                          AND r.confeiteira_id = ?

                        ORDER BY br.ordem ASC

                        LIMIT 1
                    ");

                    $stmt->execute([
                        $bolo_id,
                        $atual['tipo'],
                        $atual['ordem'],
                        $confeiteira_id,
                        $confeiteira_id
                    ]);

                    $posterior =
                        $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($posterior) {

                        $pdo->beginTransaction();

                        try {

                            $stmt = $pdo->prepare("
                                UPDATE bolo_receitas br

                                INNER JOIN bolos b
                                    ON b.id = br.bolo_id

                                SET br.ordem = ?

                                WHERE br.id = ?
                                  AND br.bolo_id = ?
                                  AND b.confeiteira_id = ?
                            ");

                            $stmt->execute([
                                $posterior['ordem'],
                                $atual['id'],
                                $bolo_id,
                                $confeiteira_id
                            ]);

                            $stmt->execute([
                                $atual['ordem'],
                                $posterior['id'],
                                $bolo_id,
                                $confeiteira_id
                            ]);

                            $pdo->commit();

                        } catch (Exception $e) {

                            if ($pdo->inTransaction()) {
                                $pdo->rollBack();
                            }

                            $erro =
                                "Não foi possível alterar a ordem.";
                        }
                    }
                }
            }

            if ($erro === '') {

                header(
                    "Location: composicao_bolo_receitas.php?id=" .
                    $bolo_id
                );

                exit;
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| BUSCAR RECEITAS DISPONÍVEIS
|--------------------------------------------------------------------------
|
| IMPORTANTE:
| O custo considera somente insumos pertencentes à mesma confeiteira
| da receita.
|
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        r.id,
        r.nome,
        r.tipo,
        r.rendimento,
        r.unidade_rendimento,

        COALESCE(
            SUM(
                CASE
                    WHEN i.confeiteira_id = r.confeiteira_id
                    THEN ri.quantidade * i.custo_unitario
                    ELSE 0
                END
            ),
            0
        ) AS custo_receita

    FROM receitas r

    LEFT JOIN receita_insumos ri
        ON ri.receita_id = r.id

    LEFT JOIN insumos i
        ON i.id = ri.insumo_id

    WHERE r.confeiteira_id = ?
      AND r.ativo = 1

    GROUP BY
        r.id,
        r.nome,
        r.tipo,
        r.rendimento,
        r.unidade_rendimento

    ORDER BY
        FIELD(
            r.tipo,
            'massa',
            'recheio',
            'cobertura',
            'decoracao',
            'outros'
        ),
        r.nome ASC
");

$stmt->execute([
    $confeiteira_id
]);

$receitas =
    $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| BUSCAR RECEITAS JÁ UTILIZADAS NO BOLO
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        br.id,
        br.receita_id,
        br.quantidade,
        br.unidade_utilizacao,
        br.ordem,

        r.nome,
        r.tipo,
        r.rendimento,
        r.unidade_rendimento,

        COALESCE(
            SUM(
                CASE
                    WHEN i.confeiteira_id = r.confeiteira_id
                    THEN ri.quantidade * i.custo_unitario
                    ELSE 0
                END
            ),
            0
        ) AS custo_receita

    FROM bolo_receitas br

    INNER JOIN receitas r
        ON r.id = br.receita_id
       AND r.confeiteira_id = ?

    LEFT JOIN receita_insumos ri
        ON ri.receita_id = r.id

    LEFT JOIN insumos i
        ON i.id = ri.insumo_id

    INNER JOIN bolos b
        ON b.id = br.bolo_id
       AND b.confeiteira_id = ?

    WHERE br.bolo_id = ?

    GROUP BY
        br.id,
        br.receita_id,
        br.quantidade,
        br.unidade_utilizacao,
        br.ordem,
        r.nome,
        r.tipo,
        r.rendimento,
        r.unidade_rendimento

    ORDER BY
        FIELD(
            r.tipo,
            'massa',
            'recheio',
            'cobertura',
            'decoracao',
            'outros'
        ),
        br.ordem ASC,
        r.nome ASC
");

$stmt->execute([
    $confeiteira_id,
    $confeiteira_id,
    $bolo_id
]);

$composicao =
    $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| CUSTO DAS RECEITAS
|--------------------------------------------------------------------------
*/

$custo_receitas = 0;

foreach ($composicao as &$item) {

    $custo_receita =
        (float) $item['custo_receita'];

    $quantidade =
        (float) $item['quantidade'];

    $rendimento =
        (float) $item['rendimento'];

    $unidade_utilizacao =
        trim(
            $item['unidade_utilizacao'] ?? ''
        );

    $unidade_rendimento =
        trim(
            $item['unidade_rendimento'] ?? ''
        );

    $quantidade_calculo =
        $quantidade;

    /*
    |--------------------------------------------------------------------------
    | CONVERTER PARA UNIDADE DO RENDIMENTO
    |--------------------------------------------------------------------------
    */

    if (
        $rendimento > 0 &&
        $unidade_utilizacao !== '' &&
        $unidade_rendimento !== ''
    ) {

        $convertida =
            converterQuantidade(
                $quantidade,
                $unidade_utilizacao,
                $unidade_rendimento
            );

        if ($convertida !== null) {
            $quantidade_calculo =
                $convertida;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | CUSTO PROPORCIONAL
    |--------------------------------------------------------------------------
    */

    if ($rendimento > 0) {

        $custo_item =
            $custo_receita *
            (
                $quantidade_calculo /
                $rendimento
            );

        $percentual =
            (
                $quantidade_calculo /
                $rendimento
            ) * 100;

    } else {

        $custo_item =
            $custo_receita *
            $quantidade;

        $percentual = 0;
    }

    $item['quantidade_calculo'] =
        $quantidade_calculo;

    $item['custo_item'] =
        $custo_item;

    $item['percentual'] =
        $percentual;

    $custo_receitas +=
        $custo_item;
}

unset($item);

/*
|--------------------------------------------------------------------------
| CUSTO DOS INSUMOS DIRETOS
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        COALESCE(
            SUM(
                bi.quantidade *
                i.custo_unitario
            ),
            0
        ) AS custo_direto

    FROM bolo_insumos bi

    INNER JOIN insumos i
        ON i.id = bi.insumo_id
       AND i.confeiteira_id = ?

    INNER JOIN bolos b
        ON b.id = bi.bolo_id
       AND b.confeiteira_id = ?

    WHERE bi.bolo_id = ?
");

$stmt->execute([
    $confeiteira_id,
    $confeiteira_id,
    $bolo_id
]);

$custo_direto =
    (float) $stmt->fetchColumn();

/*
|--------------------------------------------------------------------------
| CUSTO TOTAL
|--------------------------------------------------------------------------
*/

$custo_total =
    $custo_receitas +
    $custo_direto;

/*
|--------------------------------------------------------------------------
| RESULTADO
|--------------------------------------------------------------------------
*/

$preco_venda =
    (float) ($bolo['preco_venda'] ?? 0);

$resultado =
    $preco_venda -
    $custo_total;

$margem =
    $preco_venda > 0
        ? ($resultado / $preco_venda) * 100
        : 0;

/*
|--------------------------------------------------------------------------
| FUNÇÃO PARA NOMEAR O TIPO
|--------------------------------------------------------------------------
*/

function nomeTipoReceita($tipo)
{
    switch ($tipo) {

        case 'massa':
            return 'Massa';

        case 'recheio':
            return 'Recheios';

        case 'cobertura':
            return 'Cobertura';

        case 'decoracao':
            return 'Decoração';

        default:
            return 'Outros';
    }
}

/*
|--------------------------------------------------------------------------
| AGRUPAR RECEITAS
|--------------------------------------------------------------------------
*/

$grupos = [
    'massa' => [],
    'recheio' => [],
    'cobertura' => [],
    'decoracao' => [],
    'outros' => []
];

foreach ($composicao as $item) {

    $tipo = $item['tipo'];

    if (!isset($grupos[$tipo])) {
        $tipo = 'outros';
    }

    $grupos[$tipo][] = $item;
}

?>

<!DOCTYPE html>

<html lang="pt-BR">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Receitas -
        <?= htmlspecialchars($bolo['nome']) ?>
    </title>

    <link
        rel="stylesheet"
        href="../assets/style.css"
    >

    <style>

        .container {
            max-width: 1200px;
            margin: 30px auto;
            padding: 20px;
        }

        .card {
            background: #fff;
            padding: 20px;
            margin-bottom: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }

        .menu {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin: 20px 0;
        }

        .menu a {
            background: #555;
            color: #fff;
            text-decoration: none;
            padding: 10px 15px;
            border-radius: 6px;
        }

        label {
            display: block;
            font-weight: bold;
            margin-bottom: 7px;
        }

        select,
        input {
            width: 100%;
            padding: 11px;
            box-sizing: border-box;
            border: 1px solid #ccc;
            border-radius: 6px;
        }

        button {
            padding: 11px 18px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            background: #198754;
            color: #fff;
            font-weight: bold;
        }

        button:disabled {
            background: #aaa;
            cursor: not-allowed;
        }

        .grid-form {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr;
            gap: 15px;
            align-items: end;
        }

        .informacao-receita {
            background: #f5f5f5;
            border-radius: 8px;
            padding: 15px;
            margin-top: 15px;
            display: none;
        }

        .informacao-receita > div {
            margin-bottom: 7px;
        }

        .informacao-receita > div:last-child {
            margin-bottom: 0;
        }

        .informacao-receita strong {
            display: inline-block;
            margin-right: 5px;
        }

        .conversao-info {
            background: #e8f4ff;
            color: #174a70;
            border-left: 4px solid #0d6efd;
            padding: 12px;
            border-radius: 6px;
            margin-top: 12px;
            display: none;
        }

        .erro {
            background: #f8d7da;
            color: #842029;
            padding: 14px;
            border-radius: 7px;
            margin-bottom: 20px;
        }

        .grupo {
            margin-top: 25px;
        }

        .grupo h3 {
            background: #eee;
            padding: 12px;
            border-radius: 7px;
        }

        .tabela {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 11px;
            border-bottom: 1px solid #ddd;
            text-align: left;
            white-space: nowrap;
        }

        th {
            background: #f5f5f5;
        }

        .badge {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 5px;
            background: #eee;
        }

        .custo-destaque {
            font-weight: bold;
        }

        .custo-bolo {
            color: #198754;
            font-weight: bold;
        }

        .acoes {
            display: flex;
            gap: 5px;
            align-items: center;
        }

        .acoes a,
        .acoes button {
            text-decoration: none;
            padding: 5px 8px;
            border-radius: 5px;
            background: #eee;
            color: #222;
            border: none;
            font-weight: normal;
            cursor: pointer;
        }

        .acoes button.remover {
            color: #dc3545;
        }

        .acoes form {
            display: inline;
            margin: 0;
        }

        .resumo {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            gap: 15px;
        }

        .resumo-box {
            background: #f8f9fa;
            padding: 18px;
            border-radius: 8px;
        }

        .resumo-box small {
            display: block;
            color: #666;
            margin-bottom: 7px;
        }

        .resumo-box strong {
            font-size: 21px;
        }

        .positivo {
            color: #198754;
        }

        .negativo {
            color: #dc3545;
        }

        .vazio {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 7px;
            color: #666;
        }

        .observacao {
            margin-top: 10px;
            color: #666;
            font-size: 14px;
        }

        @media (max-width: 800px) {

            .grid-form {
                grid-template-columns: 1fr;
            }

        }

    </style>

</head>

<body>

<div class="container">

    <!-- =========================================================
         CABEÇALHO
    ========================================================== -->

    <h1>
        🎂 <?= htmlspecialchars($bolo['nome']) ?>
    </h1>

    <p>
        <strong>
            Composição por Receitas
        </strong>
    </p>

    <div class="menu">

        <a href="bolos.php">
            ← Voltar para Bolos
        </a>

        <a href="composicao_bolo.php?id=<?= $bolo_id ?>">
            📦 Insumos Diretos
        </a>

        <a href="receitas.php">
            📖 Gerenciar Receitas
        </a>

    </div>


    <!-- =========================================================
         ERRO
    ========================================================== -->

    <?php if ($erro): ?>

        <div class="erro">

            <?= htmlspecialchars($erro) ?>

        </div>

    <?php endif; ?>


    <!-- =========================================================
         ADICIONAR RECEITA
    ========================================================== -->

    <div class="card">

        <h2>
            ➕ Adicionar Receita ao Bolo
        </h2>

        <?php if (!$receitas): ?>

            <div class="vazio">

                Nenhuma receita ativa foi cadastrada.

                <br><br>

                <a href="cadastrar_receita.php">
                    ➕ Cadastrar primeira receita
                </a>

            </div>

        <?php else: ?>

            <form method="POST">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= htmlspecialchars($csrf_token) ?>"
                >

                <input
                    type="hidden"
                    name="acao"
                    value="adicionar_receita"
                >

                <div class="grid-form">

                    <div>

                        <label>
                            Receita
                        </label>

                        <select
                            name="receita_id"
                            id="receita_id"
                            required
                            onchange="atualizarReceita()"
                        >

                            <option value="">
                                Selecione uma receita
                            </option>

                            <?php foreach ($receitas as $receita): ?>

                                <option
                                    value="<?= (int) $receita['id'] ?>"
                                    data-tipo="<?= htmlspecialchars($receita['tipo']) ?>"
                                    data-rendimento="<?= htmlspecialchars($receita['rendimento']) ?>"
                                    data-unidade="<?= htmlspecialchars($receita['unidade_rendimento'] ?? '') ?>"
                                    data-custo="<?= htmlspecialchars($receita['custo_receita']) ?>"
                                >

                                    <?= htmlspecialchars(
                                        $receita['nome']
                                    ) ?>

                                    -
                                    <?= htmlspecialchars(
                                        ucfirst($receita['tipo'])
                                    ) ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


                    <div>

                        <label>
                            Quantidade utilizada
                        </label>

                        <input
                            type="number"
                            name="quantidade"
                            id="quantidade"
                            step="0.001"
                            min="0.001"
                            required
                            disabled
                            oninput="calcularPrevia()"
                        >

                    </div>


                    <div>

                        <label>
                            Unidade
                        </label>

                        <select
                            name="unidade_utilizacao"
                            id="unidade_utilizacao"
                            disabled
                            onchange="calcularPrevia()"
                        >

                            <option value="">
                                Selecione a unidade
                            </option>

                        </select>

                    </div>

                </div>


                <div
                    class="informacao-receita"
                    id="informacao_receita"
                >

                    <div>

                        <strong>
                            Tipo:
                        </strong>

                        <span id="info_tipo">
                            -
                        </span>

                    </div>

                    <div>

                        <strong>
                            Rendimento da receita:
                        </strong>

                        <span id="info_rendimento">
                            -
                        </span>

                    </div>

                    <div>

                        <strong>
                            Custo da receita inteira:
                        </strong>

                        <span id="info_custo">
                            -
                        </span>

                    </div>

                    <div>

                        <strong>
                            Quantidade convertida:
                        </strong>

                        <span id="info_quantidade_convertida">
                            -
                        </span>

                    </div>

                    <div>

                        <strong>
                            Custo estimado no bolo:
                        </strong>

                        <span id="info_custo_bolo">
                            -
                        </span>

                    </div>

                    <div>

                        <strong>
                            Percentual utilizado:
                        </strong>

                        <span id="info_percentual">
                            -
                        </span>

                    </div>

                </div>


                <div
                    class="conversao-info"
                    id="conversao_info"
                >
                    A quantidade será convertida automaticamente para a
                    unidade do rendimento da receita antes do cálculo.
                </div>


                <p class="observacao">
                    Exemplo: se a receita rende <strong>1 kg</strong>,
                    você pode informar <strong>500 g</strong>.
                    O sistema converterá automaticamente para
                    <strong>0,500 kg</strong> e calculará 50% da receita.
                </p>


                <br>

                <button
                    type="submit"
                    id="btn_adicionar"
                    disabled
                >
                    ➕ Adicionar Receita
                </button>

            </form>

        <?php endif; ?>

    </div>


    <!-- =========================================================
         RECEITAS UTILIZADAS
    ========================================================== -->

    <div class="card">

        <h2>
            🧁 Receitas utilizadas
        </h2>

        <?php if (!$composicao): ?>

            <div class="vazio">

                Nenhuma receita foi adicionada a este bolo.

            </div>

        <?php else: ?>

            <?php foreach ($grupos as $tipo => $lista): ?>

                <?php if (!empty($lista)): ?>

                    <div class="grupo">

                        <h3>

                            <?php

                            switch ($tipo) {

                                case 'massa':
                                    echo "🟤 Massa";
                                    break;

                                case 'recheio':
                                    echo "🟡 Recheios";
                                    break;

                                case 'cobertura':
                                    echo "⚪ Cobertura";
                                    break;

                                case 'decoracao':
                                    echo "🎨 Decoração";
                                    break;

                                default:
                                    echo "📦 Outros";
                                    break;
                            }

                            ?>

                        </h3>


                        <div class="tabela">

                            <table>

                                <thead>

                                <tr>

                                    <th>
                                        Ordem
                                    </th>

                                    <th>
                                        Receita
                                    </th>

                                    <th>
                                        Rendimento
                                    </th>

                                    <th>
                                        Utilização
                                    </th>

                                    <th>
                                        Quantidade convertida
                                    </th>

                                    <th>
                                        Custo da receita
                                    </th>

                                    <th>
                                        Custo no bolo
                                    </th>

                                    <th>
                                        Percentual
                                    </th>

                                    <th>
                                        Ações
                                    </th>

                                </tr>

                                </thead>

                                <tbody>

                                <?php foreach ($lista as $item): ?>

                                    <?php

                                    $custo_receita =
                                        (float) $item['custo_receita'];

                                    $quantidade =
                                        (float) $item['quantidade'];

                                    $rendimento =
                                        (float) $item['rendimento'];

                                    $unidadeUtilizacao =
                                        trim(
                                            $item['unidade_utilizacao'] ?? ''
                                        );

                                    $unidadeRendimento =
                                        trim(
                                            $item['unidade_rendimento'] ?? ''
                                        );

                                    $quantidadeConvertida =
                                        $quantidade;

                                    if (
                                        $rendimento > 0 &&
                                        $unidadeUtilizacao !== '' &&
                                        $unidadeRendimento !== ''
                                    ) {

                                        $convertida =
                                            converterQuantidade(
                                                $quantidade,
                                                $unidadeUtilizacao,
                                                $unidadeRendimento
                                            );

                                        if ($convertida !== null) {

                                            $quantidadeConvertida =
                                                $convertida;
                                        }
                                    }

                                    if ($rendimento > 0) {

                                        $custo_item =
                                            $custo_receita *
                                            (
                                                $quantidadeConvertida /
                                                $rendimento
                                            );

                                        $percentual =
                                            (
                                                $quantidadeConvertida /
                                                $rendimento
                                            ) * 100;

                                    } else {

                                        $custo_item =
                                            $custo_receita *
                                            $quantidade;

                                        $percentual = 0;
                                    }

                                    ?>

                                    <tr>

                                        <td>

                                            <strong>
                                                <?= (int) $item['ordem'] + 1 ?>
                                            </strong>

                                        </td>


                                        <td>

                                            <strong>

                                                <?= htmlspecialchars(
                                                    $item['nome']
                                                ) ?>

                                            </strong>

                                            <br>

                                            <span class="badge">

                                                <?= htmlspecialchars(
                                                    nomeTipoReceita(
                                                        $item['tipo']
                                                    )
                                                ) ?>

                                            </span>

                                        </td>


                                        <td>

                                            <?php if ($rendimento > 0): ?>

                                                <?= number_format(
                                                    $rendimento,
                                                    3,
                                                    ',',
                                                    '.'
                                                ) ?>

                                                <?= htmlspecialchars(
                                                    exibirUnidade(
                                                        $item['unidade_rendimento'] ?? ''
                                                    )
                                                ) ?>

                                            <?php else: ?>

                                                Não informado

                                            <?php endif; ?>

                                        </td>


                                        <td>

                                            <strong>

                                                <?= number_format(
                                                    $quantidade,
                                                    3,
                                                    ',',
                                                    '.'
                                                ) ?>

                                                <?= htmlspecialchars(
                                                    exibirUnidade(
                                                        $unidadeUtilizacao
                                                    )
                                                ) ?>

                                            </strong>

                                        </td>


                                        <td>

                                            <?php if ($rendimento > 0): ?>

                                                <?= number_format(
                                                    $quantidadeConvertida,
                                                    3,
                                                    ',',
                                                    '.'
                                                ) ?>

                                                <?= htmlspecialchars(
                                                    exibirUnidade(
                                                        $unidadeRendimento
                                                    )
                                                ) ?>

                                            <?php else: ?>

                                                -

                                            <?php endif; ?>

                                        </td>


                                        <td>

                                            R$

                                            <?= number_format(
                                                $custo_receita,
                                                2,
                                                ',',
                                                '.'
                                            ) ?>

                                        </td>


                                        <td>

                                            <span class="custo-bolo">

                                                R$

                                                <?= number_format(
                                                    $custo_item,
                                                    2,
                                                    ',',
                                                    '.'
                                                ) ?>

                                            </span>

                                        </td>


                                        <td>

                                            <?php if ($rendimento > 0): ?>

                                                <?= number_format(
                                                    $percentual,
                                                    2,
                                                    ',',
                                                    '.'
                                                ) ?>%

                                            <?php else: ?>

                                                -

                                            <?php endif; ?>

                                        </td>


                                        <td>

                                            <div class="acoes">

                                                <!-- SUBIR -->

                                                <form
                                                    method="POST"
                                                    onsubmit="return confirmarAcao(this)"
                                                >

                                                    <input
                                                        type="hidden"
                                                        name="csrf_token"
                                                        value="<?= htmlspecialchars($csrf_token) ?>"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="acao"
                                                        value="subir_receita"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="bolo_receita_id"
                                                        value="<?= (int) $item['id'] ?>"
                                                    >

                                                    <button
                                                        type="submit"
                                                        title="Subir"
                                                    >
                                                        ⬆️
                                                    </button>

                                                </form>


                                                <!-- DESCER -->

                                                <form
                                                    method="POST"
                                                    onsubmit="return confirmarAcao(this)"
                                                >

                                                    <input
                                                        type="hidden"
                                                        name="csrf_token"
                                                        value="<?= htmlspecialchars($csrf_token) ?>"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="acao"
                                                        value="descer_receita"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="bolo_receita_id"
                                                        value="<?= (int) $item['id'] ?>"
                                                    >

                                                    <button
                                                        type="submit"
                                                        title="Descer"
                                                    >
                                                        ⬇️
                                                    </button>

                                                </form>


                                                <!-- VER COMPOSIÇÃO -->

                                                <a
                                                    href="composicao_receita.php?id=<?= (int) $item['receita_id'] ?>"
                                                    title="Ver composição da receita"
                                                >
                                                    👁️
                                                </a>


                                                <!-- REMOVER -->

                                                <form
                                                    method="POST"
                                                    onsubmit="return confirmarRemocao(this)"
                                                >

                                                    <input
                                                        type="hidden"
                                                        name="csrf_token"
                                                        value="<?= htmlspecialchars($csrf_token) ?>"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="acao"
                                                        value="remover_receita"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="bolo_receita_id"
                                                        value="<?= (int) $item['id'] ?>"
                                                    >

                                                    <button
                                                        type="submit"
                                                        class="remover"
                                                        title="Remover"
                                                    >
                                                        🗑️
                                                    </button>

                                                </form>

                                            </div>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                                </tbody>

                            </table>

                        </div>

                    </div>

                <?php endif; ?>

            <?php endforeach; ?>

        <?php endif; ?>

    </div>


    <!-- =========================================================
         RESUMO
    ========================================================== -->

    <div class="card">

        <h2>
            💰 Resumo do Bolo
        </h2>

        <div class="resumo">

            <div class="resumo-box">

                <small>
                    CUSTO DAS RECEITAS
                </small>

                <strong>

                    R$

                    <?= number_format(
                        $custo_receitas,
                        2,
                        ',',
                        '.'
                    ) ?>

                </strong>

            </div>


            <div class="resumo-box">

                <small>
                    INSUMOS DIRETOS
                </small>

                <strong>

                    R$

                    <?= number_format(
                        $custo_direto,
                        2,
                        ',',
                        '.'
                    ) ?>

                </strong>

            </div>


            <div class="resumo-box">

                <small>
                    CUSTO TOTAL
                </small>

                <strong>

                    R$

                    <?= number_format(
                        $custo_total,
                        2,
                        ',',
                        '.'
                    ) ?>

                </strong>

            </div>


            <div class="resumo-box">

                <small>
                    PREÇO DE VENDA
                </small>

                <strong>

                    R$

                    <?= number_format(
                        $preco_venda,
                        2,
                        ',',
                        '.'
                    ) ?>

                </strong>

            </div>


            <div class="resumo-box">

                <small>
                    RESULTADO BRUTO ESTIMADO
                </small>

                <strong
                    class="<?= $resultado >= 0
                        ? 'positivo'
                        : 'negativo'
                    ?>"
                >

                    R$

                    <?= number_format(
                        $resultado,
                        2,
                        ',',
                        '.'
                    ) ?>

                </strong>

            </div>


            <div class="resumo-box">

                <small>
                    MARGEM SOBRE A VENDA
                </small>

                <strong
                    class="<?= $margem >= 0
                        ? 'positivo'
                        : 'negativo'
                    ?>"
                >

                    <?= number_format(
                        $margem,
                        2,
                        ',',
                        '.'
                    ) ?>%

                </strong>

            </div>

        </div>

    </div>

</div>


<script>

/*
|--------------------------------------------------------------------------
| FUNÇÕES DE UNIDADE - JAVASCRIPT
|--------------------------------------------------------------------------
*/

function normalizarUnidade(unidade) {

    if (!unidade) {
        return '';
    }

    let valor = unidade
        .trim()
        .toLowerCase();

    const mapa = {

        'quilo': 'kg',
        'quilos': 'kg',
        'quilograma': 'kg',
        'quilogramas': 'kg',
        'kg': 'kg',

        'grama': 'g',
        'gramas': 'g',
        'g': 'g',

        'miligrama': 'mg',
        'miligramas': 'mg',
        'mg': 'mg',

        'litro': 'l',
        'litros': 'l',
        'l': 'l',

        'mililitro': 'ml',
        'mililitros': 'ml',
        'ml': 'ml',

        'un': 'un',
        'und': 'un',
        'unid': 'un',
        'unidade': 'un',
        'unidades': 'un'
    };

    return mapa[valor] || valor;
}


function categoriaUnidade(unidade) {

    unidade =
        normalizarUnidade(unidade);

    if (
        ['mg', 'g', 'kg'].includes(unidade)
    ) {
        return 'massa';
    }

    if (
        ['ml', 'l'].includes(unidade)
    ) {
        return 'volume';
    }

    if (
        unidade === 'un'
    ) {
        return 'quantidade';
    }

    return null;
}


function fatorUnidade(unidade) {

    unidade =
        normalizarUnidade(unidade);

    switch (unidade) {

        case 'mg':
            return 0.001;

        case 'g':
            return 1;

        case 'kg':
            return 1000;

        case 'ml':
            return 1;

        case 'l':
            return 1000;

        case 'un':
            return 1;

        default:
            return null;
    }
}


function unidadesCompativeis(
    origem,
    destino
) {

    const categoriaOrigem =
        categoriaUnidade(origem);

    const categoriaDestino =
        categoriaUnidade(destino);

    if (
        !categoriaOrigem ||
        !categoriaDestino
    ) {
        return false;
    }

    return (
        categoriaOrigem ===
        categoriaDestino
    );
}


function converterQuantidade(
    quantidade,
    unidadeOrigem,
    unidadeDestino
) {

    const origem =
        normalizarUnidade(
            unidadeOrigem
        );

    const destino =
        normalizarUnidade(
            unidadeDestino
        );

    if (
        !origem ||
        !destino
    ) {
        return null;
    }

    if (
        !unidadesCompativeis(
            origem,
            destino
        )
    ) {
        return null;
    }

    const fatorOrigem =
        fatorUnidade(origem);

    const fatorDestino =
        fatorUnidade(destino);

    if (
        fatorOrigem === null ||
        fatorDestino === null
    ) {
        return null;
    }

    return (
        quantidade *
        fatorOrigem
    ) / fatorDestino;
}


function nomeUnidade(unidade) {

    const normalizada =
        normalizarUnidade(unidade);

    switch (normalizada) {

        case 'kg':
            return 'kg';

        case 'g':
            return 'g';

        case 'mg':
            return 'mg';

        case 'l':
            return 'L';

        case 'ml':
            return 'ml';

        case 'un':
            return 'un';

        default:
            return unidade;
    }
}


/*
|--------------------------------------------------------------------------
| OBTER UNIDADES COMPATÍVEIS
|--------------------------------------------------------------------------
*/

function obterUnidadesCompativeis(
    unidadeRendimento
) {

    const categoria =
        categoriaUnidade(
            unidadeRendimento
        );

    if (categoria === 'massa') {

        return [
            {
                valor: 'mg',
                nome: 'mg'
            },
            {
                valor: 'g',
                nome: 'g'
            },
            {
                valor: 'kg',
                nome: 'kg'
            }
        ];
    }

    if (categoria === 'volume') {

        return [
            {
                valor: 'ml',
                nome: 'ml'
            },
            {
                valor: 'l',
                nome: 'L'
            }
        ];
    }

    if (categoria === 'quantidade') {

        return [
            {
                valor: 'un',
                nome: 'un'
            }
        ];
    }

    return [];
}


/*
|--------------------------------------------------------------------------
| ATUALIZAR RECEITA
|--------------------------------------------------------------------------
*/

function atualizarReceita() {

    const select =
        document.getElementById(
            'receita_id'
        );

    const option =
        select.options[
            select.selectedIndex
        ];

    const quantidade =
        document.getElementById(
            'quantidade'
        );

    const unidade =
        document.getElementById(
            'unidade_utilizacao'
        );

    const info =
        document.getElementById(
            'informacao_receita'
        );

    const conversaoInfo =
        document.getElementById(
            'conversao_info'
        );

    const btn =
        document.getElementById(
            'btn_adicionar'
        );

    if (
        !option ||
        !option.value
    ) {

        quantidade.value = '';

        quantidade.disabled = true;

        quantidade.removeAttribute(
            'max'
        );

        unidade.innerHTML =
            '<option value="">Selecione a unidade</option>';

        unidade.disabled = true;

        info.style.display =
            'none';

        conversaoInfo.style.display =
            'none';

        btn.disabled = true;

        return;
    }


    const tipo =
        option.dataset.tipo || '';


    const rendimento =
        parseFloat(
            option.dataset.rendimento ||
            '0'
        );


    const unidadeRendimento =
        option.dataset.unidade || '';


    const custo =
        parseFloat(
            option.dataset.custo ||
            '0'
        );


    unidade.innerHTML = '';

    if (rendimento > 0) {

        const unidades =
            obterUnidadesCompativeis(
                unidadeRendimento
            );

        if (unidades.length === 0) {

            unidade.innerHTML =
                '<option value="' +
                unidadeRendimento +
                '">' +
                unidadeRendimento +
                '</option>';

        } else {

            unidades.forEach(
                function(item) {

                    const optionUnidade =
                        document.createElement(
                            'option'
                        );

                    optionUnidade.value =
                        item.valor;

                    optionUnidade.textContent =
                        item.nome;

                    if (
                        normalizarUnidade(
                            unidadeRendimento
                        ) ===
                        item.valor
                    ) {

                        optionUnidade.selected =
                            true;
                    }

                    unidade.appendChild(
                        optionUnidade
                    );
                }
            );
        }

        conversaoInfo.style.display =
            'block';

    } else {

        const optionUnidade =
            document.createElement(
                'option'
            );

        optionUnidade.value =
            'un';

        optionUnidade.textContent =
            'un';

        optionUnidade.selected =
            true;

        unidade.appendChild(
            optionUnidade
        );

        conversaoInfo.style.display =
            'none';
    }


    quantidade.disabled = false;

    unidade.disabled = false;

    quantidade.value = '';


    if (rendimento > 0) {

        const unidadeSelecionada =
            unidade.value ||
            unidadeRendimento;

        const maximo =
            converterQuantidade(
                rendimento,
                unidadeRendimento,
                unidadeSelecionada
            );

        if (maximo !== null) {

            quantidade.max =
                maximo;

            quantidade.placeholder =
                'Máximo: ' +
                maximo.toLocaleString(
                    'pt-BR',
                    {
                        minimumFractionDigits: 3,
                        maximumFractionDigits: 3
                    }
                );
        }

    } else {

        quantidade.removeAttribute(
            'max'
        );

        quantidade.placeholder =
            'Informe a quantidade';
    }


    document.getElementById(
        'info_tipo'
    ).textContent =
        tipo.charAt(0).toUpperCase() +
        tipo.slice(1);


    if (rendimento > 0) {

        document.getElementById(
            'info_rendimento'
        ).textContent =
            rendimento.toLocaleString(
                'pt-BR',
                {
                    minimumFractionDigits: 3,
                    maximumFractionDigits: 3
                }
            ) +
            ' ' +
            nomeUnidade(
                unidadeRendimento
            );

    } else {

        document.getElementById(
            'info_rendimento'
        ).textContent =
            'Não informado';
    }


    document.getElementById(
        'info_custo'
    ).textContent =
        'R$ ' +
        custo.toLocaleString(
            'pt-BR',
            {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }
        );


    document.getElementById(
        'info_quantidade_convertida'
    ).textContent =
        '-';


    document.getElementById(
        'info_custo_bolo'
    ).textContent =
        '-';


    document.getElementById(
        'info_percentual'
    ).textContent =
        '-';


    info.style.display =
        'block';


    btn.disabled =
        true;


    quantidade.oninput =
        calcularPrevia;

    unidade.onchange =
        function() {

            atualizarLimite();

            calcularPrevia();
        };

}


/*
|--------------------------------------------------------------------------
| ATUALIZAR LIMITE
|--------------------------------------------------------------------------
*/

function atualizarLimite() {

    const select =
        document.getElementById(
            'receita_id'
        );

    const option =
        select.options[
            select.selectedIndex
        ];

    const quantidade =
        document.getElementById(
            'quantidade'
        );

    const unidade =
        document.getElementById(
            'unidade_utilizacao'
        );

    if (
        !option ||
        !option.value
    ) {
        return;
    }

    const rendimento =
        parseFloat(
            option.dataset.rendimento ||
            '0'
        );

    const unidadeRendimento =
        option.dataset.unidade ||
        '';

    if (
        rendimento > 0 &&
        unidade.value
    ) {

        const maximo =
            converterQuantidade(
                rendimento,
                unidadeRendimento,
                unidade.value
            );

        if (maximo !== null) {

            quantidade.max =
                maximo;

            quantidade.placeholder =
                'Máximo: ' +
                maximo.toLocaleString(
                    'pt-BR',
                    {
                        minimumFractionDigits: 3,
                        maximumFractionDigits: 3
                    }
                );
        }
    }
}


/*
|--------------------------------------------------------------------------
| CALCULAR PRÉVIA
|--------------------------------------------------------------------------
*/

function calcularPrevia() {

    const select =
        document.getElementById(
            'receita_id'
        );

    const option =
        select.options[
            select.selectedIndex
        ];

    const quantidade =
        parseFloat(
            document.getElementById(
                'quantidade'
            ).value || '0'
        );

    const unidadeUtilizacao =
        document.getElementById(
            'unidade_utilizacao'
        ).value || '';


    const rendimento =
        parseFloat(
            option?.dataset.rendimento ||
            '0'
        );


    const unidadeRendimento =
        option?.dataset.unidade ||
        '';


    const custo =
        parseFloat(
            option?.dataset.custo ||
            '0'
        );


    const btn =
        document.getElementById(
            'btn_adicionar'
        );


    if (
        !option ||
        !option.value
    ) {

        btn.disabled =
            true;

        return;
    }


    if (
        quantidade <= 0 ||
        isNaN(quantidade)
    ) {

        btn.disabled =
            true;

        document.getElementById(
            'info_quantidade_convertida'
        ).textContent =
            '-';

        document.getElementById(
            'info_custo_bolo'
        ).textContent =
            '-';

        document.getElementById(
            'info_percentual'
        ).textContent =
            '-';

        return;
    }


    /*
    |--------------------------------------------------------------------------
    | SEM RENDIMENTO
    |--------------------------------------------------------------------------
    */

    if (rendimento <= 0) {

        const custoBolo =
            custo *
            quantidade;

        document.getElementById(
            'info_quantidade_convertida'
        ).textContent =
            'Não se aplica';

        document.getElementById(
            'info_custo_bolo'
        ).textContent =
            'R$ ' +
            custoBolo.toLocaleString(
                'pt-BR',
                {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2
                }
            );

        document.getElementById(
            'info_percentual'
        ).textContent =
            '-';

        btn.disabled =
            false;

        return;
    }


    /*
    |--------------------------------------------------------------------------
    | VERIFICAR COMPATIBILIDADE
    |--------------------------------------------------------------------------
    */

    if (
        !unidadesCompativeis(
            unidadeUtilizacao,
            unidadeRendimento
        )
    ) {

        btn.disabled =
            true;

        document.getElementById(
            'info_quantidade_convertida'
        ).textContent =
            'Unidades incompatíveis';

        document.getElementById(
            'info_custo_bolo'
        ).textContent =
            '-';

        document.getElementById(
            'info_percentual'
        ).textContent =
            '-';

        return;
    }


    /*
    |--------------------------------------------------------------------------
    | CONVERTER
    |--------------------------------------------------------------------------
    */

    const quantidadeConvertida =
        converterQuantidade(
            quantidade,
            unidadeUtilizacao,
            unidadeRendimento
        );


    if (
        quantidadeConvertida === null
    ) {

        btn.disabled =
            true;

        document.getElementById(
            'info_quantidade_convertida'
        ).textContent =
            'Erro na conversão';

        return;
    }


    /*
    |--------------------------------------------------------------------------
    | VERIFICAR LIMITE
    |--------------------------------------------------------------------------
    */

    if (
        quantidadeConvertida >
        rendimento + 0.0000001
    ) {

        btn.disabled =
            true;

        document.getElementById(
            'info_quantidade_convertida'
        ).textContent =
            quantidadeConvertida.toLocaleString(
                'pt-BR',
                {
                    minimumFractionDigits: 3,
                    maximumFractionDigits: 3
                }
            ) +
            ' ' +
            nomeUnidade(
                unidadeRendimento
            );

        document.getElementById(
            'info_custo_bolo'
        ).textContent =
            'Quantidade maior que o rendimento';

        document.getElementById(
            'info_percentual'
        ).textContent =
            '-';

        return;
    }


    /*
    |--------------------------------------------------------------------------
    | CUSTO PROPORCIONAL
    |--------------------------------------------------------------------------
    */

    const custoBolo =
        custo *
        (
            quantidadeConvertida /
            rendimento
        );


    const percentual =
        (
            quantidadeConvertida /
            rendimento
        ) *
        100;


    document.getElementById(
        'info_quantidade_convertida'
    ).textContent =
        quantidadeConvertida.toLocaleString(
            'pt-BR',
            {
                minimumFractionDigits: 3,
                maximumFractionDigits: 3
            }
        ) +
        ' ' +
        nomeUnidade(
            unidadeRendimento
        );


    document.getElementById(
        'info_custo_bolo'
    ).textContent =
        'R$ ' +
        custoBolo.toLocaleString(
            'pt-BR',
            {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }
        );


    document.getElementById(
        'info_percentual'
    ).textContent =
        percentual.toLocaleString(
            'pt-BR',
            {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            }
        ) +
        '%';


    btn.disabled =
        false;
}


/*
|--------------------------------------------------------------------------
| CONFIRMAÇÕES
|--------------------------------------------------------------------------
*/

function confirmarAcao(form) {

    return true;
}


function confirmarRemocao(form) {

    return confirm(
        'Deseja remover esta receita do bolo?'
    );
}

</script>

</body>

</html>