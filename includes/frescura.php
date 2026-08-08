<?php
/**
 * includes/frescura.php
 *
 * Cuán viejo es un dato traído de afuera, y cómo se dice.
 *
 * Vive acá y no dentro del cron porque la regla la usan los dos lados: el cron,
 * para avisar en su log que la fuente dejó de publicar; y el tablero, para no
 * mostrar un precio de hace una semana con la misma cara que uno de hoy. Si la
 * regla estuviera duplicada, tarde o temprano una diría "está bien" y la otra
 * "está viejo" sobre el mismo dato.
 *
 * El contexto: en agosto de 2026 la pizarra de la BCR se quedó dos días sin
 * publicar y el tablero siguió mostrando el precio viejo sin distinguirse en
 * nada de uno fresco. Nadie se enteró hasta abrir la app y mirar la fecha con
 * atención. Esto es para que eso salte a la vista.
 */

/**
 * Días hábiles entre dos fechas (sin contar sábados ni domingos).
 *
 * Se cuentan hábiles y no corridos porque las fuentes de precios sólo publican
 * en días de semana: el viernes a la tarde y el lunes a la mañana el último dato
 * disponible es el mismo, y eso es completamente normal. Contando días corridos,
 * todos los lunes darían falsa alarma.
 */
function dias_habiles(string $desdeSQL, string $hastaSQL): int
{
    $d = new DateTime($desdeSQL);
    $h = new DateTime($hastaSQL);
    if ($d >= $h) return 0;

    $n = 0;
    while ($d < $h) {
        $d->modify('+1 day');
        if ((int)$d->format('N') <= 5) $n++;
    }
    return $n;
}

/**
 * Evalúa la frescura de una fecha y devuelve todo lo que hace falta para
 * mostrarla: si está vieja, cuántos días hábiles pasaron y un texto en
 * castellano listo para pantalla.
 *
 * La tolerancia por defecto es de 2 días hábiles: el tablero del día se publica
 * a la tarde, así que a la mañana siempre se está mirando el del día anterior.
 *
 * @return array{viejo:bool, dias:int, texto:string}
 */
function evaluar_frescura(?string $fechaSQL, int $toleranciaHabiles = 2): array
{
    if ($fechaSQL === null || $fechaSQL === '' || strtotime($fechaSQL) === false) {
        return ['viejo' => true, 'dias' => 0, 'texto' => 'sin dato'];
    }

    $dias = dias_habiles(date('Y-m-d', strtotime($fechaSQL)), date('Y-m-d'));

    if ($dias <= $toleranciaHabiles) {
        return ['viejo' => false, 'dias' => $dias, 'texto' => date('d/m/Y', strtotime($fechaSQL))];
    }

    return [
        'viejo' => true,
        'dias'  => $dias,
        'texto' => 'desactualizado · hace ' . $dias . ' días hábiles',
    ];
}
