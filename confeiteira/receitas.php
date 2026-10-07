<?php

require_once "../config/auth.php";
require_once "../config/banco.php";

if (!isset($_SESSION['usuario_id'])) {
    header("Location: ../login.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| Buscar confeiteira logada
|--------------------------------------------------------------------------
*/

$usuario_id = $_SESSION['usuario_id'];

$stmt = $pdo->prepare("
    SELECT id, nome_confeitaria
    FROM confeiteiras
    WHERE usuario_id = ?
    LIMIT 1
");

$stmt->execute([$usuario_id]);

$confeiteira = $stmt->fetch();

if (!$confeiteira) {
    die("Confeiteira não encontrada.");
}

$confeiteira_id = $confeiteira['id'];

/*
|--------------------------------------------------------------------------
| Ativar / desativar receita
|--------------------------------------------------------------------------
*/

if (isset($_GET['acao'], $_GET['id'])) {

    $id = (int) $_GET['id'];
    $acao = $_GET['acao'];

    if ($acao === 'ativar') {

        $stmt = $pdo->prepare("
            UPDATE receitas
            SET ativo = 1
            WHERE id = ?
            AND confeiteira_id = ?
        ");

        $stmt->execute([$id, $confeiteira_id]);

    } elseif ($acao === 'desativar') {

        $stmt = $pdo->prepare("
            UPDATE receitas
            SET ativo = 0
            WHERE id = ?
            AND confeiteira_id = ?
        ");

        $stmt->execute([$id, $confeiteira_id]);
    }

    header("Location: receitas.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| Excluir receita
|--------------------------------------------------------------------------
*/

if (isset($_GET['excluir'])) {

    $id = (int) $_GET['excluir'];

    $stmt = $pdo->prepare("
        DELETE FROM receitas
        WHERE id = ?
        AND confeiteira_id = ?
    ");

    $stmt->execute([$id, $confeiteira_id]);

    header("Location: receitas.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| Buscar receitas
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        r.id,
        r.nome,
        r.tipo,
        r.descricao,
        r.rendimento,
        r.unidade_rendimento,
        r.ativo,
        r.criado_em,

        COALESCE(
            SUM(ri.quantidade * i.custo_unitario),
            0
        ) AS custo_total

    FROM receitas r

    LEFT JOIN receita_insumos ri
        ON ri.receita_id = r.id

    LEFT JOIN insumos i
        ON i.id = ri.insumo_id

    WHERE r.confeiteira_id = ?

    GROUP BY
        r.id,
        r.nome,
        r.tipo,
        r.descricao,
        r.rendimento,
        r.unidade_rendimento,
        r.ativo,
        r.criado_em

    ORDER BY r.id DESC
");

$stmt->execute([$confeiteira_id]);

$receitas = $stmt->fetchAll();

?>

<!DOCTYPE html>
<html lang="pt-BR">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Minhas Receitas</title>

    <link rel="stylesheet" href="../assets/style.css">

</head>

<body>

<div class="container">

    <h1>🧁 Minhas Receitas</h1>

    <p>
        Confeitaria:
        <strong>
            <?= htmlspecialchars($confeiteira['nome_confeitaria']) ?>
        </strong>
    </p>

    <div class="menu-admin">

        <a href="index.php">
            🏠 Dashboard
        </a>

        <a href="bolos.php">
            🎂 Meus Bolos
        </a>

        <a href="insumos.php">
            📦 Insumos
        </a>

        <a href="cadastrar_receita.php">
            ➕ Nova Receita
        </a>

    </div>

    <hr>

    <?php if (!$receitas): ?>

        <div class="card">

            <h2>Nenhuma receita cadastrada</h2>

            <p>
                Comece cadastrando sua primeira receita.
            </p>

            <a href="cadastrar_receita.php">
                ➕ Cadastrar Receita
            </a>

        </div>

    <?php else: ?>

        <div style="overflow-x:auto;">

            <table>

                <thead>

                    <tr>

                        <th>Receita</th>

                        <th>Tipo</th>

                        <th>Rendimento</th>

                        <th>Custo</th>

                        <th>Status</th>

                        <th>Ações</th>

                    </tr>

                </thead>

                <tbody>

                <?php foreach ($receitas as $receita): ?>

                    <tr>

                        <td>

                            <strong>
                                <?= htmlspecialchars($receita['nome']) ?>
                            </strong>

                            <?php if (!empty($receita['descricao'])): ?>

                                <br>

                                <small>
                                    <?= htmlspecialchars($receita['descricao']) ?>
                                </small>

                            <?php endif; ?>

                        </td>

                        <td>

                            <?= htmlspecialchars(
                                ucfirst($receita['tipo'])
                            ) ?>

                        </td>

                        <td>

                            <?php

                            if (
                                $receita['rendimento'] !== null &&
                                $receita['rendimento'] > 0
                            ) {

                                echo number_format(
                                    $receita['rendimento'],
                                    3,
                                    ',',
                                    '.'
                                );

                                if (!empty($receita['unidade_rendimento'])) {

                                    echo ' ' .
                                        htmlspecialchars(
                                            $receita['unidade_rendimento']
                                        );
                                }

                            } else {

                                echo '-';

                            }

                            ?>

                        </td>

                        <td>

                            <strong>
                                R$
                                <?= number_format(
                                    $receita['custo_total'],
                                    2,
                                    ',',
                                    '.'
                                ) ?>
                            </strong>

                        </td>

                        <td>

                            <?php if ($receita['ativo']): ?>

                                <span style="color:green;">
                                    ● Ativa
                                </span>

                            <?php else: ?>

                                <span style="color:red;">
                                    ● Inativa
                                </span>

                            <?php endif; ?>

                        </td>

                        <td>

                            <a href="composicao_receita.php?id=<?= $receita['id'] ?>">
                                🧾 Composição
                            </a>

                            <br>

                            <a href="editar_receita.php?id=<?= $receita['id'] ?>">
                                ✏️ Editar
                            </a>

                            <br>

                            <?php if ($receita['ativo']): ?>

                                <a
                                    href="receitas.php?acao=desativar&id=<?= $receita['id'] ?>"
                                    onclick="return confirm('Deseja desativar esta receita?')"
                                >
                                    🔴 Desativar
                                </a>

                            <?php else: ?>

                                <a
                                    href="receitas.php?acao=ativar&id=<?= $receita['id'] ?>"
                                >
                                    🟢 Ativar
                                </a>

                            <?php endif; ?>

                            <br>

                            <a
                                href="receitas.php?excluir=<?= $receita['id'] ?>"
                                onclick="return confirm('ATENÇÃO: deseja excluir esta receita? Essa ação não poderá ser desfeita.')"
                                style="color:red;"
                            >
                                🗑️ Excluir
                            </a>

                        </td>

                    </tr>

                <?php endforeach; ?>

                </tbody>

            </table>

        </div>

    <?php endif; ?>

</div>

</body>

</html>