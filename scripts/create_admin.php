<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

use App\Database;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execute este script apenas no terminal.\n");
}

if ($argc < 3) {
    fwrite(
        STDERR,
        "Uso: php scripts/create_admin.php admin@exemplo.com 'Senha-forte123!' [Nome]\n",
    );
    exit(1);
}

$email = strtolower(trim((string) $argv[1]));
$password = (string) $argv[2];
$name = trim((string) ($argv[3] ?? 'Administrador'));

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
    fwrite(STDERR, "Informe um endereço de e-mail válido de até 190 caracteres.\n");
    exit(1);
}

if (strlen($password) < 12) {
    fwrite(STDERR, "A senha precisa ter pelo menos 12 caracteres.\n");
    exit(1);
}

if ($name === '') {
    $name = 'Administrador';
}

if (mb_strlen($name) > 160) {
    fwrite(STDERR, "O nome deve ter no máximo 160 caracteres.\n");
    exit(1);
}

try {
    $db = Database::connection();
} catch (Throwable $exception) {
    error_log('Database connection error while creating admin: ' . $exception->getMessage());
    fwrite(STDERR, "Não foi possível conectar ao banco. Confira o arquivo .env e o serviço MySQL.\n");
    exit(1);
}

try {
    $db->beginTransaction();

    $statement = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $statement->execute([$email]);

    if ($statement->fetch(PDO::FETCH_ASSOC)) {
        $db->rollBack();
        fwrite(STDERR, "Já existe um usuário com este e-mail.\n");
        exit(1);
    }

    $statement = $db->query('SELECT id FROM organizers ORDER BY id ASC LIMIT 1');
    $organizer = $statement->fetch(PDO::FETCH_ASSOC);

    if (!$organizer) {
        $baseSlug = 'organizacao-principal';
        $slug = $baseSlug;
        $suffix = 1;

        $slugQuery = $db->prepare('SELECT id FROM organizers WHERE slug = ? LIMIT 1');

        do {
            $slugQuery->execute([$slug]);
            $slugExists = (bool) $slugQuery->fetch(PDO::FETCH_ASSOC);

            if ($slugExists) {
                $slug = $baseSlug . '-' . $suffix;
                $suffix++;
            }
        } while ($slugExists);

        $statement = $db->prepare(
            'INSERT INTO organizers (name, slug, email) VALUES (?, ?, ?)',
        );
        $statement->execute(['Organização Principal', $slug, $email]);
        $organizerId = (int) $db->lastInsertId();
    } else {
        $organizerId = (int) $organizer['id'];
    }

    $statement = $db->prepare(
        "INSERT INTO users (organizer_id, name, email, password_hash, role, active)
         VALUES (?, ?, ?, ?, 'owner', 1)",
    );
    $statement->execute([
        $organizerId,
        $name,
        $email,
        password_hash($password, PASSWORD_DEFAULT),
    ]);

    $db->commit();
    fwrite(STDOUT, "Administrador criado com sucesso: {$email}\n");
} catch (Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log('Erro ao criar administrador: ' . $exception->getMessage());
    fwrite(STDERR, "Não foi possível criar o administrador. Confira a conexão e o schema do banco.\n");
    exit(1);
}
