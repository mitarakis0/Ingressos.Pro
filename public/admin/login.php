<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/bootstrap.php';

use App\Database;

if (current_user()) {
    redirect(url('admin/dashboard.php'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');

    try {
        $statement = Database::connection()->prepare(
            'SELECT id, organizer_id, name, email, password_hash, role, active
             FROM users WHERE email = ? LIMIT 1',
        );
        $statement->execute([$email]);
        $user = $statement->fetch();

        if ($user && (int) $user['active'] === 1 && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            unset($user['password_hash'], $user['active']);
            $_SESSION['user'] = $user;

            $statement = Database::connection()->prepare(
                'UPDATE users SET last_login_at = NOW() WHERE id = ?',
            );
            $statement->execute([$user['id']]);
            audit('auth.login');
            redirect(url('admin/dashboard.php'));
        }

        usleep(250000);
        flash('error', 'E-mail ou senha inválidos.');
    } catch (Throwable $exception) {
        error_log('Login error: ' . $exception->getMessage());
        flash('error', 'Não foi possível entrar. Verifique a configuração do banco.');
    }

    redirect(url('admin/login.php'));
}

page_start('Entrar');
?>

<div class="auth-wrap">
    <form class="auth-card" method="post" autocomplete="on">
        <span class="eyebrow">Área administrativa</span>
        <h1>Entrar na plataforma</h1>
        <p class="muted">Acesse seus eventos, vendas e participantes.</p>

        <?= csrf_field() ?>

        <div class="field">
            <label for="email">E-mail</label>
            <input id="email" type="email" name="email" autocomplete="username" required maxlength="190">
        </div>

        <div class="field">
            <label for="password">Senha</label>
            <input id="password" type="password" name="password" autocomplete="current-password" required>
        </div>

        <button class="btn" type="submit" style="width: 100%">Entrar</button>
        <p class="small">
            Primeiro acesso? Execute
            <code>php scripts/create_admin.php admin@exemplo.com 'Senha-forte123!' 'Administrador'</code>
            no terminal, dentro da pasta do projeto.
        </p>
    </form>
</div>

<?php page_end(); ?>
