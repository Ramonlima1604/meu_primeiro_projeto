<?php

session_start();

require_once __DIR__ . '/../config/banco.php';

/*
|--------------------------------------------------------------------------
| AUTENTICAÇÃO
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION['usuario_id']) || empty($_SESSION['usuario_id'])) {
    header('Location: ../login.php');
    exit;
}

$usuario_id = (int) $_SESSION['usuario_id'];

/*
|--------------------------------------------------------------------------
| BUSCAR USUÁRIO
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT 
        id,
        nome,
        email
    FROM usuarios
    WHERE id = ?
    LIMIT 1
");

$stmt->execute([$usuario_id]);

$usuario = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$usuario) {

    session_destroy();

    header('Location: ../login.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| BUSCAR CONFEITEIRA
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        id,
        usuario_id,
        nome_confeitaria,
        telefone,
        cidade,
        descricao
    FROM confeiteiras
    WHERE usuario_id = ?
    LIMIT 1
");

$stmt->execute([$usuario_id]);

$confeiteira = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$confeiteira) {
    die('Cadastro da confeiteira não encontrado.');
}

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['csrf_minha_conta'])) {

    $_SESSION['csrf_minha_conta'] =
        bin2hex(random_bytes(32));
}

$csrf = $_SESSION['csrf_minha_conta'];

/*
|--------------------------------------------------------------------------
| VARIÁVEIS DO FORMULÁRIO
|--------------------------------------------------------------------------
*/

$nome = $usuario['nome'] ?? '';
$email = $usuario['email'] ?? '';

$nome_confeitaria =
    $confeiteira['nome_confeitaria'] ?? '';

$telefone =
    $confeiteira['telefone'] ?? '';

$cidade =
    $confeiteira['cidade'] ?? '';

$descricao =
    $confeiteira['descricao'] ?? '';

$senha_atual = '';
$nova_senha = '';
$confirmar_senha = '';

$erros = [];
$sucesso = '';

/*
|--------------------------------------------------------------------------
| FUNÇÕES
|--------------------------------------------------------------------------
*/

function limpar($valor)
{
    return trim((string) $valor);
}

