<?php
/* includes/lote_notas.php — La libreta del lote.
 *
 * Notas con fecha sobre un lote, aparte de los gastos: lo que el productor ve
 * en el campo y quiere tener anotado. Se guardan con la campaña que tenía el
 * lote en ese momento, para que al cambiar de campaña la historia no se mezcle.
 * Pantalla: lotes.php (botón de la libreta en cada lote).
 *
 * Anda sin la migración (migrations/lote_notas.sql): sin la tabla, el botón no
 * aparece y nada más cambia.
 */

function lote_notas_lista(PDO $pdo): bool
{
    static $lista = null;
    if ($lista === null) {
        try { $lista = (bool)$pdo->query("SHOW TABLES LIKE 'lote_notas'")->fetch(); }
        catch (Throwable $e) { $lista = false; }
    }
    return $lista;
}

/** Todas las notas de la cuenta, por lote, la más nueva primero. [lote_id => [nota, ...]] */
function lote_notas_por_lote(PDO $pdo, int $usuario_id): array
{
    if (!lote_notas_lista($pdo)) return [];
    $st = $pdo->prepare("SELECT id, lote_id, fecha, campania, texto FROM lote_notas
                          WHERE usuario_id = ? ORDER BY fecha DESC, id DESC");
    $st->execute([$usuario_id]);
    $por = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $n) $por[(int)$n['lote_id']][] = $n;
    return $por;
}

/** Fecha válida (Y-m-d, no futura); si no, hoy. */
function lote_nota_fecha(string $f): string
{
    $d = DateTime::createFromFormat('!Y-m-d', $f);
    if (!$d || $d->format('Y-m-d') !== $f || $f > date('Y-m-d')) return date('Y-m-d');
    return $f;
}

/** Guarda una nota. Devuelve el texto del error o '' si se guardó. */
function lote_nota_agregar(PDO $pdo, int $usuario_id, int $lote_id, string $fecha, string $texto): string
{
    $texto = trim($texto);
    if ($texto === '') return 'La nota está vacía.';
    $texto = mb_substr($texto, 0, 2000);
    // El lote tiene que ser de esta cuenta; de paso, su campaña actual.
    $st = $pdo->prepare("SELECT campania FROM lotes WHERE id = ? AND usuario_id = ?");
    $st->execute([$lote_id, $usuario_id]);
    $lote = $st->fetch(PDO::FETCH_ASSOC);
    if (!$lote) return 'Ese lote no existe.';
    $pdo->prepare("INSERT INTO lote_notas (usuario_id, lote_id, fecha, campania, texto) VALUES (?, ?, ?, ?, ?)")
        ->execute([$usuario_id, $lote_id, lote_nota_fecha($fecha), $lote['campania'] ?: null, $texto]);
    return '';
}

/** Borra una nota de esta cuenta. Devuelve el lote al que pertenecía (0 si no era suya). */
function lote_nota_borrar(PDO $pdo, int $usuario_id, int $id): int
{
    $st = $pdo->prepare("SELECT lote_id FROM lote_notas WHERE id = ? AND usuario_id = ?");
    $st->execute([$id, $usuario_id]);
    $lote_id = (int)$st->fetchColumn();
    if ($lote_id) $pdo->prepare("DELETE FROM lote_notas WHERE id = ? AND usuario_id = ?")->execute([$id, $usuario_id]);
    return $lote_id;
}
