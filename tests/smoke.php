<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/helpers.php';

$tests = 0;

function assert_same(mixed $expected, mixed $actual, string $label): void
{
    global $tests;
    $tests++;

    if ($expected !== $actual) {
        throw new RuntimeException(
            $label . ' falhou. Esperado: ' . var_export($expected, true)
            . '; recebido: ' . var_export($actual, true),
        );
    }
}

assert_same('R$ 123,45', money(12345), 'Formatação de moeda');
assert_same('sao-joao-2026', slugify('São João 2026'), 'Geração de slug com acentos');
assert_same('&lt;script&gt;&quot;x&quot;&lt;/script&gt;', e('<script>"x"</script>'), 'Escape de HTML');
assert_same(null, nullable_datetime(''), 'Data opcional vazia');
assert_same('2026-10-14 18:30:00', nullable_datetime('2026-10-14T18:30'), 'Conversão de datetime-local');

$invalidDateRejected = false;

try {
    nullable_datetime('2026-02-31T18:30');
} catch (InvalidArgumentException) {
    $invalidDateRejected = true;
}

assert_same(true, $invalidDateRejected, 'Rejeição de data inexistente');

$_ENV['APP_URL'] = 'http://localhost/ingressos-abacatepay/public';
assert_same(
    'http://localhost/ingressos-abacatepay/public/assets/app.css',
    url('assets/app.css'),
    'Geração de URL absoluta configurada',
);

fwrite(STDOUT, "OK: {$tests} verificações locais passaram.\n");