/*
|--------------------------------------------------------------------------
| PROCESSAR FORMULÁRIO
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $csrf_form = $_POST['csrf_token'] ?? '';

    if (
        empty($csrf_form) ||
        !hash_equals(
            $_SESSION['csrf_minha_conta'],
            $csrf_form
        )
    ) {

        $erros[] =
            'Sessão expirada. Atualize a página e tente novamente.';
    }

    /*
    |--------------------------------------------------------------------------
    | DADOS DO USUÁRIO
    |--------------------------------------------------------------------------
    */

    $nome =
        limpar($_POST['nome'] ?? '');

    $email =
        limpar($_POST['email'] ?? '');

    /*
    |--------------------------------------------------------------------------
    | DADOS DA CONFEITARIA
    |--------------------------------------------------------------------------
    */

    $nome_confeitaria =
        limpar($_POST['nome_confeitaria'] ?? '');

    $telefone =
        limpar($_POST['telefone'] ?? '');

    $cidade =
        limpar($_POST['cidade'] ?? '');

    $descricao =
        limpar($_POST['descricao'] ?? '');

    /*
    |--------------------------------------------------------------------------
    | SENHAS
    |--------------------------------------------------------------------------
    */

    $senha_atual =
        $_POST['senha_atual'] ?? '';

    $nova_senha =
        $_POST['nova_senha'] ?? '';

    $confirmar_senha =
        $_POST['confirmar_senha'] ?? '';

    /*
    |--------------------------------------------------------------------------
    | VALIDAÇÕES
    |--------------------------------------------------------------------------
    */

    if ($nome === '') {

        $erros[] =
            'Informe seu nome.';

    } elseif (mb_strlen($nome) < 2) {

        $erros[] =
            'Seu nome deve possuir pelo menos 2 caracteres.';

    } elseif (mb_strlen($nome) > 150) {

        $erros[] =
            'Seu nome não pode ultrapassar 150 caracteres.';
    }

    if ($email === '') {

        $erros[] =
            'Informe seu e-mail.';

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $erros[] =
            'Informe um e-mail válido.';

    } elseif (mb_strlen($email) > 150) {

        $erros[] =
            'O e-mail não pode ultrapassar 150 caracteres.';
    }

    if ($nome_confeitaria === '') {

        $erros[] =
            'Informe o nome da confeitaria.';

    } elseif (mb_strlen($nome_confeitaria) > 150) {

        $erros[] =
            'O nome da confeitaria não pode ultrapassar 150 caracteres.';
    }

    if (mb_strlen($telefone) > 30) {

        $erros[] =
            'O telefone não pode ultrapassar 30 caracteres.';
    }

    if (mb_strlen($cidade) > 100) {

        $erros[] =
            'A cidade não pode ultrapassar 100 caracteres.';
    }

    if (mb_strlen($descricao) > 1000) {

        $erros[] =
            'A descrição não pode ultrapassar 1000 caracteres.';
    }

    /*
    |--------------------------------------------------------------------------
    | ALTERAÇÃO DE SENHA
    |--------------------------------------------------------------------------
    */

    $alterar_senha = false;

    if (
        $nova_senha !== '' ||
        $confirmar_senha !== ''
    ) {

        $alterar_senha = true;

        if ($nova_senha === '') {

            $erros[] =
                'Informe a nova senha.';

        } elseif (strlen($nova_senha) < 6) {

            $erros[] =
                'A nova senha deve possuir pelo menos 6 caracteres.';

        } elseif (strlen($nova_senha) > 255) {

            $erros[] =
                'A nova senha é muito longa.';
        }

        if ($confirmar_senha === '') {

            $erros[] =
                'Confirme a nova senha.';
        }

        if (
            $nova_senha !== '' &&
            $confirmar_senha !== '' &&
            $nova_senha !== $confirmar_senha
        ) {

            $erros[] =
                'A confirmação da senha não confere.';
        }

        if ($senha_atual === '') {

            $erros[] =
                'Informe sua senha atual para alterar a senha.';
        }
    }

    /*
    |--------------------------------------------------------------------------
    | VERIFICAR E-MAIL DUPLICADO
    |--------------------------------------------------------------------------
    */

    if (empty($erros)) {

        $stmt = $pdo->prepare("
            SELECT id
            FROM usuarios
            WHERE email = ?
              AND id <> ?
            LIMIT 1
        ");

        $stmt->execute([
            $email,
            $usuario_id
        ]);

        if ($stmt->fetch()) {

            $erros[] =
                'Este e-mail já está sendo utilizado por outra conta.';
        }
    }

    /*
    |--------------------------------------------------------------------------
    | VERIFICAR SENHA ATUAL
    |--------------------------------------------------------------------------
    */

    if (
        $alterar_senha &&
        empty($erros)
    ) {

        $stmt = $pdo->prepare("
            SELECT senha
            FROM usuarios
            WHERE id = ?
            LIMIT 1
        ");

        $stmt->execute([
            $usuario_id
        ]);

        $usuario_senha =
            $stmt->fetch(PDO::FETCH_ASSOC);

        if (
            !$usuario_senha ||
            !password_verify(
                $senha_atual,
                $usuario_senha['senha']
            )
        ) {

            $erros[] =
                'A senha atual informada está incorreta.';
        }
    }

    /*
    |--------------------------------------------------------------------------
    | SALVAR ALTERAÇÕES
    |--------------------------------------------------------------------------
    */

    if (empty($erros)) {

        try {

            $pdo->beginTransaction();

            /*
            |--------------------------------------------------------------------------
            | ATUALIZAR USUÁRIO
            |--------------------------------------------------------------------------
            */

            if ($alterar_senha) {

                $senha_hash =
                    password_hash(
                        $nova_senha,
                        PASSWORD_DEFAULT
                    );

                if ($senha_hash === false) {

                    throw new Exception(
                        'Não foi possível gerar a senha.'
                    );
                }

                $stmt = $pdo->prepare("
                    UPDATE usuarios
                    SET
                        nome = ?,
                        email = ?,
                        senha = ?
                    WHERE id = ?
                ");

                $stmt->execute([
                    $nome,
                    $email,
                    $senha_hash,
                    $usuario_id
                ]);

            } else {

                $stmt = $pdo->prepare("
                    UPDATE usuarios
                    SET
                        nome = ?,
                        email = ?
                    WHERE id = ?
                ");

                $stmt->execute([
                    $nome,
                    $email,
                    $usuario_id
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | ATUALIZAR CONFEITEIRA
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                UPDATE confeiteiras
                SET
                    nome_confeitaria = ?,
                    telefone = ?,
                    cidade = ?,
                    descricao = ?
                WHERE usuario_id = ?
            ");

            $stmt->execute([
                $nome_confeitaria,
                $telefone,
                $cidade,
                $descricao,
                $usuario_id
            ]);

            $pdo->commit();

            /*
            |--------------------------------------------------------------------------
            | ATUALIZAR SESSÃO
            |--------------------------------------------------------------------------
            */

            $_SESSION['nome'] = $nome;

            /*
            |--------------------------------------------------------------------------
            | LIMPAR SENHAS
            |--------------------------------------------------------------------------
            */

            $senha_atual = '';
            $nova_senha = '';
            $confirmar_senha = '';

            /*
            |--------------------------------------------------------------------------
            | NOVO TOKEN CSRF
            |--------------------------------------------------------------------------
            */

            $_SESSION['csrf_minha_conta'] =
                bin2hex(random_bytes(32));

            $csrf =
                $_SESSION['csrf_minha_conta'];

            $sucesso =
                'Sua conta foi atualizada com sucesso.';

            /*
            |--------------------------------------------------------------------------
            | RECARREGAR USUÁRIO
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                SELECT
                    nome,
                    email
                FROM usuarios
                WHERE id = ?
                LIMIT 1
            ");

            $stmt->execute([
                $usuario_id
            ]);

            $usuario =
                $stmt->fetch(PDO::FETCH_ASSOC);

            /*
            |--------------------------------------------------------------------------
            | RECARREGAR CONFEITEIRA
            |--------------------------------------------------------------------------
            */

            $stmt = $pdo->prepare("
                SELECT
                    nome_confeitaria,
                    telefone,
                    cidade,
                    descricao
                FROM confeiteiras
                WHERE usuario_id = ?
                LIMIT 1
            ");

            $stmt->execute([
                $usuario_id
            ]);

            $confeiteira =
                $stmt->fetch(PDO::FETCH_ASSOC);

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log(
                'Erro em minha_conta.php: ' .
                $e->getMessage()
            );

            $erros[] =
                'Não foi possível atualizar sua conta. Tente novamente.';
        }
    }
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

    <title>Minha Conta - Sistema de Bolos</title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            background: #f5f6f8;
            color: #222;
        }

        .container {
            width: 100%;
            max-width: 1000px;
            margin: 0 auto;
            padding: 30px 20px 50px;
        }

        .topo {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            margin-bottom: 25px;
            flex-wrap: wrap;
        }

        .titulo h1 {
            margin: 0 0 6px;
            font-size: 28px;
        }

        .titulo p {
            margin: 0;
            color: #666;
        }

        .acoes {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-block;
            padding: 11px 16px;
            border-radius: 8px;
            text-decoration: none;
            border: none;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
        }

        .btn-cinza {
            background: #e5e7eb;
            color: #222;
        }

        .btn-cinza:hover {
            background: #d1d5db;
        }

        .btn-principal {
            background: #111827;
            color: white;
        }

        .btn-principal:hover {
            background: #000;
        }

        .card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.06);
        }

        .card h2 {
            margin: 0 0 5px;
            font-size: 20px;
        }

        .card-descricao {
            color: #666;
            margin: 0 0 22px;
            font-size: 14px;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
        }

        .campo {
            display: flex;
            flex-direction: column;
        }

        .campo-completo {
            grid-column: 1 / -1;
        }

        label {
            font-weight: bold;
            margin-bottom: 7px;
            font-size: 14px;
        }

        input,
        textarea {
            width: 100%;
            padding: 12px 13px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 15px;
            font-family: inherit;
            outline: none;
            background: white;
        }

        input:focus,
        textarea:focus {
            border-color: #111827;
            box-shadow: 0 0 0 2px rgba(17, 24, 39, .08);
        }

        textarea {
            min-height: 120px;
            resize: vertical;
        }

        .ajuda {
            margin-top: 6px;
            font-size: 12px;
            color: #777;
        }

        .alerta {
            border-radius: 9px;
            padding: 14px 16px;
            margin-bottom: 20px;
        }

        .alerta ul {
            margin: 0;
            padding-left: 20px;
        }

        .erro {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .sucesso {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        .senha-box {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 18px;
        }

        .senha-box p {
            margin: 0 0 18px;
            color: #666;
            font-size: 14px;
        }

        /*
        |--------------------------------------------------------------------------
        | CAMPO DE SENHA
        |--------------------------------------------------------------------------
        */

        .campo-senha {
            position: relative;
        }

        .campo-senha input {
            padding-right: 100px;
        }

        .btn-mostrar-senha {
            position: absolute;
            right: 8px;
            bottom: 8px;
            height: 36px;
            padding: 0 10px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            background: #f3f4f6;
            color: #374151;
            font-size: 12px;
            font-weight: bold;
            cursor: pointer;
            white-space: nowrap;
        }

        .btn-mostrar-senha:hover {
            background: #e5e7eb;
        }

        .btn-mostrar-senha:focus {
            outline: 2px solid #111827;
            outline-offset: 1px;
        }

        /*
        |--------------------------------------------------------------------------
        | BOTÕES DO FORMULÁRIO
        |--------------------------------------------------------------------------
        */

        .rodape-acoes {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 25px;
            flex-wrap: wrap;
        }

        .observacao {
            margin-top: 20px;
            font-size: 13px;
            color: #777;
        }

        /*
        |--------------------------------------------------------------------------
        | EXCLUSÃO DA CONTA
        |--------------------------------------------------------------------------
        */

        .card-exclusao {
            border: 1px solid #fecaca;
            background: #ffffff;
        }

        .card-exclusao h2 {
            color: #991b1b;
        }

        .exclusao-aviso {
            background: #fef2f2;
            border: 1px solid #fecaca;
            border-radius: 10px;
            padding: 16px;
            margin-bottom: 20px;
            color: #7f1d1d;
        }

        .exclusao-aviso strong {
            display: block;
            margin-bottom: 8px;
            font-size: 15px;
        }

        .exclusao-aviso p {
            margin: 6px 0;
            font-size: 14px;
            line-height: 1.5;
        }

        .btn-excluir {
            background: #dc2626;
            color: white;
        }

        .btn-excluir:hover {
            background: #b91c1c;
        }

        @media (max-width: 700px) {

            .container {
                padding: 20px 15px 40px;
            }

            .grid {
                grid-template-columns: 1fr;
            }

            .campo-completo {
                grid-column: auto;
            }

            .topo {
                align-items: flex-start;
            }

            .acoes {
                width: 100%;
            }

            .acoes .btn {
                width: 100%;
                text-align: center;
            }

            .card {
                padding: 20px;
            }

            .rodape-acoes {
                flex-direction: column-reverse;
            }

            .rodape-acoes .btn {
                width: 100%;
                text-align: center;
            }

            .btn-mostrar-senha {
                right: 7px;
            }

            .campo-senha input {
                padding-right: 95px;
            }

            .btn-excluir {
                width: 100%;
                text-align: center;
            }
        }

    </style>

</head>

<body>

<div class="container">

    <!-- =========================================================
         CABEÇALHO
    ========================================================== -->

    <div class="topo">

        <div class="titulo">

            <h1>Minha Conta</h1>

            <p>
                Gerencie seus dados pessoais,
                sua confeitaria e sua senha.
            </p>

        </div>

        <div class="acoes">

            <a
                href="index.php"
                class="btn btn-cinza"
            >
                ← Voltar ao painel
            </a>

        </div>

    </div>


    <!-- =========================================================
         MENSAGENS DE ERRO
    ========================================================== -->

    <?php if (!empty($erros)): ?>

        <div class="alerta erro">

            <strong>
                Verifique os seguintes pontos:
            </strong>

            <ul>

                <?php foreach ($erros as $erro): ?>

                    <li>

                        <?= htmlspecialchars(
                            $erro,
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>

                    </li>

                <?php endforeach; ?>

            </ul>

        </div>

    <?php endif; ?>


    <!-- =========================================================
         MENSAGEM DE SUCESSO
    ========================================================== -->

    <?php if ($sucesso !== ''): ?>

        <div class="alerta sucesso">

            <?= htmlspecialchars(
                $sucesso,
                ENT_QUOTES,
                'UTF-8'
            ) ?>

        </div>

    <?php endif; ?>


    <!-- =========================================================
         FORMULÁRIO PRINCIPAL
    ========================================================== -->

    <form
        method="POST"
        action=""
        autocomplete="off"
    >

        <input
            type="hidden"
            name="csrf_token"
            value="<?= htmlspecialchars(
                $csrf,
                ENT_QUOTES,
                'UTF-8'
            ) ?>"
        >


        <!-- =====================================================
             DADOS DA CONTA
        ====================================================== -->

        <div class="card">

            <h2>Dados da conta</h2>

            <p class="card-descricao">

                Essas são as informações utilizadas
                para identificar sua conta no sistema.

            </p>

            <div class="grid">

                <div class="campo">

                    <label for="nome">
                        Seu nome *
                    </label>

                    <input
                        type="text"
                        id="nome"
                        name="nome"
                        maxlength="150"
                        required
                        autocomplete="name"
                        value="<?= htmlspecialchars(
                            $nome,
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>"
                    >

                </div>


                <div class="campo">

                    <label for="email">
                        E-mail *
                    </label>

                    <input
                        type="email"
                        id="email"
                        name="email"
                        maxlength="150"
                        required
                        autocomplete="email"
                        value="<?= htmlspecialchars(
                            $email,
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>"
                    >

                </div>

            </div>

        </div>


        <!-- =====================================================
             DADOS DA CONFEITARIA
        ====================================================== -->

        <div class="card">

            <h2>Dados da confeitaria</h2>

            <p class="card-descricao">

                Essas informações podem aparecer no
                atendimento e nos pedidos dos seus clientes.

            </p>

            <div class="grid">

                <div class="campo campo-completo">

                    <label for="nome_confeitaria">
                        Nome da confeitaria *
                    </label>

                    <input
                        type="text"
                        id="nome_confeitaria"
                        name="nome_confeitaria"
                        maxlength="150"
                        required
                        value="<?= htmlspecialchars(
                            $nome_confeitaria,
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>"
                    >

                </div>


                <div class="campo">

                    <label for="telefone">
                        Telefone
                    </label>

                    <input
                        type="text"
                        id="telefone"
                        name="telefone"
                        maxlength="30"
                        autocomplete="tel"
                        value="<?= htmlspecialchars(
                            $telefone,
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>"
                    >

                </div>


                <div class="campo">

                    <label for="cidade">
                        Cidade
                    </label>

                    <input
                        type="text"
                        id="cidade"
                        name="cidade"
                        maxlength="100"
                        autocomplete="address-level2"
                        value="<?= htmlspecialchars(
                            $cidade,
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>"
                    >

                </div>


                <div class="campo campo-completo">

                    <label for="descricao">
                        Descrição da confeitaria
                    </label>

                    <textarea
                        id="descricao"
                        name="descricao"
                        maxlength="1000"
                        placeholder="Conte um pouco sobre sua confeitaria..."
                    ><?= htmlspecialchars(
                        $descricao,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?></textarea>

                    <div class="ajuda">

                        Máximo de 1000 caracteres.

                    </div>

                </div>

            </div>

        </div>


        <!-- =====================================================
             ALTERAR SENHA
        ====================================================== -->

        <div class="card">

            <h2>Alterar senha</h2>

            <p class="card-descricao">

                Caso não queira alterar sua senha,
                deixe os três campos em branco.

            </p>

            <div class="senha-box">

                <p>

                    Para alterar sua senha, informe sua senha atual
                    e depois escolha a nova senha.

                </p>

                <div class="grid">


                    <!-- SENHA ATUAL -->

                    <div class="campo campo-completo campo-senha">

                        <label for="senha_atual">
                            Senha atual
                        </label>

                        <input
                            type="password"
                            id="senha_atual"
                            name="senha_atual"
                            maxlength="255"
                            autocomplete="current-password"
                        >

                        <button
                            type="button"
                            class="btn-mostrar-senha"
                            data-target="senha_atual"
                            aria-label="Mostrar senha atual"
                        >
                            Mostrar
                        </button>

                    </div>


                    <!-- NOVA SENHA -->

                    <div class="campo campo-senha">

                        <label for="nova_senha">
                            Nova senha
                        </label>

                        <input
                            type="password"
                            id="nova_senha"
                            name="nova_senha"
                            minlength="6"
                            maxlength="255"
                            autocomplete="new-password"
                        >

                        <button
                            type="button"
                            class="btn-mostrar-senha"
                            data-target="nova_senha"
                            aria-label="Mostrar nova senha"
                        >
                            Mostrar
                        </button>

                    </div>


                    <!-- CONFIRMAR SENHA -->

                    <div class="campo campo-senha">

                        <label for="confirmar_senha">
                            Confirmar nova senha
                        </label>

                        <input
                            type="password"
                            id="confirmar_senha"
                            name="confirmar_senha"
                            minlength="6"
                            maxlength="255"
                            autocomplete="new-password"
                        >

                        <button
                            type="button"
                            class="btn-mostrar-senha"
                            data-target="confirmar_senha"
                            aria-label="Mostrar confirmação da nova senha"
                        >
                            Mostrar
                        </button>

                    </div>

                </div>

            </div>

        </div>


        <!-- =====================================================
             BOTÕES
        ====================================================== -->

        <div class="rodape-acoes">

            <a
                href="index.php"
                class="btn btn-cinza"
            >
                Cancelar
            </a>

            <button
                type="submit"
                class="btn btn-principal"
            >
                Salvar alterações
            </button>

        </div>


        <div class="observacao">

            * Campos obrigatórios.

        </div>

    </form>


    <!-- =========================================================
         EXCLUSÃO DA CONTA
    ========================================================== -->

    <div class="card card-exclusao">

        <h2>
            Excluir minha conta
        </h2>

        <p class="card-descricao">

            Se você não deseja mais utilizar o sistema,
            poderá solicitar a exclusão da sua conta.

        </p>


        <div class="exclusao-aviso">

            <strong>
                Atenção
            </strong>

            <p>

                A exclusão da conta é uma ação importante.
                Antes de continuar, você terá que confirmar
                que realmente deseja excluir sua conta e
                informar o motivo da exclusão.

            </p>

            <p>

                Seus dados relacionados a pedidos,
                produtos e movimentações financeiras poderão
                ser preservados pelo sistema para manter
                a integridade dos registros.

            </p>

        </div>


        <a
            href="excluir_conta.php"
            class="btn btn-excluir"
        >
            Excluir minha conta
        </a>

    </div>

</div>


<!-- =============================================================
     JAVASCRIPT - MOSTRAR / OCULTAR SENHA
============================================================== -->

<script>

document.addEventListener(
    'DOMContentLoaded',
    function () {

        const botoes =
            document.querySelectorAll(
                '.btn-mostrar-senha'
            );

        botoes.forEach(
            function (botao) {

                botao.addEventListener(
                    'click',
                    function () {

                        const idCampo =
                            botao.getAttribute(
                                'data-target'
                            );

                        const campo =
                            document.getElementById(
                                idCampo
                            );

                        if (!campo) {
                            return;
                        }

                        if (
                            campo.type ===
                            'password'
                        ) {

                            campo.type = 'text';

                            botao.textContent =
                                'Ocultar';

                            botao.setAttribute(
                                'aria-label',
                                'Ocultar senha'
                            );

                        } else {

                            campo.type =
                                'password';

                            botao.textContent =
                                'Mostrar';

                            botao.setAttribute(
                                'aria-label',
                                'Mostrar senha'
                            );
                        }

                    }
                );

            }
        );

    }
);

</script>

</body>

</html>