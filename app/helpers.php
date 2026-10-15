<?php
declare(strict_types=1);

use App\Database;

/** Escapa conteúdo para inserção segura em HTML. */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Formata um valor armazenado em centavos. */
function money(int $cents): string
{
    return 'R$ ' . number_format($cents / 100, 2, ',', '.');
}

/** Converte um campo datetime-local para o formato MySQL, permitindo campo vazio. */
function nullable_datetime(mixed $value): ?string
{
    $value = trim((string) ($value ?? ''));

    if ($value === '') {
        return null;
    }

    $value = str_replace('T', ' ', $value);

    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value) === 1) {
        $value .= ':00';
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
    $errors = DateTimeImmutable::getLastErrors();

    if (
        $date === false
        || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
    ) {
        throw new InvalidArgumentException('Informe uma data e hora válidas.');
    }

    return $date->format('Y-m-d H:i:s');
}

/** Monta URLs a partir de APP_URL ou detecta a pasta /public automaticamente. */
function url(string $path = ''): string
{
    $configuredBase = trim((string) ($_ENV['APP_URL'] ?? ''));

    if ($configuredBase !== '') {
        $base = rtrim($configuredBase, '/');
    } else {
        $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
        $publicPosition = strpos($scriptName, '/public/');

        if ($publicPosition !== false) {
            $base = substr($scriptName, 0, $publicPosition + strlen('/public'));
        } else {
            $base = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
        }

        if ($base === '.' || $base === '/') {
            $base = '';
        }
    }

    return $base . ($path !== '' ? '/' . ltrim($path, '/') : '');
}

/** Redireciona e encerra a execução atual. */
function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $expected = (string) ($_SESSION['_csrf'] ?? '');
    $received = (string) ($_POST['_csrf'] ?? '');

    if ($expected === '' || $received === '' || !hash_equals($expected, $received)) {
        http_response_code(419);
        exit('Sessão expirada. Atualize a página e tente novamente.');
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function get_flash(): ?array
{
    $message = $_SESSION['_flash'] ?? null;
    unset($_SESSION['_flash']);

    return is_array($message) ? $message : null;
}

function current_user(): ?array
{
    $user = $_SESSION['user'] ?? null;

    return is_array($user) ? $user : null;
}

function require_login(): array
{
    $user = current_user();

    if ($user === null) {
        redirect(url('admin/login.php'));
    }

    return $user;
}

function require_role(array $roles): array
{
    $user = require_login();

    if (!in_array($user['role'] ?? '', $roles, true)) {
        http_response_code(403);
        exit('Acesso não autorizado.');
    }

    return $user;
}

/** Registra ações administrativas sem interromper a operação principal em caso de falha de log. */
function audit(
    string $action,
    ?string $entityType = null,
    ?int $entityId = null,
    array $details = [],
): void {
    try {
        $user = current_user();
        $statement = Database::connection()->prepare(
            'INSERT INTO audit_logs (
                user_id, organizer_id, action, entity_type, entity_id, details, ip_address
             ) VALUES (?, ?, ?, ?, ?, ?, ?)',
        );
        $statement->execute([
            $user['id'] ?? null,
            $user['organizer_id'] ?? null,
            $action,
            $entityType,
            $entityId,
            json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $exception) {
        error_log('Audit log error: ' . $exception->getMessage());
    }
}

function slugify(string $text): string
{
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    $text = strtolower((string) ($ascii !== false ? $ascii : $text));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $text) ?? '';
    $slug = trim($slug, '-');

    return $slug !== '' ? $slug : bin2hex(random_bytes(4));
}

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function organizer_id(): int
{
    $user = current_user();
    $organizerId = (int) ($user['organizer_id'] ?? 0);

    if ($organizerId < 1) {
        throw new RuntimeException('Este usuário não está vinculado a uma organização.');
    }

    return $organizerId;
}

function page_start(string $title, bool $admin = false): void
{
    $flashMessage = get_flash();
    $homeUrl = e(url('index.php'));
    $loginUrl = e(url('admin/login.php'));
    $stylesheetUrl = e(url('assets/app.css'));

    echo '<!doctype html>';
    echo '<html lang="pt-BR">';
    echo '<head>';
    echo '<meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<meta name="theme-color" content="#0b0d12">';
    echo '<title>' . e($title) . ' · Ingressos Pro</title>';
    echo '<link rel="stylesheet" href="' . $stylesheetUrl . '">';
    echo '</head>';
    echo '<body>';
    echo '<header class="topbar">';
    echo '<a class="brand" href="' . $homeUrl . '">';
    echo '<span class="brand-mark">i</span> ingressos<span class="brand-light">pro</span></a>';
    echo '<nav aria-label="Navegação principal">';

    if ($admin && current_user() !== null) {
        $links = [
            'admin/dashboard.php' => 'Painel',
            'admin/events.php' => 'Eventos',
            'admin/orders.php' => 'Pedidos',
            'admin/participants.php' => 'Participantes',
            'admin/checkin.php' => 'Check-in',
        ];

        foreach ($links as $path => $label) {
            echo '<a href="' . e(url($path)) . '">' . e($label) . '</a>';
        }

        echo '<form class="nav-logout" method="post" action="' . e(url('admin/logout.php')) . '">';
        echo csrf_field();
        echo '<button type="submit">Sair</button>';
        echo '</form>';
    } else {
        echo '<a href="' . $homeUrl . '">Eventos</a>';
        echo '<a class="nav-cta" href="' . $loginUrl . '">Área do organizador</a>';
    }

    echo '</nav>';
    echo '</header>';
    echo '<main class="container">';

    if ($flashMessage !== null) {
        $type = (string) ($flashMessage['type'] ?? 'info');
        $message = (string) ($flashMessage['message'] ?? '');
        echo '<div class="alert ' . e($type) . '" role="status">' . e($message) . '</div>';
    }
}

function page_end(): void
{
    echo '</main>';
    echo '<footer class="footer">Ingressos Pro · Plataforma de venda e validação de ingressos</footer>';
    echo '<script src="' . e(url('assets/app.js')) . '"></script>';
    echo '</body>';
    echo '</html>';
}
