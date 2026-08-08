<?php
/**
 * La regla que decide si un dato traido de afuera esta viejo.
 *
 * La usan el cron (para avisar en su log) y el tablero (para pintar el cartel
 * ambar). Si se afloja, volvemos al problema de agosto de 2026: precios
 * congelados dos dias sin que nadie se entere. Si se pasa de estricta, grita en
 * falso todos los lunes y se deja de mirar, que termina siendo lo mismo.
 */

require_once __DIR__ . '/../includes/frescura.php';

grupo('Dias habiles entre fechas');

es('mismo dia',                              0, dias_habiles('2026-08-06', '2026-08-06'));
es('jueves a viernes',                       1, dias_habiles('2026-08-06', '2026-08-07'));
es('jueves a sabado: el sabado no cuenta',   1, dias_habiles('2026-08-06', '2026-08-08'));
es('jueves a domingo: tampoco',              1, dias_habiles('2026-08-06', '2026-08-09'));
es('viernes a lunes: un solo habil',         1, dias_habiles('2026-08-07', '2026-08-10'));
es('jueves a lunes',                         2, dias_habiles('2026-08-06', '2026-08-10'));
es('jueves a martes',                        3, dias_habiles('2026-08-06', '2026-08-11'));
es('una semana entera',                      5, dias_habiles('2026-08-03', '2026-08-10'));
es('fecha futura no da negativo',            0, dias_habiles('2026-08-20', '2026-08-10'));

grupo('Evaluacion de frescura');

// La tolerancia por defecto es de 2 dias habiles: el tablero del dia se publica
// a la tarde, asi que a la manana siempre se mira el del dia anterior.
$hoy = date('Y-m-d');

$deHoy = evaluar_frescura($hoy);
esVerdad('el dato de hoy no esta viejo', !$deHoy['viejo']);
es('el dato de hoy muestra la fecha', date('d/m/Y'), $deHoy['texto']);

$hace10 = evaluar_frescura(date('Y-m-d', strtotime('-10 days')));
esVerdad('un dato de hace diez dias esta viejo', $hace10['viejo']);
esVerdad('y lo dice en el texto', strpos($hace10['texto'], 'desactualizado') === 0);

grupo('Frescura — sin dato');

foreach ([null, '', 'cualquier cosa'] as $malo) {
    $r = evaluar_frescura($malo);
    esVerdad('sin fecha valida se considera viejo (' . var_export($malo, true) . ')', $r['viejo']);
    es('y el texto lo aclara (' . var_export($malo, true) . ')', 'sin dato', $r['texto']);
}

grupo('Frescura — el limite de la tolerancia');

// Se fija el "hoy" a mano. Sin eso los casos dependerian del dia de la semana en
// que se corran las pruebas: la primera version de este archivo pasaba entre
// semana y fallaba los sabados, porque el viernes queda a cero dias habiles.
$jueves = '2026-08-06';

es('jueves visto el lunes: 2 habiles',  2, evaluar_frescura($jueves, 2, '2026-08-10')['dias']);
esVerdad('y con tolerancia 2 todavia no alarma', !evaluar_frescura($jueves, 2, '2026-08-10')['viejo']);

es('jueves visto el martes: 3 habiles',  3, evaluar_frescura($jueves, 2, '2026-08-11')['dias']);
esVerdad('con tolerancia 2 el martes ya alarma', evaluar_frescura($jueves, 2, '2026-08-11')['viejo']);

$viernesVistoLunes = evaluar_frescura('2026-08-07', 2, '2026-08-10');
esVerdad('el viernes mirado el lunes no alarma (fin de semana)', !$viernesVistoLunes['viejo']);
es('y son solo 1 dia habil', 1, $viernesVistoLunes['dias']);

esVerdad('con tolerancia 0, un dia habil ya es viejo',
    evaluar_frescura($jueves, 0, '2026-08-07')['viejo']);
esVerdad('con tolerancia alta, una semana no alarma',
    !evaluar_frescura($jueves, 9, '2026-08-13')['viejo']);

// El texto del cartel tiene que nombrar el atraso, que es lo que se lee en pantalla.
$cartel = evaluar_frescura($jueves, 2, '2026-08-13')['texto'];
esVerdad('el cartel dice desactualizado', strpos($cartel, 'desactualizado') === 0);
esVerdad('el cartel dice cuantos dias', strpos($cartel, '5 días hábiles') !== false);
