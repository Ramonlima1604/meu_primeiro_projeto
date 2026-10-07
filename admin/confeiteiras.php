<?php

require_once "../config/banco.php";
require_once "../config/auth.php";

exigirTipo("admin");


/*
|--------------------------------------------------------------------------
| ATIVAR / DESATIVAR
|--------------------------------------------------------------------------
*/

if (isset($_GET["acao"]) && isset($_GET["id"])) {

    $id = (int) $_GET["id"];

    if ($_GET["acao"] === "desativar") {

        $sql = "
            UPDATE usuarios u

            INNER JOIN confeiteiras c
                ON c.usuario_id = u.id

            SET u.status = 0

            WHERE c.id = ?
        ";

        $stmt = $pdo->prepare($sql);

        $stmt->execute([$id]);
    }


    if ($_GET["acao"] === "ativar") {

        $sql = "
            UPDATE usuarios u

            INNER JOIN confeiteiras c
                ON c.usuario_id = u.id

            SET u.status = 1

            WHERE c.id = ?
        ";

        $stmt = $pdo->prepare($sql);

        $stmt->execute([$id]);
    }

    header("Location: confeiteiras.php");

    exit;
}


/*
|--------------------------------------------------------------------------
| BUSCAR CONFEITEIRAS
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        c.id,
        c.nome_confeitaria,
        c.telefone,
        c.cidade,
        c.criado_em,

        u.nome,
        u.email,
        u.status

    FROM confeiteiras c

    INNER JOIN usuarios u
        ON u.id = c.usuario_id

    ORDER BY c.id DESC
";

$stmt = $pdo->query($sql);

$confeiteiras = $stmt->fetchAll();

?>

<!DOCTYPE html>
<html lang="pt-br">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Confeiteiras</title>

    <link rel="stylesheet"
          href="../assets/style.css">

</head>

<body>

<div class="admin-container">


    <header class="topo">

        <div>

            <h1>Confeiteiras</h1>

            <p>
                Gerenciamento das confeiteiras
            </p>

        </div>

        <a href="index.php">
            Dashboard
        </a>

    </header>


    <main>


        <div class="pagina-acoes">

            <a
                href="cadastrar_confeiteira.php"
                class="botao"
            >
                + Nova Confeiteira
            </a>

        </div>


        <div class="tabela-container">

            <table>

                <thead>

                    <tr>

                        <th>ID</th>

                        <th>Nome</th>

                        <th>Confeitaria</th>

                        <th>E-mail</th>

                        <th>Telefone</th>

                        <th>Cidade</th>

                        <th>Status</th>

                        <th>Ações</th>

                    </tr>

                </thead>


                <tbody>

                <?php foreach ($confeiteiras as $confeiteira): ?>

                    <tr>

                        <td>
                            <?= $confeiteira["id"] ?>
                        </td>

                        <td>
                            <?= htmlspecialchars(
                                $confeiteira["nome"]
                            ) ?>
                        </td>

                        <td>
                            <?= htmlspecialchars(
                                $confeiteira["nome_confeitaria"]
                            ) ?>
                        </td>

                        <td>
                            <?= htmlspecialchars(
                                $confeiteira["email"]
                            ) ?>
                        </td>

                        <td>
                            <?= htmlspecialchars(
                                $confeiteira["telefone"]
                            ) ?>
                        </td>

                        <td>
                            <?= htmlspecialchars(
                                $confeiteira["cidade"]
                            ) ?>
                        </td>

                        <td>

                            <?php if ($confeiteira["status"] == 1): ?>

                                <span class="status ativo">
                                    Ativa
                                </span>

                            <?php else: ?>

                                <span class="status inativo">
                                    Inativa
                                </span>

                            <?php endif; ?>

                        </td>

                        <td>

                            <a
                                href="editar_confeiteira.php?id=<?= $confeiteira["id"] ?>"
                                class="acao"
                            >
                                Editar
                            </a>


                            <?php if ($confeiteira["status"] == 1): ?>

                                <a
                                    href="confeiteiras.php?acao=desativar&id=<?= $confeiteira["id"] ?>"
                                    class="acao perigo"
                                    onclick="return confirm('Deseja realmente desativar esta confeiteira?')"
                                >
                                    Desativar
                                </a>

                            <?php else: ?>

                                <a
                                    href="confeiteiras.php?acao=ativar&id=<?= $confeiteira["id"] ?>"
                                    class="acao sucesso"
                                >
                                    Ativar
                                </a>

                            <?php endif; ?>

                        </td>

                    </tr>

                <?php endforeach; ?>


                <?php if (count($confeiteiras) === 0): ?>

                    <tr>

                        <td colspan="8">

                            Nenhuma confeiteira cadastrada.

                        </td>

                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </main>

</div>

</body>

</html>